<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunResumed;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Events\RunStepped;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRefusals;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextCompleteEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\TextStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

// G-282 A and A+ (G-284): the tagged run step stream. The field names are an
// agreed contract with Fancy (contractVersion g282.1); these tests pin the
// shape, the sequence, the timing, refused-versus-failed, and that the
// streamed approval continuation records every decision before any tool runs.

function isoMicros(): string
{
    return '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/';
}

function stepSession(string $scope = 'chat'): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Budget']))->session($scope);
}

/** @return list<RunStep> */
function collectSteps(iterable $steps): array
{
    $out = [];
    foreach ($steps as $step) {
        $out[] = $step;
    }

    return $out;
}

/** @param list<RunStep> $steps @return list<RunStep> */
function stepsOfType(array $steps, string $type): array
{
    return array_values(array_filter($steps, fn (RunStep $step): bool => $step->type === $type));
}

/**
 * A real Anthropic handler over faked SSE: core runs the tools itself, so
 * timing, failures and refusals are the real ones rather than a fake's.
 *
 * @param  list<string>  $toolNames  what the model calls, one tool step each
 */
function anthropicSteps(array $toolNames, string $scope = 'tools', int $finals = 1): Session
{
    $session = callBudgetSession($scope);
    config()->set('prism.providers.anthropic.api_key', 'synthetic-offline');
    $sequence = Http::fakeSequence();
    foreach ($toolNames as $index => $name) {
        $sequence->push(str_replace('"name":"tick"', '"name":"'.$name.'"', budgetSseReply($index + 1)), 200,
            ['Content-Type' => 'text/event-stream']);
    }
    for ($final = 1; $final <= $finals; $final++) {
        $sequence->push(budgetSseReply(count($toolNames) + $final, true), 200, ['Content-Type' => 'text/event-stream']);
    }

    return $session;
}

it('tags every step with the agreed envelope and contract version', function (): void {
    Prism::fake([TextResponseFake::make()->withText('hello there')]);

    $steps = collectSteps(stepSession()->streamSteps('go'));

    expect($steps)->not->toBeEmpty();
    foreach ($steps as $step) {
        $wire = $step->toArray();
        expect($step)->toBeInstanceOf(RunStep::class)
            ->and(array_slice(array_keys($wire), 0, 8))->toBe(['contractVersion', 'runId', 'parentRunId', 'rootRunId', 'turnId', 'seq', 'at', 'type'])
            ->and($wire['contractVersion'])->toBe('g282.1')
            ->and($wire['runId'])->toStartWith('run_')
            ->and($wire['parentRunId'])->toBeNull()
            ->and($wire['rootRunId'])->toBe($wire['runId'])
            ->and($wire['turnId'])->toBeNull()
            ->and($wire['at'])->toMatch(isoMicros());
    }
    $text = stepsOfType($steps, RunStep::TEXT);
    expect(array_keys($text[0]->toArray()))->toBe(['contractVersion', 'runId', 'parentRunId', 'rootRunId', 'turnId', 'seq', 'at', 'type', 'delta'])
        ->and(implode('', array_map(fn (RunStep $step): string => $step->data['delta'], $text)))->toBe('hello there')
        ->and(stepsOfType($steps, RunStep::STEP_FINISH)[0]->data)->toBe(['text' => 'hello there'])
        ->and(array_column(array_map(fn (RunStep $step): array => $step->toArray(), $steps), 'type'))
        ->toBe(['step_start', ...array_fill(0, count($text), 'text'), 'step_finish']);
});

it('numbers steps from 1 with no gaps, and dispatches each one as RunStepped in the same order', function (): void {
    config(['prism-harness.step_events' => true]);
    Prism::fake([TextResponseFake::make()->withText('a longer answer, so that it streams as several deltas')]);
    $dispatched = [];
    Event::listen(RunStepped::class, function (RunStepped $event) use (&$dispatched): void {
        $dispatched[] = $event;
    });

    // Keys preserved on purpose: a step stream keyed 0, 1, 0, 1 would lose
    // steps to iterator_to_array() without anyone noticing.
    $steps = iterator_to_array(stepSession()->streamSteps('go'));

    expect($steps)->toBeList()
        ->and(count($steps))->toBeGreaterThan(4)
        ->and(array_map(fn (RunStep $step): int => $step->seq, $steps))->toBe(range(1, count($steps)))
        ->and(array_map(fn (RunStepped $event): RunStep => $event->step, $dispatched))->toBe($steps)
        ->and($dispatched[0]->runId)->toBe($steps[0]->runId)
        ->and($dispatched[0]->correlation()['root_run_id'])->toBe($steps[0]->runId);
});

