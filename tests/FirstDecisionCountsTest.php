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
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// G-401: the FIRST decision recorded for an approval is the one that counts.
// A second decision for an approval that already has one records nothing, so
// a turn that failed before acting on a decision cannot be followed by a
// different decision that then runs, and nothing a person denied runs.

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
        ->push(['id' => 'resp_f', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'c1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'c1', 'name' => 'weather']],
            'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_f_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_f', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function firstDecisionSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<bool> every decision stored for `$approvalId`, in order */
function firstDecisionStored(string $approvalId): array
{
    $stored = [];
    foreach (firstDecisionSession()->thread()->storedMessages()->reorder('position')->get() as $row) {
        foreach ($row->payload['tool_approval_responses'] ?? [] as $response) {
            if (($response['approval_id'] ?? null) === $approvalId) {
                $stored[] = (bool) ($response['approved'] ?? false);
            }
        }
    }

    return $stored;
}

/** @return array<string, Closure(Session, ToolApprovalResponse): mixed> */
function firstDecisionPaths(): array
{
    return [
        'decide' => fn (Session $session, ToolApprovalResponse $decision): mixed => $session->decide([$decision]),
        'approve or deny' => fn (Session $session, ToolApprovalResponse $decision): mixed => $session->approve($decision->approvalId, $decision->approved),
        'streamDecision' => fn (Session $session, ToolApprovalResponse $decision): mixed => iterator_to_array($session->streamDecision([$decision]), false),
    ];
}

/**
 * Park, then a first decision whose turn fails before acting on it (the run
 * has no step left), then room for a step, then a second decision.
 */
function firstDecisionAcrossFailedTurn(string $path, bool $first, bool $second): string
{
    config()->set('prism-harness.agent.modes.chat.max_steps', 1);
    $approvalId = firstDecisionSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    try {
        firstDecisionPaths()[$path](firstDecisionSession(), new ToolApprovalResponse($approvalId, $first));
    } catch (RunNotPermitted) {
        // The turn failed before it acted on the decision.
    }
    config()->set('prism-harness.agent.modes.chat.max_steps', 8);
    app()->forgetInstance(ModeRegistry::class);
    app()->forgetInstance(AgentRuntime::class);
    app()->forgetInstance(PrismHarness::class);

    firstDecisionPaths()[$path](firstDecisionSession(), new ToolApprovalResponse($approvalId, $second));
    firstDecisionSession()->send('');

    return $approvalId;
}

it('keeps a denial when an approval follows it across a failed turn', function (string $path): void {
    $approvalId = firstDecisionAcrossFailedTurn($path, false, true);

    expect($this->ran)->toBe([])
        ->and(firstDecisionStored($approvalId))->not->toContain(true);
})->with(array_keys(firstDecisionPaths()));

it('keeps an approval when a denial follows it across a failed turn', function (string $path): void {
    $approvalId = firstDecisionAcrossFailedTurn($path, true, false);

    expect($this->ran)->toBe(['Paris'])
        ->and(firstDecisionStored($approvalId))->not->toContain(false);
})->with(array_keys(firstDecisionPaths()));

it('counts the first of two decisions for one approval in one batch', function (string $path, bool $first): void {
    $approvalId = firstDecisionSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    $batch = [new ToolApprovalResponse($approvalId, $first), new ToolApprovalResponse($approvalId, ! $first)];
    $path === 'decide' ? firstDecisionSession()->decide($batch) : iterator_to_array(firstDecisionSession()->streamDecision($batch), false);
    firstDecisionSession()->send('');

    expect($this->ran)->toBe($first ? ['Paris'] : [])
        ->and(array_values(array_unique(firstDecisionStored($approvalId))))->toBe([$first]);
})->with(['decide', 'streamDecision'])->with(['deny first' => [false], 'approve first' => [true]]);

it('keeps a denial a host recorded when an approval follows it', function (): void {
    $approvalId = firstDecisionSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    // The decision row only: no run has acted on it.
    firstDecisionSession()->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($approvalId, false)])]);
    firstDecisionSession()->approve($approvalId);

    expect($this->ran)->toBe([])
        ->and(firstDecisionStored($approvalId))->not->toContain(true);
});

it('reads the first of two decision rows already in the thread for one approval', function (bool $first, string $resume): void {
    $approvalId = firstDecisionSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    // Two writers, each recording a decision row for the same approval: in
    // separate rows, or both in one row.
    $rows = $resume === 'one row'
        ? [new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($approvalId, $first), new ToolApprovalResponse($approvalId, ! $first)])]
        : [new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($approvalId, $first)]),
            new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($approvalId, ! $first)])];
    foreach ($rows as $row) {
        firstDecisionSession()->thread()->record([$row]);
    }
    $resume === 'stream' ? iterator_to_array(firstDecisionSession()->stream(''), false) : firstDecisionSession()->send('');
    firstDecisionSession()->send('');

    expect($this->ran)->toBe($first ? ['Paris'] : []);
})->with(['deny first' => [false], 'approve first' => [true]])->with(['send', 'stream', 'one row']);
