<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// G-401: a denial recorded for an approval is final. Its call never runs,
// whatever decision is recorded before or after it, on the write path and
// when the thread is read back: an approval followed by a denial runs
// nothing (the denial revokes it), and a denial followed by an approval runs
// nothing. The same decision sent again records nothing.

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $ran = &$this->ran;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran[] = $city;

                return "Sunny in {$city}";
            }),
    ]);

    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    Http::fakeSequence()
        ->push(['id' => 'resp_d', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'c1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'c1', 'name' => 'weather']],
            'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_d_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_d', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function denialWinsSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<bool> every decision stored for `$approvalId`, in order */
function denialWinsStored(string $approvalId): array
{
    $stored = [];
    foreach (denialWinsSession()->thread()->storedMessages()->reorder('position')->get() as $row) {
        foreach ($row->payload['tool_approval_responses'] ?? [] as $response) {
            if (($response['approval_id'] ?? null) === $approvalId) {
                $stored[] = (bool) ($response['approved'] ?? false);
            }
        }
    }

    return $stored;
}

/** @return array<string, Closure(Session, ToolApprovalResponse): mixed> */
function denialWinsPaths(): array
{
    return [
        'decide' => fn (Session $session, ToolApprovalResponse $decision): mixed => $session->decide([$decision]),
        'approve or deny' => fn (Session $session, ToolApprovalResponse $decision): mixed => $session->approve($decision->approvalId, $decision->approved),
        'streamDecision' => fn (Session $session, ToolApprovalResponse $decision): mixed => iterator_to_array($session->streamDecision([$decision]), false),
    ];
}

it('runs nothing when a denial and an approval are recorded across a failed turn, in either order', function (string $path, bool $first): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 1);
    $approvalId = denialWinsSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    try {
        denialWinsPaths()[$path](denialWinsSession(), new ToolApprovalResponse($approvalId, $first));
    } catch (RunNotPermitted) {
        // The turn failed before it acted on the decision.
    }
    config()->set('prism-harness.agent.modes.chat.max_steps', 8);
    app()->forgetInstance(ModeRegistry::class);
    app()->forgetInstance(AgentRuntime::class);
    app()->forgetInstance(PrismHarness::class);

    denialWinsPaths()[$path](denialWinsSession(), new ToolApprovalResponse($approvalId, ! $first));
    denialWinsSession()->send('');

    expect($this->ran)->toBe([])
        ->and(denialWinsStored($approvalId))->toContain(false);
})->with(array_keys(denialWinsPaths()))->with(['approve, then deny' => [true], 'deny, then approve' => [false]]);

it('runs nothing for a batch holding a denial and an approval for one approval, in either order', function (string $path, bool $first): void {
    $approvalId = denialWinsSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    $batch = [new ToolApprovalResponse($approvalId, $first), new ToolApprovalResponse($approvalId, ! $first)];
    $path === 'decide' ? denialWinsSession()->decide($batch) : iterator_to_array(denialWinsSession()->streamDecision($batch), false);
    denialWinsSession()->send('');

    expect($this->ran)->toBe([])
        ->and(array_values(array_unique(denialWinsStored($approvalId))))->toBe([false]);
})->with(['decide', 'streamDecision'])->with(['approve first' => [true], 'deny first' => [false]]);

it('runs nothing for a denial and an approval already in the thread for one approval, in either order', function (bool $first, string $layout): void {
    $approvalId = denialWinsSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    // Two writers, each recording a decision row for the same approval: in
    // separate rows, in one row, or in rows a system row separates.
    $one = new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($approvalId, $first)]);
    $two = new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($approvalId, ! $first)]);
    $rows = match ($layout) {
        'one row' => [new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($approvalId, $first), new ToolApprovalResponse($approvalId, ! $first)])],
        'separated' => [$one, new SystemMessage('A note.'), $two],
        default => [$one, $two],
    };
    foreach ($rows as $row) {
        denialWinsSession()->thread()->record([$row]);
    }
    denialWinsSession()->send('');
    denialWinsSession()->approve($approvalId);

    expect($this->ran)->toBe([]);
})->with(['approve first' => [true], 'deny first' => [false]])->with(['rows', 'one row', 'separated']);

it('records nothing for the same decision sent again', function (bool $approved): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 1);
    $approvalId = denialWinsSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    foreach ([1, 2] as $attempt) {
        try {
            denialWinsSession()->decide([new ToolApprovalResponse($approvalId, $approved)]);
        } catch (RunNotPermitted) {
            // The turn failed before it acted on the decision.
        }
    }

    expect(denialWinsStored($approvalId))->toBe([$approved])
        ->and($this->ran)->toBe([]);
})->with(['an approval' => [true], 'a denial' => [false]]);