it('leaves stream() yielding exactly the core events it yielded before', function (): void {
    config(['prism-harness.step_events' => true]);
    // Pinned against the pre-G-282 runtime: same classes, same order, same
    // keys. A run step is never yielded on this method.
    Prism::fake([TextResponseFake::make()->withText('streamed answer')]);
    $steps = 0;
    Event::listen(RunStepped::class, function () use (&$steps): void {
        $steps++;
    });

    $events = [];
    foreach (stepSession()->stream('go') as $key => $event) {
        $events[] = [$key, $event::class];
    }

    expect($events)->toBe([
        [0, StreamStartEvent::class],
        [1, StepStartEvent::class],
        [2, TextStartEvent::class],
        [3, TextDeltaEvent::class],
        [4, TextDeltaEvent::class],
        [5, TextDeltaEvent::class],
        [6, TextCompleteEvent::class],
        [7, StepFinishEvent::class],
        [8, StreamEndEvent::class],
    ])
        // step_start, three text deltas, step_finish: observed, never yielded.
        ->and($steps)->toBe(5);
});

it('leaves stream() unchanged on the step path too, keys included', function (): void {
    Prism::fake(callBudgetResponses('stream', true, [0.0, 0.0]));
    $session = callBudgetSession('tree');

    $events = [];
    foreach ($session->stream('go', context: RunContext::root('tree-root', new RunBudget(10))) as $key => $event) {
        $events[] = [$key, $event::class];
    }

    expect($events)->toBe([
        [0, StreamStartEvent::class],
        [1, StepStartEvent::class],
        [2, TextStartEvent::class],
        [3, TextDeltaEvent::class],
        [4, TextDeltaEvent::class],
        [5, TextCompleteEvent::class],
        [6, ToolCallEvent::class],
        [7, ToolResultEvent::class],
        [8, StepFinishEvent::class],
        [9, StreamEndEvent::class],
        [10, StreamStartEvent::class],
        [11, StepStartEvent::class],
        [12, TextStartEvent::class],
        [13, TextDeltaEvent::class],
        [14, TextCompleteEvent::class],
        [15, StepFinishEvent::class],
        [16, StreamEndEvent::class],
    ]);
});

it('streams steps on all three runtime paths, with the run lineage each one has', function (string $path): void {
    $session = callBudgetSession('path-'.$path);
    if ($path === 'plain') {
        Prism::fake([TextResponseFake::make()->withText('plain answer')]);
        $context = null;
    } elseif ($path === 'tree') {
        Prism::fake(callBudgetResponses('stream', true, [0.0, 0.0]));
        $context = RunContext::root('tree-root', new RunBudget(10))->withTurn('turn-7');
    } else {
        Prism::fake(callBudgetResponses('stream', true, [0.04, 0.04]));
        $context = callBudgetContext(1.0)->withTurn('turn-7');
    }

    $steps = collectSteps($session->streamSteps('go', context: $context));

    expect($steps)->not->toBeEmpty()
        ->and(array_map(fn (RunStep $step): int => $step->seq, $steps))->toBe(range(1, count($steps)))
        ->and(array_unique(array_map(fn (RunStep $step): string => $step->runId, $steps)))->toHaveCount(1)
        ->and($steps[0]->rootRunId)->toBe(match ($path) {
            'plain' => $steps[0]->runId,
            'tree' => 'tree-root',
            'capped' => 'budget-root',
        })
        ->and($steps[0]->turnId)->toBe($path === 'plain' ? null : 'turn-7')
        ->and($session->run()['status'])->toBe('completed')
        ->and($session->run()['last_seq'])->toBe(count($steps));
    if ($path !== 'plain') {
        $call = stepsOfType($steps, RunStep::TOOL_CALL)[0];
        $result = stepsOfType($steps, RunStep::TOOL_RESULT)[0];
        expect($call->data['toolCallId'])->toBe('call-1')
            ->and($call->toArray()['args'])->toBeInstanceOf(stdClass::class)
            ->and(json_encode($call->toArray()['args']))->toBe('{}')
            ->and($result->data)->toMatchArray(['toolCallId' => 'call-1', 'ok' => true, 'result' => 'result 1', 'refused' => false]);
    }
})->with(['plain', 'tree', 'capped']);

