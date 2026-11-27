<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// G-354. A decision is an answer to a question the run asked. An approval id
// the run never issued, or one already answered, is not an answer to anything:
// while a call was pending it closed that call as "No approval response
// provided", and while nothing was pending it started a model turn with no new
// user message, in which an ungated tool could run. Every decision path is
// driven here through the public session API, over Prism's real OpenAI handler
// on faked HTTP with stray requests refused.

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);

    $this->ran = ['search' => 0, 'weather' => 0];
    $ran = &$this->ran;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$ran): string {
                $ran['search']++;

                return 'The tigers game is today at 3pm in detroit';
            }),
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran['weather']++;

                return 'The weather will be 75 and sunny';
            }),
    ]);

    $fixture = fn (string $name): string => (string) file_get_contents(__DIR__.'/Fixtures/openai/'.$name.'.json');
    Http::fakeSequence()
        ->push($fixture('tool-calls'))
        ->push($fixture('after-tools'))
        ->push($fixture('text-reply'))
        ->push($fixture('text-reply'));
});

function g354Session(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<array{type: string, payload: mixed}> */
function g354Rows(): array
{
    $thread = Thread::query()->first();

    return $thread === null ? [] : $thread->storedMessages()->orderBy('position')->get(['type', 'payload'])
        ->map(fn ($row): array => ['type' => $row->type, 'payload' => $row->payload])->all();
}

/** @return array<string, string> */
function g354OutputsSent(int $request): array
{
    return collect(json_decode((string) Http::recorded()[$request][0]->body(), true)['input'])
        ->where('type', 'function_call_output')
        ->pluck('output', 'call_id')
        ->all();
}

/** @return list<callable(Session, string): mixed> */
function g354DecisionPaths(): array
{
    return [
        'approve' => fn (Session $session, string $id): mixed => $session->approve($id),
        'deny' => fn (Session $session, string $id): mixed => $session->deny($id, 'no'),
        'decide' => fn (Session $session, string $id): mixed => $session->decide([new ToolApprovalResponse($id, true)]),
        'streamDecision' => fn (Session $session, string $id): mixed => iterator_to_array($session->streamDecision([new ToolApprovalResponse($id, true)]), false),
    ];
}

it('refuses a made-up id while a call is pending, and the real one still works afterwards', function (string $path): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $pending = g354Session()->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    $rows = g354Rows();

    expect(fn () => g354DecisionPaths()[$path](g354Session(), 'approval-never-issued'))
        ->toThrow(ApprovalNotPending::class, 'approval-never-issued');

    // Nothing sent, nothing written, nothing run.
    expect(Http::recorded())->toHaveCount(1)
        ->and(g354Rows())->toBe($rows)
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 0]);

    // The person's real decision is still the one that counts.
    $resumed = g354Session()->approve($pending);

    expect($this->ran)->toBe(['search' => 1, 'weather' => 1])
        ->and($resumed->awaitingApproval())->toBeFalse()
        ->and(g354OutputsSent(1)['call_huHZrdh8RFUy8a8Uqbb2qV1x'])->toBe('The weather will be 75 and sunny')
        ->and(array_values(g354OutputsSent(1)))->not->toContain('No approval response provided');
})->with(['approve', 'deny', 'decide', 'streamDecision']);

it('refuses a made-up id alongside the real one, and records neither', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $pending = g354Session()->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    $rows = g354Rows();

    expect(fn () => g354Session()->decide([
        new ToolApprovalResponse($pending->approvalId, true),
        new ToolApprovalResponse('approval-never-issued', true),
    ]))->toThrow(ApprovalNotPending::class);

    expect(Http::recorded())->toHaveCount(1)
        ->and(g354Rows())->toBe($rows)
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 0]);
});

it('refuses a made-up id while nothing is pending: no request, nothing written', function (string $path): void {
    // A finished turn with no gate: the model called both tools and replied.
    g354Session()->send('What time is the game, and do I need a coat?');
    $rows = g354Rows();
    $sent = count(Http::recorded());

    expect($sent)->toBe(2)
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 1]);

    expect(fn () => g354DecisionPaths()[$path](g354Session(), 'approval-never-issued'))
        ->toThrow(ApprovalNotPending::class);

    expect(Http::recorded())->toHaveCount($sent)
        ->and(g354Rows())->toBe($rows)
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 1]);
})->with(['approve', 'deny', 'decide', 'streamDecision']);

it('refuses a decision on a session that has never had a turn', function (string $path): void {
    expect(fn () => g354DecisionPaths()[$path](g354Session(), 'approval-never-issued'))
        ->toThrow(ApprovalNotPending::class);

    expect(Http::recorded())->toHaveCount(0)
        ->and(g354Rows())->toBe([])
        ->and($this->ran)->toBe(['search' => 0, 'weather' => 0]);
})->with(['approve', 'deny', 'decide', 'streamDecision']);

it('keeps the real id working exactly as before', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $pending = g354Session()->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];

    $resumed = g354Session()->approve($pending);

    expect($this->ran)->toBe(['search' => 1, 'weather' => 1])
        ->and($resumed->awaitingApproval())->toBeFalse()
        ->and(Http::recorded())->toHaveCount(2)
        // Round 15: plus the approved call's write-ahead started row.
        ->and(array_column(g354Rows(), 'type'))->toBe(['user', 'assistant', 'tool_result', 'tool_result', 'tool_result', 'tool_result', 'assistant']);
});

it('refuses a repeat of an id that was already answered: no request, nothing written', function (string $path): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $pending = g354Session()->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    g354Session()->approve($pending);
    $rows = g354Rows();

    expect(Http::recorded())->toHaveCount(2);

    expect(fn () => g354DecisionPaths()[$path](g354Session(), $pending->approvalId))
        ->toThrow(ApprovalNotPending::class);

    expect(Http::recorded())->toHaveCount(2)
        ->and(g354Rows())->toBe($rows)
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 1]);
})->with(['approve', 'deny', 'decide', 'streamDecision']);

it('refuses the same id twice in one batch', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $pending = g354Session()->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    $rows = g354Rows();

    expect(fn () => g354Session()->decide([
        new ToolApprovalResponse($pending->approvalId, false, 'no'),
        new ToolApprovalResponse($pending->approvalId, true),
    ]))->toThrow(ApprovalNotPending::class);

    expect(Http::recorded())->toHaveCount(1)
        ->and(g354Rows())->toBe($rows)
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 0]);
});
