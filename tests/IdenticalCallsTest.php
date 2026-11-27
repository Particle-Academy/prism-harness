<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// Two IDENTICAL gated calls in one step (same id, tool and arguments) are two
// calls: the step's ids repeat, so neither runs, and each ends with exactly
// one outcome, counted: two stored results in all, two in the replay, and no
// resume adds another, whether or not the run has a step left.

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
    $call = ['id' => 'dup', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'dup', 'name' => 'weather'];
    Http::fakeSequence()
        ->push(['id' => 'resp_i', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [$call, $call], 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_i_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_i', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function identicalSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return array{stored: int, replayed: int} how many results name the Paris call */
function identicalCounts(): array
{
    $stored = 0;
    foreach (identicalSession()->thread()->storedMessages()->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $result) {
            $stored += ($result['tool_call_id'] ?? null) === 'dup' ? 1 : 0;
        }
    }
    $replayed = 0;
    foreach (identicalSession()->thread()->messages() as $message) {
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $replayed += $result->toolCallId === 'dup' ? 1 : 0;
            }
        }
    }

    return ['stored' => $stored, 'replayed' => $replayed];
}

it('gives each of two identical gated calls exactly one outcome, and no resume adds one', function (): void {
    $pending = identicalSession()->send('Weather in Paris, twice?')->pendingApprovals();
    expect($pending)->toHaveCount(2);

    identicalSession()->decide(array_map(fn ($request): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, true), $pending));
    expect(identicalCounts())->toBe(['stored' => 2, 'replayed' => 2]);

    for ($i = 0; $i < 3; $i++) {
        identicalSession()->send('');
    }

    expect($this->ran)->toBe([])
        ->and(identicalCounts())->toBe(['stored' => 2, 'replayed' => 2]);
});

it('adds no result on any resume of a run with no step left', function (): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 1);
    $pending = identicalSession()->send('Weather in Paris, twice?')->pendingApprovals();

    $counts = [];
    try {
        identicalSession()->decide(array_map(fn ($request): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, true), $pending));
    } catch (RunNotPermitted) {
        // No step left: the resume is refused.
    }
    for ($i = 0; $i < 4; $i++) {
        try {
            identicalSession()->send('');
        } catch (RunNotPermitted) {
        }
        $counts[] = identicalCounts()['stored'];
    }

    expect($this->ran)->toBe([])
        ->and(array_values(array_unique($counts)))->toHaveCount(1)
        ->and($counts[0])->toBeLessThanOrEqual(2);
});