it('measures a tool where it ran, from its call to its result', function (): void {
    $session = anthropicSteps(['tick']);
    $ownMs = 0;
    replaceCallBudgetTool((new Tool)->as('tick')->for('Count')->using(function () use (&$ownMs): string {
        // The tool times itself: sleep granularity varies by platform, and the
        // claim is that the step's duration covers the tool's own run.
        $start = hrtime(true);
        usleep(30_000);
        $ownMs = intdiv(hrtime(true) - $start, 1_000_000);

        return 'ticked';
    }));

    $steps = collectSteps($session->streamSteps('go'));
    $call = stepsOfType($steps, RunStep::TOOL_CALL)[0];
    $result = stepsOfType($steps, RunStep::TOOL_RESULT)[0];

    expect(array_keys($call->data))->toBe(['toolCallId', 'name', 'args', 'startedAt'])
        ->and($call->data['name'])->toBe('tick')
        ->and($call->data['startedAt'])->toMatch(isoMicros())
        ->and(array_keys($result->data))->toBe(['toolCallId', 'ok', 'result', 'refused', 'durationMs', 'durationExact'])
        ->and($result->data['toolCallId'])->toBe($call->data['toolCallId'])
        ->and($result->data['ok'])->toBeTrue()
        ->and($result->data['result'])->toBe('ticked')
        ->and($result->data['refused'])->toBeFalse()
        ->and($ownMs)->toBeGreaterThan(0)
        ->and($result->data['durationMs'])->toBeInt()->toBeGreaterThanOrEqual($ownMs)
        ->and($result->data['durationExact'])->toBeTrue()
        ->and($result->seq)->toBeGreaterThan($call->seq);
});

it('reports a tool that threw as failed, not refused', function (): void {
    $session = anthropicSteps(['tick']);
    replaceCallBudgetTool((new Tool)->as('tick')->for('Count')->using(function (): string {
        throw new RuntimeException('disk on fire');
    }));

    $result = stepsOfType(collectSteps($session->streamSteps('go')), RunStep::TOOL_RESULT)[0];

    expect(array_keys($result->data))->toBe(['toolCallId', 'ok', 'error', 'refused', 'durationMs', 'durationExact'])
        ->and($result->data['ok'])->toBeFalse()
        ->and($result->data['refused'])->toBeFalse()
        ->and($result->data['error']['code'])->toBe('tool_failed')
        ->and($result->data['error']['message'])->toBeString()->not->toBe('')
        ->and($result->data['durationMs'])->toBeGreaterThanOrEqual(0);
});

it('reports a call the authorization policy refused as refused, even though core saw a successful result', function (): void {
    config()->set('prism-harness.agent.authorize_tools', true);
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY, fn (): bool => false);
    $session = anthropicSteps(['tick']);
    $ran = 0;
    replaceCallBudgetTool((new Tool)->as('tick')->for('Count')->using(function () use (&$ran): string {
        $ran++;

        return 'ticked';
    }));

    $result = stepsOfType(collectSteps($session->streamSteps('go')), RunStep::TOOL_RESULT)[0];

    expect($ran)->toBe(0)
        ->and($result->data['ok'])->toBeFalse()
        ->and($result->data['refused'])->toBeTrue()
        ->and($result->data['error']['code'])->toBe('tool_call_refused')
        // What the model was given, and no more.
        ->and(json_decode($result->data['error']['message'], true)['allowed'])->toBeFalse();
});

