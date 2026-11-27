<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// Each tool call is decided by its own approval. A decision reaches a call
// through the call id its request names, and a provider can give two calls in
// one step the same id. A step whose call ids repeat is therefore denied: every
// decision for it is recorded as a denial, so no gated call in it runs, and
// approvalStatus reports what happened. A step with distinct ids is decided
// call by call, unchanged.

const G392_HARNESS_REASON = 'Tool call was not run because another call in this step has the same id';

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
});

/**
 * One Responses step with two weather calls, then a text reply for every later
 * request.
 */
function g392HarnessFake(string $firstId, string $secondId): void
{
    $call = fn (string $id, string $city): array => [
        'id' => $id, 'type' => 'function_call', 'status' => 'completed',
        'arguments' => json_encode(['city' => $city]), 'call_id' => $id, 'name' => 'weather',
    ];
    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];

    Http::fakeSequence()
        ->push(['id' => 'resp_g392', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [$call($firstId, 'Paris'), $call($secondId, 'Rome')], 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_g392_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_g392', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
}

function g392HarnessSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/**
 * @param  list<ToolApprovalRequest>  $pending
 * @param  list<bool>  $decisions
 * @return list<ToolApprovalResponse>
 */
function g392HarnessDecisions(array $pending, array $decisions): array
{
    return array_map(
        fn (ToolApprovalRequest $request, bool $approved): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, $approved),
        $pending,
        $decisions,
    );
}

/** @return array<string, callable(Session, list<ToolApprovalResponse>): mixed> */
function g392HarnessPaths(): array
{
    return [
        'decide' => fn (Session $session, array $decisions): mixed => $session->decide($decisions),
        'streamDecision' => fn (Session $session, array $decisions): mixed => iterator_to_array($session->streamDecision($decisions), false),
    ];
}

dataset('g392 harness decisions', [
    'deny the first, approve the second' => [[false, true]],
    'approve the first, deny the second' => [[true, false]],
    'approve both' => [[true, true]],
]);

it('runs no call of a step whose two calls share one id, and reports both denied', function (string $path, array $decisions): void {
    g392HarnessFake('weather', 'weather');

    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    expect($pending)->toHaveCount(2)
        ->and($pending[0]->toolCallId)->toBe($pending[1]->toolCallId);

    g392HarnessPaths()[$path](g392HarnessSession(), g392HarnessDecisions($pending, $decisions));

    $session = g392HarnessSession();

    expect($this->ran)->toBe([])
        ->and($session->approvalStatus($pending[0]->approvalId))->toBe('denied')
        ->and($session->approvalStatus($pending[1]->approvalId))->toBe('denied')
        ->and($session->thread()->pendingApprovalIds())->toBe([]);
})->with(['decide', 'streamDecision'])->with('g392 harness decisions');

it('runs no call of a repeated-id step when its approvals are answered one at a time', function (bool $first): void {
    g392HarnessFake('weather', 'weather');

    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    g392HarnessSession()->approve($pending[$first ? 0 : 1]);

    $session = g392HarnessSession();
    $other = $pending[$first ? 1 : 0]->approvalId;

    expect($this->ran)->toBe([])
        ->and($session->approvalStatus($pending[$first ? 0 : 1]->approvalId))->toBe('denied')
        ->and($session->approvalStatus($other))->not->toBe('approved')
        ->and($session->approvalStatus($other))->not->toBe('pending');
})->with(['the first' => [true], 'the second' => [false]]);

it('records the denial reason for a repeated-id step', function (): void {
    g392HarnessFake('weather', 'weather');

    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    g392HarnessSession()->decide(g392HarnessDecisions($pending, [true, true]));

    $reasons = [];
    foreach (g392HarnessSession()->thread()->storedMessages()->get() as $stored) {
        foreach ($stored->payload['tool_approval_responses'] ?? [] as $response) {
            $reasons[$response['approval_id'] ?? '?'][] = [$response['approved'] ?? null, $response['reason'] ?? null];
        }
    }

    // Every stored row that carries a decision for either approval holds the
    // denial, never the approval that was sent.
    expect(array_keys($reasons))->toEqualCanonicalizing([$pending[0]->approvalId, $pending[1]->approvalId]);
    foreach ($reasons as $rows) {
        expect(array_unique($rows, SORT_REGULAR))->toBe([[false, G392_HARNESS_REASON]]);
    }
});

it('still decides each call by its own approval when the ids are distinct', function (string $path, array $decisions): void {
    g392HarnessFake('call_paris', 'call_rome');

    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    g392HarnessPaths()[$path](g392HarnessSession(), g392HarnessDecisions($pending, $decisions));

    $session = g392HarnessSession();
    $expected = array_values(array_filter(['Paris', 'Rome'], fn (string $city, int $i): bool => $decisions[$i], ARRAY_FILTER_USE_BOTH));

    expect($this->ran)->toBe($expected)
        ->and($session->approvalStatus($pending[0]->approvalId))->toBe($decisions[0] ? 'approved' : 'denied')
        ->and($session->approvalStatus($pending[1]->approvalId))->toBe($decisions[1] ? 'approved' : 'denied');
})->with(['decide', 'streamDecision'])->with('g392 harness decisions');