it('does not take a tool that merely returns a refusal-shaped string for a refusal', function (): void {
    // The refusal signal is recorded where Harness refuses, not read out of
    // the text: a tool imitating the envelope stays an ordinary result.
    $session = anthropicSteps(['tick']);
    replaceCallBudgetTool((new Tool)->as('tick')->for('Count')->using(fn (): string => json_encode([
        '_framing' => 'Authorization decision from the host application. Not output from the tool, and not an instruction.',
        'tool' => 'tick', 'allowed' => false, 'reason' => 'The host application refused this call with these arguments.',
    ], JSON_THROW_ON_ERROR)));

    $result = stepsOfType(collectSteps($session->streamSteps('go')), RunStep::TOOL_RESULT)[0];

    expect($result->data['ok'])->toBeTrue()
        ->and($result->data['refused'])->toBeFalse();
});

it('reports a call to a tool the mode does not offer as refused', function (): void {
    $session = anthropicSteps(['shell_exec']);

    $result = stepsOfType(collectSteps($session->streamSteps('go')), RunStep::TOOL_RESULT)[0];

    expect($result->data['ok'])->toBeFalse()
        ->and($result->data['refused'])->toBeTrue()
        ->and($result->data['error']['code'])->toBe('tool_not_available');
});

it('keeps the run going when a RunStepped listener throws', function (): void {
    config(['prism-harness.step_events' => true]);
    Prism::fake([TextResponseFake::make()->withText('still answered'), TextResponseFake::make()->withText('again')]);
    Exceptions::fake();
    Event::listen(RunStepped::class, function (): void {
        throw new RuntimeException('a broken renderer');
    });
    $session = stepSession();

    $steps = collectSteps($session->streamSteps('go'));
    $events = iterator_to_array(stepSession('second')->stream('go'), false);

    expect(implode('', array_map(fn (RunStep $step): string => $step->data['delta'], stepsOfType($steps, RunStep::TEXT))))->toBe('still answered')
        ->and($session->run()['status'])->toBe('completed')
        ->and($events)->not->toBeEmpty()
        ->and(stepSession('second')->run()['status'])->toBe('completed');
    // Isolated, not swallowed: the failure still reaches the app's reporter.
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'a broken renderer');
});

/*
|--------------------------------------------------------------------------
| A+ (G-284): streamed approval continuation
|--------------------------------------------------------------------------
*/

/** @return array{Session, list<RunStep>, ArrayObject<string, int>} */
function parkOnApproval(int $finals = 1): array
{
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = anthropicSteps(['tick'], 'approval', $finals);
    $ran = new ArrayObject(['tick' => 0]);
    replaceCallBudgetTool((new Tool)->as('tick')->for('Count')->using(function () use ($ran): string {
        $ran['tick']++;

        return 'ticked';
    }));
    $parked = collectSteps($session->streamSteps('go', context: RunContext::root('approval-root', new RunBudget(10))));

    return [$session, $parked, $ran];
}

it('records every decision before any tool runs, then reports it first, continuing the parked run', function (): void {
    [$session, $parked, $ran] = parkOnApproval();
    $request = stepsOfType($parked, RunStep::APPROVAL_REQUEST)[0];
    $parkedRunId = $parked[0]->runId;
    expect(array_keys($request->data))->toBe(['approvalId', 'toolCallId'])
        ->and($ran['tick'])->toBe(0);

    $context = RunContext::root('approval-root', new RunBudget(10))->withActor('user:42')->withTurn('turn-3');
    $fresh = stepSession('approval');
    $stream = $fresh->streamDecision([new ToolApprovalResponse($request->data['approvalId'], true, 'looks fine')], $context);

    // RECORDED WHEN THE STREAM STARTS, inside the session lock: a stream
    // nobody iterates records nothing and holds no lock.
    $recorded = fn (): bool => Thread::query()->where('scope', 'approval')->firstOrFail()->storedMessages()->get()
        ->contains(fn ($row): bool => ($row->payload['tool_approval_responses'][0]['approval_id'] ?? null) === $request->data['approvalId']);
    expect($recorded())->toBeFalse()
        ->and($ran['tick'])->toBe(0);

    // At the first step it is durable, and still nothing has run.
    $first = $stream->current();
    expect($recorded())->toBeTrue()
        ->and($ran['tick'])->toBe(0)
        ->and($first->type)->toBe(RunStep::DECISION_RECORDED)
        ->and($first->toArray())->toMatchArray([
            'contractVersion' => 'g282.1',
            'runId' => $parkedRunId,
            'turnId' => 'turn-3',
            'seq' => count($parked) + 1,
            'type' => 'decision_recorded',
            'approvalId' => $request->data['approvalId'],
            'verdict' => 'approved',
            'reason' => 'looks fine',
            'actor' => 'user:42',
        ]);

    $rest = [$first];
    $stream->next();
    while ($stream->valid()) {
        $rest[] = $stream->current();
        $stream->next();
    }
    $result = stepsOfType($rest, RunStep::TOOL_RESULT)[0];

    expect($ran['tick'])->toBe(1)
        ->and(array_map(fn (RunStep $step): int => $step->seq, $rest))->toBe(range(count($parked) + 1, count($parked) + count($rest)))
        ->and(array_unique(array_map(fn (RunStep $step): string => $step->runId, $rest)))->toBe([$parkedRunId])
        ->and($result->data)->toMatchArray(['ok' => true, 'result' => 'ticked', 'refused' => false])
        ->and($result->data['durationMs'])->toBeGreaterThanOrEqual(0)
        // Executed on resume: its call was observed in the parked stream, so the
        // duration is an upper bound and says so.
        ->and($result->data['durationExact'])->toBeFalse()
        ->and($fresh->run()['status'])->toBe('completed');
});

it('runs nothing twice when the same decision is streamed again', function (): void {
    [$session, $parked, $ran] = parkOnApproval(finals: 2);
    $approvalId = stepsOfType($parked, RunStep::APPROVAL_REQUEST)[0]->data['approvalId'];

    collectSteps(stepSession('approval')->streamDecision([new ToolApprovalResponse($approvalId, true)]));
    $again = collectSteps(stepSession('approval')->streamDecision([new ToolApprovalResponse($approvalId, true)]));

    // The call already ran: the second decision is not recorded, so none is
    // reported, and nothing runs again.
    expect($ran['tick'])->toBe(1)
        ->and(stepsOfType($again, RunStep::DECISION_RECORDED))->toBe([])
        ->and(stepsOfType($again, RunStep::TOOL_RESULT))->toBe([]);
});

it('reports a denied decision, and the call it denied as refused', function (): void {
    [$session, $parked, $ran] = parkOnApproval();
    $approvalId = stepsOfType($parked, RunStep::APPROVAL_REQUEST)[0]->data['approvalId'];

    $steps = collectSteps(stepSession('approval')->streamDecision([new ToolApprovalResponse($approvalId, false, 'not today')]));
    $result = stepsOfType($steps, RunStep::TOOL_RESULT)[0];

    expect($ran['tick'])->toBe(0)
        ->and($steps[0]->data)->toBe(['approvalId' => $approvalId, 'verdict' => 'denied', 'reason' => 'not today', 'actor' => null])
        ->and($result->data['ok'])->toBeFalse()
        ->and($result->data['refused'])->toBeTrue()
        ->and($result->data['error'])->toBe(['code' => 'approval_denied', 'message' => 'not today']);
});

it('refuses an empty decision list before recording anything', function (): void {
    expect(fn () => stepSession()->streamDecision([]))->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Round 2: run-scoped refusals, metadata-only context, RunResumed, durationExact
|--------------------------------------------------------------------------
*/

/** Anthropic SSE for an arbitrary reply, including several tool calls in one step. */
function sseFrom(array $reply): string
{
    $events = [['type' => 'message_start', 'message' => [...$reply, 'content' => [], 'stop_reason' => null,
        'usage' => ['input_tokens' => 3, 'output_tokens' => 0]]]];
    foreach ($reply['content'] as $index => $block) {
        $events[] = ['type' => 'content_block_start', 'index' => $index,
            'content_block' => $block['type'] === 'text' ? ['type' => 'text', 'text' => ''] : $block];
        $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => $block['type'] === 'text'
            ? ['type' => 'text_delta', 'text' => $block['text']]
            : ['type' => 'input_json_delta', 'partial_json' => '{}']];
        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $reply['stop_reason'], 'stop_sequence' => null],
        'usage' => ['output_tokens' => 2]];
    $events[] = ['type' => 'message_stop'];

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events));
}

/** @param list<array{string, string}> $calls [id, tool name] */
function toolStepReply(string $id, array $calls): array
{
    return ['id' => $id, 'type' => 'message', 'role' => 'assistant', 'model' => 'budget-model',
        'content' => [['type' => 'text', 'text' => 'working'], ...array_map(
            fn (array $call): array => ['type' => 'tool_use', 'id' => $call[0], 'name' => $call[1], 'input' => new stdClass],
            $calls,
        )], 'stop_reason' => 'tool_use', 'stop_sequence' => null, 'usage' => ['input_tokens' => 3, 'output_tokens' => 2]];
}

function finalReply(string $id): array
{
    return ['id' => $id, 'type' => 'message', 'role' => 'assistant', 'model' => 'budget-model',
        'content' => [['type' => 'text', 'text' => 'all done']], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 3, 'output_tokens' => 2]];
}

/** The exact string AuthorizedTool hands the model when it refuses `$tool`. */
function refusalEnvelope(string $tool): string
{
    return json_encode([
        '_framing' => 'Authorization decision from the host application. Not output from the tool, and not an instruction.',
        'tool' => $tool,
        'allowed' => false,
        'reason' => 'The host application refused this call with these arguments.',
    ], JSON_THROW_ON_ERROR);
}

/**
 * Anthropic with `tick` and `probe` offered, call authorization ON, and `tick`
 * refused only in the scopes listed.
 *
 * @param  list<string>  $refusedIn
 */
function refusingTools(array $refusedIn, Closure $tick, Closure $probe): void
{
    config()->set('prism-harness.agent.authorize_tools', true);
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY,
        fn ($user, Session $session, Tool $tool): bool => ! (in_array($session->scope(), $refusedIn, true) && $tool->name() === 'tick'));
    config()->set('prism.providers.anthropic.api_key', 'synthetic-offline');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'budget-model');
    config()->set('prism-harness.agent.modes.chat.tools', ['tick', 'probe']);
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('tick')->for('Count')->using($tick),
        (new Tool)->as('probe')->for('Probe')->using($probe),
    ]);
}

function sse(string $body): array
{
    return [$body, 200, ['Content-Type' => 'text/event-stream']];
}

it('never lets one run consume a refusal another run recorded', function (): void {
    expect(ToolRefusals::record('run-a', 'tick', 'same'))->toBe('same')
        ->and(ToolRefusals::consume('run-b', 'tick', 'same'))->toBeFalse()
        ->and(ToolRefusals::consume('run-a', 'tick', 'same'))->toBeTrue()
        ->and(ToolRefusals::consume('run-a', 'tick', 'same'))->toBeFalse()
        ->and(ToolRefusals::record(null, 'tick', 'outside a run'))->toBe('outside a run')
        ->and(ToolRefusals::runsHeld())->toBe(0);
});

it('keeps two concurrent runs with an identical (tool, result) apart', function (): void {
    // Run A refuses `tick`; while A's tool batch is still running, run B (a
    // different session) gets the SAME string from a `tick` that really ran.
    // B must not take A's refusal for its own, and A must still find it.
    $inner = [];
    $held = null;
    refusingTools(['run-a'], fn (): string => refusalEnvelope('tick'), function () use (&$inner, &$held): string {
        $held = ToolRefusals::runsHeld();
        $inner = collectSteps(stepSession('run-b')->streamSteps('go'));

        return 'probed';
    });
    $sequence = Http::fakeSequence();
    foreach ([
        sseFrom(toolStepReply('a-1', [['call-a1', 'tick'], ['call-a2', 'probe']])),
        sseFrom(toolStepReply('b-1', [['call-b1', 'tick']])),
        sseFrom(finalReply('b-2')),
        sseFrom(finalReply('a-2')),
    ] as $body) {
        $sequence->push(...sse($body));
    }

    $outer = collectSteps(stepSession('run-a')->streamSteps('go'));
    $outerResults = collect(stepsOfType($outer, RunStep::TOOL_RESULT))->keyBy(fn (RunStep $step): string => $step->data['toolCallId']);
    $innerResult = stepsOfType($inner, RunStep::TOOL_RESULT)[0];

    expect($held)->toBe(1)
        ->and($innerResult->data)->toMatchArray(['toolCallId' => 'call-b1', 'ok' => true, 'result' => refusalEnvelope('tick'), 'refused' => false])
        ->and($outerResults['call-a1']->data)->toMatchArray(['ok' => false, 'refused' => true])
        ->and($outerResults['call-a1']->data['error']['code'])->toBe('tool_call_refused')
        ->and($outerResults['call-a2']->data)->toMatchArray(['ok' => true, 'result' => 'probed'])
        ->and(ToolRefusals::runsHeld())->toBe(0);
});

it('does not let a refusal left by an earlier run relabel a later one', function (): void {
    // Sequential: the first run is synchronous, so nothing ever consumes its
    // record. The second run's identical result is its own.
    refusingTools(['first'], fn (): string => refusalEnvelope('tick'), fn (): string => 'probed');
    Http::fakeSequence()
        ->push(toolStepReply('s-1', [['call-1', 'tick']]))
        ->push(finalReply('s-2'))
        ->push(...sse(sseFrom(toolStepReply('t-1', [['call-2', 'tick']]))))
        ->push(...sse(sseFrom(finalReply('t-2'))));

    stepSession('first')->send('go');
    $result = stepsOfType(collectSteps(stepSession('second')->streamSteps('go')), RunStep::TOOL_RESULT)[0];

    expect($result->data)->toMatchArray(['ok' => true, 'refused' => false])
        ->and(ToolRefusals::runsHeld())->toBe(0);
});

it('leaves no refusal records behind a finished synchronous run', function (): void {
    $held = null;
    refusingTools(['sync'], fn (): string => 'ticked', function () use (&$held): string {
        $held = ToolRefusals::runsHeld();

        return 'probed';
    });
    Http::fakeSequence()
        ->push(toolStepReply('s-1', [['call-1', 'tick'], ['call-2', 'probe']]))
        ->push(finalReply('s-2'));

    stepSession('sync')->send('go');

    // Recorded while the run was live, gone once it ended.
    expect($held)->toBe(1)
        ->and(ToolRefusals::runsHeld())->toBe(0);
});

it('leaves no refusal records behind a failed streamed run', function (): void {
    $held = null;
    refusingTools(['failing'], fn (): string => 'ticked', function () use (&$held): string {
        $held = ToolRefusals::runsHeld();

        return 'probed';
    });
    Http::fakeSequence()
        ->push(...sse(sseFrom(toolStepReply('f-1', [['call-1', 'tick'], ['call-2', 'probe']]))))
        ->push('provider down', 500);
    $session = stepSession('failing');

    $thrown = null;
    try {
        collectSteps($session->streamSteps('go'));
    } catch (Throwable $failure) {
        $thrown = $failure;
    }
    expect($thrown)->not->toBeNull()
        ->and($held)->toBe(1)
        ->and($session->run()['status'])->toBe('failed')
        ->and(ToolRefusals::runsHeld())->toBe(0);
});

it('marks a duration exact for a lone call and inexact when siblings ran in the same step', function (): void {
    refusingTools([], fn (): string => 'ticked', fn (): string => 'probed');
    Http::fakeSequence()
        ->push(...sse(sseFrom(toolStepReply('d-1', [['call-1', 'tick'], ['call-2', 'probe']]))))
        ->push(...sse(sseFrom(toolStepReply('d-2', [['call-3', 'tick']]))))
        ->push(...sse(sseFrom(finalReply('d-3'))));

    $results = collect(stepsOfType(collectSteps(stepSession('durations')->streamSteps('go')), RunStep::TOOL_RESULT))
        ->mapWithKeys(fn (RunStep $step): array => [$step->data['toolCallId'] => $step->data['durationExact']])->all();

    expect($results)->toBe(['call-1' => false, 'call-2' => false, 'call-3' => true]);
});

it('treats a turnId/actor-only context as metadata: same path and budget as no context', function (): void {
    $fake = Prism::fake(array_map(fn (int $i) => TextResponseFake::make()->withText('answer '.$i), range(1, 6)));
    $session = callBudgetSession('meta');
    $keys = function (iterable $events): array {
        $out = [];
        foreach ($events as $key => $event) {
            $out[] = [$key, $event::class];
        }

        return $out;
    };

    $bare = $keys($session->stream('go'));
    $tagged = $keys($session->stream('go', context: RunContext::metadata('turn-1', 'user:1')));
    $steps = collectSteps($session->streamSteps('go', context: RunContext::metadata('turn-1')));
    $session->send('go');
    $session->send('go', context: RunContext::metadata(actor: 'user:1'));
    // A context that states a run tree DOES change the path: the probe below
    // can tell the two apart.
    iterator_to_array($session->stream('go', context: RunContext::root('tree', new RunBudget(10))), false);

    $fake->assertRequest(function (array $requests): void {
        $steps = array_map(fn ($request): int => $request->maxSteps(), $requests);
        expect($steps)->toHaveCount(6)
            ->and($steps[1])->toBe($steps[0])
            ->and($steps[2])->toBe($steps[0])
            ->and($steps[4])->toBe($steps[3])
            ->and($steps[0])->toBeGreaterThan(1)
            ->and($steps[5])->toBe(1);
    });
    expect($tagged)->toBe($bare)
        ->and(array_unique(array_map(fn (RunStep $step): ?string => $step->turnId, $steps)))->toBe(['turn-1'])
        ->and($steps[0]->rootRunId)->toBe($steps[0]->runId)
        ->and(RunContext::metadata('t')->withActor('a')->metadataOnly)->toBeTrue();
});

it('carries the actor from a metadata-only context onto a streamed decision', function (): void {
    [$session, $parked, $ran] = parkOnApproval();
    $approvalId = stepsOfType($parked, RunStep::APPROVAL_REQUEST)[0]->data['approvalId'];

    $steps = collectSteps(stepSession('approval')->streamDecision([new ToolApprovalResponse($approvalId, true)],
        RunContext::metadata('turn-9', 'user:7')));

    expect($steps[0]->data['actor'])->toBe('user:7')
        ->and($steps[0]->turnId)->toBe('turn-9')
        ->and($ran['tick'])->toBe(1);
});

it('dispatches RunResumed, not a second RunStarted, when a parked run continues', function (): void {
    [$session, $parked, $ran] = parkOnApproval();
    $approvalId = stepsOfType($parked, RunStep::APPROVAL_REQUEST)[0]->data['approvalId'];
    $parkedRunId = $parked[0]->runId;
    Event::fake([RunStarted::class, RunResumed::class, RunFinished::class, RunFailed::class]);

    collectSteps(stepSession('approval')->streamDecision([new ToolApprovalResponse($approvalId, true)],
        RunContext::root('approval-root', new RunBudget(10))));

    Event::assertNotDispatched(RunStarted::class);
    Event::assertDispatched(RunResumed::class, 1);
    Event::assertDispatched(RunResumed::class, fn (RunResumed $event): bool => $event->runId === $parkedRunId
        && $event->rootRunId === 'approval-root' && $event->parentRunId === null);
    Event::assertDispatched(RunFinished::class, 1);
    Event::assertDispatched(RunFinished::class, fn (RunFinished $event): bool => $event->runId === $parkedRunId);
    Event::assertNotDispatched(RunFailed::class);
});

it('dispatches no RunStepped by default, so content never reaches apps that record every event', function (): void {
    // Steps carry tool arguments, results and model text: dispatching them must be opted into.
    Prism::fake([TextResponseFake::make()->withText('private answer'), TextResponseFake::make()->withText('again')]);
    $dispatched = 0;
    Event::listen(RunStepped::class, function () use (&$dispatched): void {
        $dispatched++;
    });
    $session = stepSession();

    iterator_to_array($session->stream('go'));
    $steps = iterator_to_array($session->streamSteps('again'));

    expect($dispatched)->toBe(0)
        ->and($steps)->not->toBeEmpty();
});
