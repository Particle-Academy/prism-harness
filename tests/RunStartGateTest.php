<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Events\ToolDenied;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Models\Thread;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Plans\PlanBudget;
use Prism\Harness\Plans\Plans;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunLedger;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentResult;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Participant;
use Tests\Support\G360;

// G-387 round 7: a runner start is decided by ONE gate (RunStarts::gate),
// read before the start writes anything, and committed in one order: the
// tree hold, then the child's records in one locked write, then the run.
// Every test enters where a real caller enters (the subagent TOOL inside a
// parent turn, or SubagentRunner::run) over Prism's real OpenAI handler on
// faked HTTP with stray requests refused. A refused start must leave every
// durable record byte-identical, write no run state for the child, send the
// child no model request, and leave the parent as it was.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.max_steps', 8);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'worker' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => 3],
    ]);
    config()->set('prism-harness.agent.modes.work', [
        'system_prompt' => 'Work.', 'tools' => ['weather', 'echo'], 'skills' => [], 'max_steps' => 8, 'requires_approval' => ['weather'],
    ]);
    $this->ran = ['weather' => 0, 'echo' => 0];
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran['weather']++;

                return 'Sunny';
            }),
        (new Tool)->as('echo')->for('Echo.')->withStringParameter('text', 'Text')
            ->using(function (string $text) use (&$ran): string {
                $ran['echo']++;

                return $text;
            }),
    ]);
});

function sgRoot(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

function sgChild(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat::sub::worker');
}

function sgPad(int $count = 30): void
{
    for ($i = 0; $i < $count; $i++) {
        G360::calls([['weather', ['city' => 'Paris']]]);
    }
}

function sgRequests(): int
{
    return count(Http::recorded());
}

function sgSent(): string
{
    return collect(Http::recorded())->map(fn ($pair): string => (string) $pair[0]->body())->implode("\n");
}

/**
 * Every durable and ephemeral record, every thread and every row, as text.
 * With `$childOnly`, only the child's records and the run-tree records.
 *
 * @return array<string, string>
 */
function sgSnapshot(bool $childOnly = false): array
{
    $out = [];
    foreach (DB::table('harness_session_state')->orderBy('key')->get() as $row) {
        if ($childOnly && ! str_contains($row->key, '::sub::worker') && ! str_contains($row->key, ':run-tree:')) {
            continue;
        }
        $out['state '.$row->key] = (string) $row->payload;
    }
    foreach (DB::table('harness_threads')->orderBy('id')->get() as $row) {
        if ($childOnly && ! str_contains((string) $row->scope, '::sub::worker')) {
            continue;
        }
        $out['thread '.$row->id] = (string) json_encode($row);
        foreach (DB::table('harness_thread_messages')->where('thread_id', $row->id)->orderBy('id')->get() as $message) {
            $out['row '.$message->id] = (string) json_encode($message);
        }
    }
    foreach (['harness_addresses', 'harness_address_resources', 'harness_address_heads'] as $table) {
        if (! $childOnly) {
            foreach (DB::table($table)->get() as $i => $row) {
                $out[$table.' '.$i] = (string) json_encode($row);
            }
        }
    }

    return $out;
}

/** The child's own records: its binding, its count, its state, its thread and rows. */
function sgChildRecords(): array
{
    return array_filter(sgSnapshot(true), fn (string $key): bool => ! str_contains($key, ':run-tree:'), ARRAY_FILTER_USE_KEY);
}

/** The child parks on weather after one step in a first root turn; the root then answers. */
function sgParkedChild(): void
{
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::calls([['weather', ['city' => 'Paris']]]);
    G360::text('Asked.');
    sgRoot()->send('Go');
    expect(sgChild()->thread()->pendingApprovalIds())->toHaveCount(1);
}

/** Answer the child's approval without acting on it, so a person can no longer answer it. */
function sgAnswered(): void
{
    $child = sgChild();
    $pending = $child->thread()->pendingApprovalIds();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($pending[0], true)]))->call($child);
}

/**
 * Make the child's pending approval one nobody can answer: a person cancels
 * the run parked on it. (A recorded decision no longer does: a decided call
 * a resume could still act on holds the child, G-387 round 10.)
 */
function sgUnanswerable(): void
{
    $child = sgChild();
    $parked = $child->thread()->approvalRunId($child->thread()->pendingApprovalIds()[0]);
    expect($child->cancel((string) $parked)->value)->toBe('ok');
}

function sgRunner(): SubagentRunner
{
    return app(SubagentRunner::class);
}

function sgWorker(int $steps = 3): Subagent
{
    return new Subagent('worker', 'Work.', 'work', new RunBudget($steps));
}

function sgFresh(): void
{
    foreach ([PrismHarness::class, AgentRuntime::class, SubagentRunner::class, RunTrees::class, Plans::class] as $class) {
        app()->forgetInstance($class);
    }
}

/** Move a ledger's start `$seconds` into the past. */
function sgAge(RunLedger $ledger, int $seconds): void
{
    (fn () => $this->startedAt -= $seconds)->call($ledger);
}

/**
 * One root turn whose model calls the subagent tool once with `$task`. The
 * records are snapshotted when the root's first request arrives and again
 * when its second does (after the tool returned): a refused start changes
 * nothing between them, the parent's own records included.
 *
 * @return array{before: array<string, string>, after: array<string, string>, outputs: string, requests: int}
 */
function sgToolTurn(mixed $task, ?RunContext $context = null, ?Closure $atFirst = null): array
{
    G360::calls([['worker', ['task' => $task]]]);
    G360::text('Handled.');
    sgPad();
    $before = $after = null;
    $start = sgRequests();
    G360::$onRequest = function (int $n) use (&$before, &$after, $atFirst): void {
        if ($before === null) {
            $before = sgSnapshot();
            if ($atFirst !== null) {
                $atFirst();
            }
        } elseif ($after === null) {
            $after = sgSnapshot();
        }
    };
    sgRoot()->send('Go', context: $context);
    G360::$onRequest = null;

    return ['before' => $before, 'after' => $after, 'outputs' => implode(' ', G360::outputs()), 'requests' => sgRequests() - $start];
}

/** The gate codes a refused start reports, and the outcome each is framed with. */
function sgExpectRefused(SubagentResult $result, string $outcome): void
{
    expect($result->outcome->value)->toBe($outcome)
        ->and($result->outcome->succeeded())->toBeFalse()
        ->and((string) $result->content)->toBe('');
}

// ---------------------------------------------------------------------------
// 1. Each stop of the gate, through the subagent TOOL inside a parent turn.
// ---------------------------------------------------------------------------

it('refuses through the tool, writing nothing, a task that is not text or is blank', function (mixed $task): void {
    $turn = sgToolTurn($task);

    expect($turn['outputs'])->toContain('"outcome":"denied"')
        ->and($turn['requests'])->toBe(2)
        ->and($turn['after'])->toBe($turn['before']);
})->with(['a number' => [42], 'null' => [null], 'blank' => [" \u{3000}\u{200B}"]]);

it('refuses through the tool, writing nothing, a start past the maximum depth', function (): void {
    $turn = sgToolTurn('Go', RunContext::root('host_run', new RunBudget(10))->atDepth(RunContext::MAX_DEPTH - 1));

    expect($turn['outputs'])->toContain('"outcome":"denied"')->and($turn['outputs'])->toContain('maximum depth')
        ->and($turn['requests'])->toBe(2)
        ->and($turn['after'])->toBe($turn['before']);
});

it('refuses through the tool, writing nothing, a start with no step left', function (): void {
    $context = RunContext::root('host_run', new RunBudget(10));
    // The host's tree spends the rest elsewhere while the root's first step runs.
    $turn = sgToolTurn('Go', $context, fn () => $context->ledger->recordSteps(9));

    expect(G360::threadText())->toContain('exhausted')
        ->and($turn['requests'])->toBe(1)
        ->and(sgChildRecords())->toBe([]);
});

it('refuses through the tool, writing nothing, a delegation into a child a person can still answer', function (): void {
    sgParkedChild();
    $turn = sgToolTurn('Start over');

    expect($turn['outputs'])->toContain('"outcome":"denied"')
        ->and($turn['requests'])->toBe(2)
        ->and($turn['after'])->toBe($turn['before'])
        ->and(sgChild()->approvalStatus(sgChild()->thread()->pendingApprovalIds()[0]))->toBe('pending');
});

it('refuses through the tool, writing nothing, a delegation into a child a running turn holds', function (): void {
    sgParkedChild();
    $pending = sgChild()->thread()->pendingApprovalIds();
    sgPad();
    $turn = null;
    G360::$onRequest = function () use (&$turn): void {
        if ($turn === null) {
            $turn = 'running';
            G360::$queue = [];
            G360::$onRequest = null;
            $turn = sgToolTurn('Start over');
        }
    };
    try {
        sgChild()->approve($pending[0]);
    } catch (Throwable) {
    }
    G360::$onRequest = null;

    expect($turn['outputs'])->toContain('"outcome":"denied"')->and($turn['outputs'])->toContain('already running')
        ->and($turn['requests'])->toBe(2)
        ->and($turn['after'])->toBe($turn['before']);
});

it('refuses through the tool, writing nothing, a start the run tree cannot fund', function (): void {
    // The root's tree record, spent by another process while the root ran.
    Event::listen(RunStarted::class, function (RunStarted $event): void {
        if ($event->parentRunId === null && ! str_contains($event->sessionKey, '::sub::')) {
            $root = sgRoot();
            $state = PlanBudget::open($event->runId, ['mode' => 'shared', 'max_steps' => 1, 'max_cost_micros' => null]);
            $state['steps'] = 1;
            $root->planStore()->put($event->sessionKey.':run-tree:'.$event->runId, $state);
        }
    });
    $turn = sgToolTurn('Go');

    expect($turn['outputs'])->toContain('"outcome":"exhausted"')
        ->and($turn['requests'])->toBe(2)
        ->and($turn['after'])->toBe($turn['before'])
        ->and(sgChildRecords())->toBe([]);
});

// The stops of the run doing the delegating also stop that run, so the child's
// records (and the run tree's) are what is compared.
it('refuses through the tool, writing nothing, a start under a stopped host context', function (string $stop): void {
    config()->set('prism-harness.agent.max_output_tokens_per_call', 1000);
    config()->set('prism-harness.pricing.openai.gpt-4o', ['inputPerMTok' => 2.5, 'outputPerMTok' => 10.0, 'toolOverheadTokens' => 300]);
    app()->forgetInstance(AgentRuntime::class);
    $context = RunContext::root('host_run', match ($stop) {
        'time' => new RunBudget(10, maxSeconds: 100),
        'cost', 'cost_unenforceable' => new RunBudget(10, maxCostUsd: 5.0, maxOutputTokensPerCall: 1000),
        'tool_calls' => new RunBudget(10, maxToolCalls: 1),
        default => new RunBudget(10),
    });
    $before = sgSnapshot(true);
    try {
        sgToolTurn('Go', $context, fn () => match ($stop) {
            'cancelled' => $context->ledger->cancel('SECRET operator note'),
            'time' => sgAge($context->ledger, 200),
            'cost' => $context->ledger->recordCost(6.0),
            'cost_unenforceable' => $context->ledger->recordCost(null),
            'tool_calls' => $context->ledger->recordToolCall(),
        });
    } catch (RunNotPermitted) {
    }
    G360::$onRequest = null;

    expect(sgRequests())->toBe(1)
        ->and(sgSnapshot(true))->toBe($before)
        ->and(G360::threadText())->not->toContain('SECRET');
})->with(['cancelled', 'time', 'cost', 'cost_unenforceable', 'tool_calls']);

// ---------------------------------------------------------------------------
// 2. Each stop of the gate, through SubagentRunner::run directly.
// ---------------------------------------------------------------------------

it('refuses through the runner, writing nothing, every stop of the gate', function (string $stop, string $outcome): void {
    if (in_array($stop, ['child_waiting', 'child_busy'], true)) {
        sgParkedChild();
    }
    $budget = match ($stop) {
        'time' => new RunBudget(8, maxSeconds: 100),
        'cost', 'cost_unenforceable' => new RunBudget(8, maxCostUsd: 5.0, maxOutputTokensPerCall: 1000),
        'tool_calls' => new RunBudget(8, maxToolCalls: 1),
        default => new RunBudget(8),
    };
    $context = RunContext::root('run_root', $budget);
    if ($stop === 'too_deep') {
        $context = $context->atDepth(RunContext::MAX_DEPTH - 1);
    }
    match ($stop) {
        'cancelled' => $context->ledger->cancel('SECRET operator note'),
        'time' => sgAge($context->ledger, 200),
        'steps' => $context->ledger->recordSteps(8),
        'cost' => $context->ledger->recordCost(5.0),
        'cost_unenforceable' => $context->ledger->recordCost(null),
        'tool_calls' => $context->ledger->recordToolCall(),
        default => null,
    };
    $task = match ($stop) {
        'task_invalid' => ['not', 'text'],
        'task_blank' => "\u{2060}\u{FEFF} ",
        default => 'Start over',
    };
    $run = fn (): SubagentResult => sgRunner()->run(sgWorker(), sgRoot(), $context, 'run_root', $task);
    $requests = sgRequests();

    if ($stop === 'child_busy') {
        $pending = sgChild()->thread()->pendingApprovalIds();
        sgPad();
        $result = null;
        $before = $after = null;
        G360::$onRequest = function () use (&$result, &$before, &$after, $run): void {
            if ($result === null) {
                $before = sgSnapshot();
                $result = $run();
                $after = sgSnapshot();
            }
        };
        try {
            sgChild()->approve($pending[0]);
        } catch (Throwable) {
        }
        G360::$onRequest = null;
        $requests++;
    } else {
        $before = sgSnapshot();
        $result = $run();
        $after = sgSnapshot();
    }

    sgExpectRefused($result, $outcome);
    expect($after)->toBe($before)
        ->and((string) $result->reason)->not->toContain('SECRET')
        ->and(sgRequests() - $requests)->toBe(0);
})->with([
    'a task that is not text' => ['task_invalid', 'denied'],
    'a blank task' => ['task_blank', 'denied'],
    'too deep' => ['too_deep', 'denied'],
    'cancelled' => ['cancelled', 'cancelled'],
    'time spent' => ['time', 'exhausted'],
    'no step left' => ['steps', 'exhausted'],
    'cost spent' => ['cost', 'exhausted'],
    'cost cannot be metered' => ['cost_unenforceable', 'exhausted'],
    'tool calls spent' => ['tool_calls', 'exhausted'],
    'a turn on the child is live' => ['child_busy', 'denied'],
    'a person can answer the child' => ['child_waiting', 'denied'],
]);

// The parent's thread is never created by a refused start either.
it('creates no parent thread when the runner refuses a start', function (): void {
    $result = sgRunner()->run(sgWorker(), sgRoot(), RunContext::root('run_root', new RunBudget(8))->atDepth(RunContext::MAX_DEPTH - 1),
        'run_root', 'Go');

    expect($result->outcome->value)->toBe('denied')
        ->and(DB::table('harness_threads')->count())->toBe(0)
        ->and(DB::table('harness_session_state')->count())->toBe(0);
});

// The cancel reason is the host's text: it never reaches the parent model.
it('reports a cancelled start with harness-written text only', function (): void {
    $context = RunContext::root('run_root', new RunBudget(8));
    $context->ledger->cancel('SECRET operator note');

    $result = sgRunner()->run(sgWorker(), sgRoot(), $context, 'run_root', 'Go');

    expect($result->outcome->value)->toBe('cancelled')
        ->and((string) $result->reason)->not->toContain('SECRET');
});

// ---------------------------------------------------------------------------
// 3. The commit's races: a hold or a claim that lands between the read and
// the write it races refuses the start with the child's records unchanged.
// ---------------------------------------------------------------------------

/**
 * Run `$then` once, right after the first query that reads or writes a key
 * containing `$needle` (or, for a `/regex/`, matching it).
 */
function sgAfterQuery(string $needle, Closure $then): void
{
    $fired = false;
    DB::listen(function (QueryExecuted $query) use (&$fired, $needle, $then): void {
        $hit = false;
        foreach ($query->bindings as $binding) {
            $binding = (string) $binding;
            $hit = $hit || (str_starts_with($needle, '/') ? preg_match($needle, $binding) === 1 : str_contains($binding, $needle));
        }
        if ($fired || ! $hit) {
            return;
        }
        $fired = true;
        $then();
    });
}

it('refuses a start whose tree was spent between the gate read and the hold, writing nothing on the child', function (): void {
    sgParkedChild();
    sgUnanswerable();
    $records = sgChildRecords();
    $tree = null;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$tree): void {
        if ($event->parentRunId === null && ! str_contains($event->sessionKey, '::sub::') && $tree === null) {
            $tree = $event->sessionKey.':run-tree:'.$event->runId;
        }
    });
    $spent = null;
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Handled.');
    sgPad();
    G360::$onRequest = function (int $n) use (&$tree, &$spent): void {
        if ($spent !== null) {
            return;
        }
        $spent = PlanBudget::open(substr((string) $tree, strrpos((string) $tree, ':') + 1), ['mode' => 'shared', 'max_steps' => 1, 'max_cost_micros' => null]);
        $spent['steps'] = 1;
        // Another process spends the tree after this process read it.
        // The record itself, not its cancel flag, which the root's own checks read.
        sgAfterQuery('/:run-tree:run_[0-9a-f]+\z/', function () use ($tree, $spent): void {
            sgRoot()->planStore()->put((string) $tree, $spent);
        });
    };
    sgRoot()->send('Go');
    G360::$onRequest = null;

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"exhausted"')
        ->and(sgChildRecords())->toBe($records)
        // The tree record is what the other process wrote: no hold was taken.
        ->and(sgRoot()->planStore()->get((string) $tree))->toBe($spent);
});

it('refuses a start when a resume claims the child between the gate read and the child write, releasing the tree hold', function (): void {
    sgParkedChild();
    sgUnanswerable();
    $records = sgChildRecords();
    $tree = null;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$tree): void {
        if ($event->parentRunId === null && ! str_contains($event->sessionKey, '::sub::') && $tree === null) {
            $tree = $event->sessionKey.':run-tree:'.$event->runId;
        }
    });
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Handled.');
    sgPad();
    $armed = false;
    G360::$onRequest = function () use (&$armed): void {
        if ($armed) {
            return;
        }
        $armed = true;
        sgAfterQuery(':delegation-steps', function (): void {
            app(RunTrees::class)->claimDelegation(sgChild(), 'run_sneak', RunLedger::start('sneak'), fn (int $used): int => 1);
        });
    };
    sgRoot()->send('Go');
    G360::$onRequest = null;

    $count = sgChild()->planStore()->get(sgChild()->key().':delegation-steps');
    expect(implode(' ', G360::outputs()))->toContain('"outcome":"denied"')
        ->and(implode(' ', G360::outputs()))->toContain('already running')
        ->and($count['holds'])->toHaveKey('run_sneak')
        ->and(array_diff_key(sgChildRecords(), array_flip(array_filter(array_keys($records), fn (string $k): bool => str_contains($k, 'delegation-steps')))))
        ->toBe(array_diff_key($records, array_flip(array_filter(array_keys($records), fn (string $k): bool => str_contains($k, 'delegation-steps')))))
        // The root's hold on its tree, taken for this start, was released.
        ->and(sgRoot()->planStore()->get((string) $tree))->toBeNull();
});

// ---------------------------------------------------------------------------
// 4. Once committed, a later stop only stops model steps.
// ---------------------------------------------------------------------------

it('lets a committed start that is then cancelled take no model step and record its task', function (): void {
    $root = null;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$root): void {
        if ($event->parentRunId === null && ! str_contains($event->sessionKey, '::sub::')) {
            $root ??= $event->runId;
        }
    });
    G360::calls([['worker', ['task' => 'Summarise the report']]]);
    G360::text('Handled.');
    sgPad();
    $armed = false;
    G360::$onRequest = function () use (&$armed, &$root): void {
        if ($armed) {
            return;
        }
        $armed = true;
        // The person stops the root right after the child's run began.
        sgAfterQuery('"status":"running"', function () use (&$root): void {
            sgRoot()->cancel((string) $root);
        });
    };
    try {
        sgRoot()->send('Go');
    } catch (RunNotPermitted) {
    }
    G360::$onRequest = null;

    expect(sgRequests())->toBe(1)
        ->and(sgChild()->thread()->storedMessages()->where('type', 'user')->count())->toBe(1)
        ->and(G360::threadText())->toContain('Summarise the report')
        ->and(sgChild()->run()['status'] ?? null)->toBe('cancelled');
});

// ---------------------------------------------------------------------------
// 5. New channels the gate could open.
// ---------------------------------------------------------------------------

// A fact read that throws fails CLOSED: framed `failed`, class name only,
// nothing written.
it('fails closed, writing nothing, when a gate read throws', function (string $needle): void {
    $turn = null;
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Handled.');
    sgPad();
    $before = $after = null;
    G360::$onRequest = function (int $n) use (&$before, &$after, $needle): void {
        if ($before === null) {
            $before = sgSnapshot();
            sgAfterQuery($needle, function (): void {
                throw new RuntimeException('SECRET-host=db.internal key=sk-live-123');
            });
        } elseif ($after === null) {
            $after = sgSnapshot();
        }
    };
    sgRoot()->send('Go');
    G360::$onRequest = null;

    expect(implode(' ', G360::outputs()))->toContain('failed with')
        ->and(sgSent())->not->toContain('SECRET-host')
        ->and(sgRequests())->toBe(2)
        ->and($after)->toBe($before);
})->with(['the tree record' => ['/:run-tree:run_[0-9a-f]+\z/'], 'the child count' => [':delegation-steps'], 'the child binding' => ['::sub::worker:child']]);

// A start into a child whose only pending approval nobody can answer is a
// committed start: the child runs, and that approval is superseded.
it('starts a child whose pending approval nobody can answer, and runs it', function (): void {
    sgParkedChild();
    sgUnanswerable();
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Done again.');
    G360::text('Handled.');
    $before = sgRequests();

    sgRoot()->send('Go');

    expect(sgRequests() - $before)->toBe(3)
        ->and(implode(' ', G360::outputs()))->toContain('"outcome":"completed"');
});

// The tool checks the host context it was wired with: a host cancel of that
// context stops the model-driven path too (folded into the gate's facts).
it('refuses a start when the wired host context is cancelled, even if the child context is not', function (): void {
    $context = RunContext::root('host_run', new RunBudget(10));
    $records = sgChildRecords();
    try {
        sgToolTurn('Go', $context, fn () => $context->ledger->cancel('stop'));
    } catch (RunNotPermitted) {
    }
    G360::$onRequest = null;

    expect(sgRequests())->toBe(1)->and(sgChildRecords())->toBe($records);
});

// A refused start then a committed one in the same turn: the tree hold the
// refused one released is not released again by the one that commits, and
// the turn's tree record ends settled.
it('settles the tree once after a refused start and a committed one in the same turn', function (): void {
    $tree = null;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$tree): void {
        if ($event->parentRunId === null && ! str_contains($event->sessionKey, '::sub::') && $tree === null) {
            $tree = $event->sessionKey.':run-tree:'.$event->runId;
        }
    });
    G360::calls([['worker', ['task' => ' ']]]);
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Child done.');
    G360::text('Handled.');

    sgRoot()->send('Go');

    $record = sgRoot()->planStore()->get((string) $tree);
    expect($record['holds'] ?? null)->toBe([])
        ->and($record['steps'] ?? null)->toBe(4)
        ->and(sgRequests())->toBe(4);
});

// ---------------------------------------------------------------------------
// 6. Review round 8 (G-387): narrowed from what was read; (b) undone until
// the task row; pending re-checked under the lock; no foreign text; only a
// start's own hold released; answerability reads fail closed; budgets that
// cannot bound anything refused; exception text kept out of run records.
// ---------------------------------------------------------------------------

/**
 * The child's records a start commits: its binding record, its count, its
 * thread and its rows. Not its run state and run history (the ephemeral
 * state and the per-run tree record), which name the run that was tried;
 * the mode slot in the ephemeral state is compared on its own.
 */
function sgDurable(): array
{
    return array_filter(sgChildRecords(), fn (string $key): bool => ! str_ends_with($key, ':ephemeral') && ! str_ends_with($key, ':run-trees'),
        ARRAY_FILTER_USE_KEY);
}

/** The child's mode slot, raw: absent, or the value written. */
function sgMode(): mixed
{
    $row = DB::table('harness_session_state')->where('key', sgChild()->key().':ephemeral')->first();
    $state = $row === null ? [] : (json_decode((string) $row->payload, true) ?? []);

    return is_array($state) && array_key_exists('mode', $state) ? $state['mode'] : '(absent)';
}

/** A parked child whose saved binding is gone: its approval is pending and nobody can answer it. */
function sgDeadChild(): string
{
    sgParkedChild();
    DB::table('harness_session_state')->where('key', sgChild()->key().':child')->delete();
    $pending = sgChild()->thread()->pendingApprovalIds();
    $code = 'accepted';
    try {
        sgChild()->approve($pending[0]);
    } catch (RunNotPermitted $e) {
        $code = $e->code();
    }
    expect($code)->toBe('child_binding_missing');

    return $pending[0];
}

/** A tool provider that throws for the named child while `$on` is true. */
function sgFlakyRegistry(bool &$on, string $child = '::sub::worker'): void
{
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$on, $child): array {
        if ($on && str_contains($session->key(), $child)) {
            throw new RuntimeException('SECRET registry failure');
        }

        return [];
    }, name: 'flaky'.md5($child));
}

/** Abort the child's run-begin write (its run state turning `running`). */
function sgFailRunBegin(): void
{
    foreach (['INSERT', 'UPDATE'] as $op) {
        DB::statement("CREATE TRIGGER sg_begin_{$op} BEFORE {$op} ON harness_session_state
            WHEN NEW.key LIKE '%::sub::worker:ephemeral' AND NEW.payload LIKE '%\"status\":\"running\"%'
            BEGIN SELECT RAISE(ABORT, 'SECRET trigger text'); END");
    }
}

function sgUnfailRunBegin(): void
{
    DB::statement('DROP TRIGGER IF EXISTS sg_begin_INSERT');
    DB::statement('DROP TRIGGER IF EXISTS sg_begin_UPDATE');
}

// Item 1: a runner start is narrowed to the ledger steps the gate READ plus
// its allowance; steps the context spends after the read count against it.
it('narrows a runner start from the steps the gate read, so steps spent after the read count', function (): void {
    $context = RunContext::root('run_root', new RunBudget(8));
    $context->ledger->recordSteps(5);
    G360::text('Done.');
    G360::text('Done.');
    G360::text('Done.');
    $armed = false;
    // Between the read and the narrowing: as the child's run begins.
    sgAfterQuery('"status":"running"', function () use ($context, &$armed): void {
        $armed = true;
        $context->ledger->recordSteps(3);
    });
    $before = sgRequests();

    sgRunner()->run(sgWorker(3), sgRoot(), $context, 'run_root', 'Go');

    expect($armed)->toBeTrue()
        ->and(sgRequests() - $before)->toBe(0)
        ->and($context->ledger->steps())->toBe(8);
});

// Item 2: a failure after commit (b) and before the task row is recorded
// undoes (b): a dead approval stays dead, no hold stays live, and the child's
// records are what they were.
it('undoes commit (b) when the start fails after it, keeping a dead approval dead', function (string $where): void {
    $on = false;
    sgFlakyRegistry($on);
    $dead = sgDeadChild();
    $records = sgDurable();
    $mode = sgMode();
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Handled.');
    sgPad(3);
    if ($where === 'tool registry') {
        $on = true;
    } else {
        sgFailRunBegin();
    }

    sgRoot()->send('Go');
    $on = false;
    sgUnfailRunBegin();

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and(implode(' ', G360::outputs()))->not->toContain('SECRET')
        ->and(sgDurable())->toBe($records)
        ->and(sgMode())->toBe($mode);
    $code = 'accepted';
    try {
        sgChild()->approve($dead);
    } catch (RunNotPermitted $e) {
        $code = $e->code();
    }
    expect($code)->toBe('child_binding_missing');

    // No live hold: the next delegation runs the child.
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Again']]]);
    G360::text('Done again.');
    G360::text('Handled.');
    sgRoot()->send('Go again');
    expect(implode(' ', G360::outputs()))->toContain('"outcome":"completed"');
})->with(['tool registry', 'run begin']);

it('undoes commit (b) on a fresh child when the start fails after it', function (string $where): void {
    $on = false;
    sgFlakyRegistry($on);
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Handled.');
    sgPad(3);
    if ($where === 'tool registry') {
        $on = true;
    } else {
        sgFailRunBegin();
    }

    sgRoot()->send('Go');
    $on = false;
    sgUnfailRunBegin();

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and(sgChild()->childBinding())->toBeNull()
        ->and(sgChild()->childDelegation())->toBeNull()
        ->and(sgMode())->toBe('(absent)')
        ->and(DB::table('harness_session_state')->where('key', sgChild()->key().':delegation-steps')->exists())->toBeFalse();
})->with(['tool registry', 'run begin']);

// Item 3: a host turn that parks an approval a person can answer between the
// gate read and commit (b) refuses the start: the approval stays pending.
it('re-checks the child\'s pending approvals under the lock before commit (b)', function (): void {
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Done.');
    G360::text('Asked.');
    sgRoot()->send('Go');
    expect(sgChild()->thread()->pendingApprovalIds())->toBe([]);

    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::calls([['weather', ['city' => 'Paris']]]);
    G360::text('Handled.');
    for ($i = 0; $i < 4; $i++) {
        G360::text('Pad.');
    }
    $records = null;
    $armed = false;
    $key = sgChild()->key();
    G360::$onRequest = function () use (&$armed, &$records, $key): void {
        if ($armed) {
            return;
        }
        $armed = true;
        // Another process: a host turn on the child takes its lock first.
        sgAfterQuery('lock:'.$key, function () use (&$records): void {
            sgChild()->send('Check the weather');
            $records = sgDurable();
        });
    };
    sgRoot()->send('Go');
    G360::$onRequest = null;

    $pending = sgChild()->thread()->pendingApprovalIds();
    expect($records)->not->toBeNull()
        ->and($pending)->toHaveCount(1)
        ->and(sgChild()->approvalStatus($pending[0]))->toBe('pending')
        ->and(implode(' ', G360::outputs()))->toContain('"outcome":"denied"')
        ->and(implode(' ', G360::outputs()))->toContain('waiting on a person')
        ->and(sgDurable())->toBe($records)
        ->and($this->ran['weather'])->toBe(0);
});

// Item 4: a host's cancel reason never reaches the parent model, landing
// after the child's run began or between the gate and the commit.
it('keeps a host cancel reason out of the parent\'s rows and its next provider request', function (string $when): void {
    $context = RunContext::root('host_run', new RunBudget(10));
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Handled.');
    sgPad(3);
    $armed = false;
    $key = sgChild()->key();
    G360::$onRequest = function () use (&$armed, $context, $when, $key): void {
        if ($armed) {
            return;
        }
        $armed = true;
        sgAfterQuery($when === 'after the run began' ? '"status":"running"' : 'lock:'.$key, function () use ($context): void {
            $context->ledger->cancel('SECRET operator note');
        });
    };
    try {
        sgRoot()->send('Go', context: $context);
    } catch (RunNotPermitted) {
    }
    G360::$onRequest = null;
    $rows = DB::table('harness_thread_messages')->get()->map(fn ($row): string => (string) json_encode($row))->implode("\n");

    G360::$queue = [];
    G360::text('Next answer.');
    G360::text('Next answer.');
    sgRoot()->send('Next question');

    expect($rows)->toContain('worker')
        ->and($rows)->not->toContain('SECRET')
        ->and(sgSent())->not->toContain('SECRET')
        ->and(G360::threadText())->not->toContain('SECRET');
})->with(['after the run began', 'between the gate and the commit']);

it('never builds a refusal message from a host cancel reason', function (): void {
    $ledger = RunLedger::start('run_x');
    $ledger->cancel('SECRET operator note');

    $refusal = $ledger->refusal((string) $ledger->exhaustion(new RunBudget(8)));
    $thrown = null;
    try {
        $ledger->assertCallAvailable(new RunBudget(8));
    } catch (RunNotPermitted $e) {
        $thrown = $e;
    }

    expect($refusal->getMessage())->not->toContain('SECRET')
        ->and((string) $thrown?->getMessage())->not->toContain('SECRET')
        ->and((string) json_encode($ledger->snapshot()))->not->toContain('SECRET')
        // The host's own API still reads its reason.
        ->and($ledger->cancelReason())->toBe('SECRET operator note');
});

// Item 5: a start releases only the hold it took itself.
it('does not release another start\'s tree hold when a re-entrant start fails', function (): void {
    config()->set('prism-harness.agent.modes.chat.subagents.helper', ['description' => 'Help.', 'mode' => 'work', 'max_steps' => 2]);
    $on = true;
    sgFlakyRegistry($on, '::sub::helper');
    $observed = null;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$observed): void {
        if ($observed !== null || ! str_contains($event->sessionKey, '::sub::worker')) {
            return;
        }
        $root = (string) $event->rootRunId;
        $trees = app(RunTrees::class);
        $ledger = (fn () => $this->members[$root]['ledger'])->call($trees);
        $tree = sgRoot()->key().':run-tree:'.$root;
        $before = sgRoot()->planStore()->get($tree);
        $result = sgRunner()->run(new Subagent('helper', 'Help.', 'work', new RunBudget(2)), sgRoot(),
            new RunContext($ledger, new RunBudget(8)), $root, 'Help');
        $observed = ['result' => $result->outcome->value, 'before' => $before, 'after' => sgRoot()->planStore()->get($tree), 'root' => $root];
    });
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Child done.');
    G360::text('Handled.');

    sgRoot()->send('Go');

    expect($observed['result'])->toBe('failed')
        ->and($observed['before']['holds'] ?? [])->toHaveKey($observed['root'])
        ->and($observed['after'])->toBe($observed['before']);
});

// Item 6: an answerability read that fails with any refusal other than the
// known unanswerable ones refuses the start (failed), never reads as
// "nobody can answer", which would let the start end a person's approval.
it('fails closed when the answerability read throws an unexpected refusal', function (): void {
    sgParkedChild();
    $pending = sgChild()->thread()->pendingApprovalIds();
    $records = sgDurable();
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Handled.');
    sgPad(3);
    $armed = false;
    G360::$onRequest = function () use (&$armed): void {
        if ($armed) {
            return;
        }
        $armed = true;
        // After the busy read (its count, then the child's delegation id),
        // the next read of the child's binding is the answerability check's.
        sgAfterQuery(':delegation-steps', function (): void {
            $seen = 0;
            DB::listen(function (QueryExecuted $query) use (&$seen): void {
                if (! str_contains(implode(' ', array_map('strval', $query->bindings)), '::sub::worker:child') || ++$seen !== 2) {
                    return;
                }
                throw RunNotPermitted::exhausted('SECRET store text');
            });
        });
    };
    try {
        sgRoot()->send('Go');
    } catch (Throwable) {
    }
    G360::$onRequest = null;

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and(implode(' ', G360::outputs()))->not->toContain('SECRET')
        ->and(sgChild()->approvalStatus($pending[0]))->toBe('pending')
        ->and(sgDurable())->toBe($records);
});

// Item 7: a budget that cannot bound anything is refused where a host builds it.
it('refuses a run budget whose cost or time limit is not a finite non-negative number', function (Closure $build): void {
    expect($build)->toThrow(InvalidArgumentException::class);
})->with([
    'cost NaN' => [fn () => new RunBudget(8, maxCostUsd: NAN)],
    'cost infinite' => [fn () => new RunBudget(8, maxCostUsd: INF)],
    'cost negative' => [fn () => new RunBudget(8, maxCostUsd: -1.0)],
    'seconds negative' => [fn () => new RunBudget(8, maxSeconds: -1)],
    'config cost infinite' => [fn () => RunBudget::fromArray(['max_cost_usd' => INF])],
    'config cost negative' => [fn () => RunBudget::fromArray(['max_cost_usd' => '-2'])],
    'config seconds negative' => [fn () => RunBudget::fromArray(['max_seconds' => -5])],
    'config seconds not whole' => [fn () => RunBudget::fromArray(['max_seconds' => 1.5])],
    'config seconds overflow' => [fn () => RunBudget::fromArray(['max_seconds' => '1e999'])],
]);

// Item 8: exception text reaches no RunFailed event, run row, run state or
// run history entry, with content capture off or on: the harness never
// writes it; the class name stands for it.
it('keeps exception text out of RunFailed and the run records, whatever the capture setting', function (bool $capture): void {
    config()->set('prism.telemetry.capture_content', $capture);
    $events = [];
    Event::listen(RunFailed::class, function (RunFailed $event) use (&$events): void {
        $events[] = serialize($event);
    });
    // The child's provider call throws; then the root's own.
    foreach ([2, 1] as $failing) {
        G360::$queue = [];
        G360::calls([['worker', ['task' => 'Go']]]);
        G360::text('Handled.');
        $n = 0;
        G360::$onRequest = function () use (&$n, $failing): void {
            if (++$n === $failing) {
                throw new RuntimeException('SECRET exception text');
            }
        };
        try {
            sgRoot()->send('Go');
        } catch (Throwable) {
        }
        G360::$onRequest = null;
    }

    expect($events)->not->toBe([])
        ->and(implode("\n", $events))->not->toContain('SECRET')
        ->and(implode("\n", sgSnapshot()))->not->toContain('SECRET');
})->with(['capture off' => [false], 'capture on' => [true]]);

// New channel: the undo is compare-and-restore. A record a later writer
// changed after (b) is left as that writer left it.
it('never clobbers a later writer when it undoes commit (b)', function (): void {
    sgDeadChild();
    $key = sgChild()->key().':child';
    app(ToolRegistry::class)->registerProvider(function (Session $session) use ($key): array {
        if (str_contains($session->key(), '::sub::worker')) {
            $row = DB::table('harness_session_state')->where('key', $key)->first();
            $payload = json_decode((string) $row->payload, true);
            $payload['child_binding']['delegation'] = 'dlg_later';
            DB::table('harness_session_state')->where('key', $key)->update(['payload' => json_encode($payload)]);

            throw new RuntimeException('boom');
        }

        return [];
    }, name: 'later-writer');
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Handled.');

    sgRoot()->send('Go');

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        // As stored: the start's record is provisional with no task row,
        // so a reader sees none; the undo left the later write in place.
        ->and(sgRawChild()['child_binding']['delegation'] ?? null)->toBe('dlg_later')
        ->and(sgChild()->childDelegation())->toBeNull();
});

// New channel: once the task row is recorded the delegation happened, and
// a later failure does not undo it.
it('keeps a start whose run failed after its task row was recorded', function (): void {
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::calls([['echo', ['text' => 'hi']]]);
    G360::text('Handled.');
    $n = 0;
    G360::$onRequest = function () use (&$n): void {
        if (++$n === 3) {
            throw new RuntimeException('provider down');
        }
    };
    sgRoot()->send('Go');
    G360::$onRequest = null;

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and(sgChild()->childBinding())->not->toBeNull()
        ->and(sgChild()->childDelegation())->not->toBeNull()
        ->and(sgChild()->thread()->storedMessages()->where('type', 'user')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// G-387 round 9: a runner start's records are PROVISIONAL until its task row
// exists in the child's thread. Every reader treats a provisional record whose
// task row is missing as absent, so no second write is needed to undo a
// failed start, and a runner start acts on no recorded decision.
// ---------------------------------------------------------------------------

/** Make every write that would undo the child's binding record fail, as a store outage would. */
function sgFailChildUndo(): void
{
    foreach (['UPDATE', 'DELETE'] as $op) {
        DB::statement("CREATE TRIGGER sg_undo_{$op} BEFORE {$op} ON harness_session_state
            WHEN OLD.key LIKE '%::sub::worker:child'
            BEGIN SELECT RAISE(ABORT, 'SECRET outage'); END");
    }
}

function sgUnfailChildUndo(): void
{
    DB::statement('DROP TRIGGER IF EXISTS sg_undo_UPDATE');
    DB::statement('DROP TRIGGER IF EXISTS sg_undo_DELETE');
}

/** The code a decision on the child is refused with, or `accepted`. */
function sgApproveCode(string $approval): string
{
    try {
        sgChild()->approve($approval);
    } catch (RunNotPermitted $e) {
        return $e->code();
    } catch (ApprovalNotPending) {
        return 'not_pending';
    }

    return 'accepted';
}

/** The code a host turn on the child is refused with, or `ran`. */
function sgHostTurnCode(string $prompt): string
{
    try {
        sgChild()->send($prompt);
    } catch (RunNotPermitted $e) {
        return $e->code();
    }

    return 'ran';
}

/** The raw binding record of the child, as stored. */
function sgRawChild(): ?array
{
    $row = DB::table('harness_session_state')->where('key', sgChild()->key().':child')->first();

    return $row === null ? null : json_decode((string) $row->payload, true);
}

/** Rewrite the child's stored binding record. */
function sgWriteChild(Closure $edit): void
{
    $key = sgChild()->key().':child';
    $payload = $edit(sgRawChild());
    DB::table('harness_session_state')->where('key', $key)->update(['payload' => json_encode($payload)]);
}

/** One re-delegation through the subagent tool; returns the tool output text. */
function sgRedelegate(string $task = 'Again'): string
{
    G360::$queue = [];
    G360::calls([['worker', ['task' => $task]]]);
    G360::text('Done again.');
    G360::text('Handled.');
    sgPad(3);
    $before = count(G360::outputs());
    sgRoot()->send('Go again');

    return implode(' ', array_slice(G360::outputs(), $before));
}

// R12-1: the start fails after commit (b) AND its undo fails. No second
// write is needed: the start's records are provisional, and with no task
// row they read as absent.
it('keeps a dead approval dead when a failed start cannot be undone either', function (string $where, string $entry): void {
    $on = false;
    sgFlakyRegistry($on);
    $dead = sgDeadChild();
    if ($where === 'tool registry') {
        $on = true;
    } else {
        sgFailRunBegin();
    }
    sgFailChildUndo();

    if ($entry === 'tool') {
        G360::$queue = [];
        G360::calls([['worker', ['task' => 'Start over']]]);
        G360::text('Handled.');
        sgPad(3);
        sgRoot()->send('Go');
        expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"');
    } else {
        G360::$queue = [];
        sgPad(3);
        $root = 'run_'.str_repeat('a', 24);
        $result = sgRunner()->run(sgWorker(3), sgRoot(), RunContext::root($root, new RunBudget(8)), $root, 'Start over');
        expect($result->outcome->value)->toBe('failed');
    }
    $on = false;
    sgUnfailRunBegin();
    sgUnfailChildUndo();

    // The undo did fail: the start's own record is still stored.
    expect(sgRawChild())->not->toBeNull();
    $requests = sgRequests();
    expect(sgApproveCode($dead))->toBe('child_binding_missing')
        ->and(sgHostTurnCode('Hello'))->toBe('child_binding_missing')
        ->and(sgRequests())->toBe($requests)
        ->and($this->ran['weather'])->toBe(0)
        ->and(sgChild()->childBinding())->toBeNull();

    // Not blocked: the next delegation starts the child.
    expect(sgRedelegate())->toContain('"outcome":"completed"')
        ->and($this->ran['weather'])->toBe(0);
})->with(['tool registry', 'run begin'])->with(['tool', 'runner']);

// R12-2: a decision recorded on the child between commit (b) and its task
// row is never acted on: not by this start, not by the next delegation, not
// by a host resume.
it('never acts on a decision recorded between commit (b) and the task row', function (bool $fails): void {
    $on = false;
    sgFlakyRegistry($on);
    $dead = sgDeadChild();
    $recorded = false;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$recorded, $dead, $fails, &$on): void {
        if ($recorded || ! str_contains($event->sessionKey, '::sub::worker')) {
            return;
        }
        $recorded = true;
        // Another process records a decision in the window.
        $child = sgChild();
        (fn () => $this->recordDecisions([new ToolApprovalResponse($dead, true)]))->call($child);
        $on = $fails;
    });
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Child done.');
    G360::text('Handled.');
    sgPad(3);

    sgRoot()->send('Go');
    $on = false;

    expect($recorded)->toBeTrue()
        ->and($this->ran['weather'])->toBe(0);
    if ($fails) {
        expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
            ->and(sgHostTurnCode(''))->toBe('child_binding_missing');
    }
    expect(sgRedelegate())->toContain('"outcome":"completed"')
        ->and($this->ran['weather'])->toBe(0);
    // And a host resume afterwards runs nothing of it either.
    G360::$queue = [];
    sgPad(3);
    try {
        sgChild()->send('');
    } catch (Throwable) {
    }
    expect($this->ran['weather'])->toBe(0);
})->with(['start succeeds' => [false], 'start fails' => [true]]);

// Item 5 (P3): a person approved while the binding was alive; the binding
// was then lost. A bare resume is refused, and a re-delegation runs nothing
// of it, through the tool and through the runner.
it('never runs on a re-delegation an approval a bare resume would refuse', function (string $entry): void {
    sgParkedChild();
    sgAnswered();
    DB::table('harness_session_state')->where('key', sgChild()->key().':child')->delete();
    expect(sgHostTurnCode(''))->toBe('child_binding_missing');

    if ($entry === 'tool') {
        expect(sgRedelegate())->toContain('"outcome":"completed"');
    } else {
        G360::$queue = [];
        G360::text('Child done.');
        sgPad(3);
        $root = 'run_'.str_repeat('b', 24);
        $result = sgRunner()->run(sgWorker(3), sgRoot(), RunContext::root($root, new RunBudget(8)), $root, 'Start over');
        expect($result->outcome->value)->toBe('completed');
    }
    expect($this->ran['weather'])->toBe(0);
    // The call is closed for good: a later host turn does not run it.
    G360::$queue = [];
    G360::text('Later.');
    sgPad(3);
    sgChild()->send('Later');
    expect($this->ran['weather'])->toBe(0);
})->with(['tool', 'runner']);

// The normal path: a provisional record whose task row exists is the
// record, exactly. A crash right after the task row (no housekeeping clear
// ever runs) changes nothing.
it('reads a provisional record whose task row exists exactly like a confirmed one', function (): void {
    sgParkedChild();
    $raw = sgRawChild();
    $child = sgChild();
    $pending = $child->thread()->pendingApprovalIds();
    $runId = $child->thread()->approvalRunId($pending[0]);

    expect($raw['child_binding']['provisional'] ?? null)->toBe($runId)
        ->and($child->childBinding())->not->toBeNull()
        ->and($child->childDelegation())->not->toBeNull()
        ->and($child->childStepCeiling())->toBe(3);
    G360::text('Done.');
    sgChild()->approve($pending[0]);
    expect($this->ran['weather'])->toBe(1);
});

it('reads a garbage provisional mark as absent, and a record without one as before', function (mixed $mark): void {
    sgParkedChild();
    $pending = sgChild()->thread()->pendingApprovalIds();
    sgWriteChild(function (array $payload) use ($mark): array {
        if ($mark === '(legacy)') {
            unset($payload['child_binding']['provisional']);
        } else {
            $payload['child_binding']['provisional'] = $mark;
        }

        return $payload;
    });

    if ($mark === '(legacy)') {
        expect(sgChild()->childBinding())->not->toBeNull();
        G360::text('Done.');
        sgChild()->approve($pending[0]);
        expect($this->ran['weather'])->toBe(1);

        return;
    }
    $child = sgChild();
    expect($child->childBinding())->toBeNull()
        ->and($child->childDepth())->toBe(0)
        ->and($child->childTree())->toBeNull()
        ->and($child->childPlanLedger())->toBeNull()
        ->and($child->childStepCeiling())->toBeNull()
        ->and($child->childDelegation())->toBeNull()
        ->and(sgApproveCode($pending[0]))->toBe('child_binding_missing')
        ->and($this->ran['weather'])->toBe(0);
})->with([
    'legacy' => ['(legacy)'],
    'a number' => [123],
    'an empty string' => [''],
    'not a run id' => ['garbage'],
    'a list' => [['run_cccccccccccccccccccccccc']],
    'null' => [null],
]);

// New channels: what confirms a provisional record is the task row of THAT
// run, a user row recorded with its run id.
it('confirms a provisional record only by a user row recorded under its run id', function (string $case): void {
    sgParkedChild();
    $child = sgChild();
    $pending = $child->thread()->pendingApprovalIds();
    $parkedRun = $child->thread()->approvalRunId($pending[0]);
    $other = 'run_'.str_repeat('d', 24);
    // A host forging the id of an old task row gains no more than a record
    // with no mark at all, which a host-written record already is.
    $mark = $case === 'an old task row' ? $parkedRun : $other;
    expect($child->thread()->storedMessages()->where('type', 'user')->exists())->toBeTrue();
    sgWriteChild(function (array $payload) use ($mark): array {
        $payload['child_binding']['provisional'] = $mark;

        return $payload;
    });

    if ($case === 'an old task row') {
        expect(sgChild()->childBinding())->not->toBeNull();

        return;
    }
    expect(sgChild()->childBinding())->toBeNull()
        ->and(sgApproveCode($pending[0]))->toBe('child_binding_missing');
})->with(['an old task row', 'another run id']);

it('does not count an assistant or tool row of the run as its task row', function (): void {
    sgParkedChild();
    $child = sgChild();
    $pending = $child->thread()->pendingApprovalIds();
    $runId = $child->thread()->approvalRunId($pending[0]);
    // Only the user row of that run confirms it: remove it, keep the rest.
    $child->thread()->storedMessages()->where('type', 'user')->where('run_id', $runId)->delete();
    expect(sgChild()->thread()->storedMessages()->where('run_id', $runId)->exists())->toBeTrue()
        ->and(sgChild()->childBinding())->toBeNull();
});

// New channel: a decision or host turn on the child racing the start's task
// row (in the window between commit (b) and the task row) runs nothing and
// takes no model step.
it('refuses a decision on the child that races the start\'s task row', function (): void {
    $dead = sgDeadChild();
    $raced = null;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$raced, $dead): void {
        if ($raced !== null || ! str_contains($event->sessionKey, '::sub::worker')) {
            return;
        }
        $requests = sgRequests();
        $outcome = 'ran';
        try {
            sgChild()->approve($dead);
        } catch (Throwable $e) {
            $outcome = $e::class;
        }
        $raced = ['outcome' => $outcome, 'requests' => sgRequests() - $requests];
    });
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Child done.');
    G360::text('Handled.');
    sgPad(3);

    sgRoot()->send('Go');

    expect($raced)->not->toBeNull()
        ->and($raced['outcome'])->not->toBe('ran')
        ->and($raced['requests'])->toBe(0)
        ->and($this->ran['weather'])->toBe(0);
});

// Item 7 (R3): an attempted run with no task row is absent from the run
// state: the parked run can still be cancelled after a failed start.
it('lets a person cancel the parked run after a failed start', function (bool $undoFails): void {
    $on = false;
    sgFlakyRegistry($on);
    $dead = sgDeadChild();
    $parked = sgChild()->thread()->approvalRunId($dead);
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Handled.');
    sgPad(3);
    $on = true;
    if ($undoFails) {
        sgFailChildUndo();
    }

    sgRoot()->send('Go');
    $on = false;
    sgUnfailChildUndo();

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and(sgChild()->run()['id'] ?? null)->toBe($parked)
        ->and(sgChild()->cancel($parked)->value)->toBe('ok')
        ->and(sgChild()->approvalStatus($dead))->toBe('cancelled');
})->with(['undo succeeds' => [false], 'undo fails' => [true]]);

// Item 4: a failure handler cleans up first and reads nothing of the
// exception but its class. A host exception whose code() or context()
// throws still fails the run, emits RunFailed, leaves no live hold and
// keeps a dead approval dead.
it('cleans up a failed start whatever the host exception does when read', function (string $kind): void {
    $failure = match ($kind) {
        'code throws' => new class('SECRET host failure') extends RuntimeException implements HasErrorCode
        {
            public function code(): string
            {
                throw new LogicException('SECRET code getter');
            }
        },
        'context throws' => new class('SECRET host failure') extends RuntimeException
        {
            /** @return array<string, mixed> */
            public function context(): array
            {
                throw new LogicException('SECRET context');
            }
        },
    };
    $dead = sgDeadChild();
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$failure): array {
        if ($failure !== null && str_contains($session->key(), '::sub::worker')) {
            throw $failure;
        }

        return [];
    }, name: 'hostile');
    $events = [];
    Event::listen(RunFailed::class, function (RunFailed $event) use (&$events): void {
        if (str_contains($event->sessionKey, '::sub::worker')) {
            $events[] = $event->exception;
        }
    });
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Handled.');
    sgPad(3);

    sgRoot()->send('Go');
    $failure = null;

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and(implode(' ', G360::outputs()))->not->toContain('SECRET')
        ->and($events)->toHaveCount(1)
        ->and(sgApproveCode($dead))->toBe('child_binding_missing')
        ->and(sgRedelegate())->toContain('"outcome":"completed"');
})->with(['code throws', 'context throws']);

// Item 8: a limit that is present but not a finite non-negative number is
// a configuration error, never "no limit"; a numeric string is its number.
it('refuses a cost or time limit in config that is not a finite non-negative number', function (string $key, mixed $value): void {
    expect(fn () => RunBudget::fromArray([$key => $value]))->toThrow(InvalidArgumentException::class);

    config()->set('prism-harness.agent.'.$key, $value);
    sgFresh();
    G360::text('Done.');
    $requests = sgRequests();
    expect(fn () => sgRoot()->send('Go'))->toThrow(InvalidArgumentException::class)
        ->and(sgRequests())->toBe($requests);
})->with([
    'cost NAN' => ['max_cost_usd', 'NAN'],
    'cost INF' => ['max_cost_usd', 'INF'],
    'cost -INF' => ['max_cost_usd', '-INF'],
    'cost comma' => ['max_cost_usd', '0,50'],
    'cost word' => ['max_cost_usd', 'none'],
    'cost empty' => ['max_cost_usd', ''],
    'cost bool' => ['max_cost_usd', true],
    'seconds NAN' => ['max_seconds', 'NAN'],
    'seconds INF' => ['max_seconds', 'INF'],
    'seconds -INF' => ['max_seconds', '-INF'],
    'seconds comma' => ['max_seconds', '0,50'],
    'seconds word' => ['max_seconds', 'soon'],
]);

it('reads a quoted number in config as that number, never as no limit', function (): void {
    $budget = RunBudget::fromArray(['max_cost_usd' => '0.50', 'max_seconds' => '60']);
    expect($budget->maxCostUsd)->toBe(0.5)
        ->and($budget->maxSeconds)->toBe(60);
});

// ---------------------------------------------------------------------------
// G-387 round 10: the round-9 review findings, one rule each.
// ---------------------------------------------------------------------------

/** A fresh child session object, so nothing cached is read. */
function sgFreshChild(): Session
{
    sgFresh();

    return sgChild();
}

/** Make the root mode able to park on weather itself, after delegating. */
function sgParkingRoot(): void
{
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    app()->forgetInstance(ModeRegistry::class);
    sgFresh();
}

/** The child's raw ephemeral state. */
function sgRawState(): array
{
    $row = DB::table('harness_session_state')->where('key', sgChild()->key().':ephemeral')->first();

    return $row === null ? [] : (json_decode((string) $row->payload, true) ?? []);
}

// Item 1 (Py F1): an unconfirmed record reads as the last CONFIRMED run tree,
// so a host-driven start after a failed start whose undo failed keeps the
// child in the root's tree, and a cancel of that tree still stops it.
it('keeps a child in its confirmed run tree after a failed start whose undo failed', function (): void {
    sgParkingRoot();
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Child done.');
    G360::calls([['weather', ['city' => 'Rome']]]);
    $root = sgRoot()->send('Go');
    $tree = sgFreshChild()->childTree();
    expect($tree)->not->toBeNull()->and($tree['run'])->toBe($root->runId);

    // A direct start under a context the host built fails after (b), and its
    // undo fails too: the outage begins once (b) has written.
    $confirmed = sgRawChild()['child_binding']['provisional'] ?? null;
    $on = true;
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$on): array {
        if ($on && str_contains($session->key(), '::sub::worker')) {
            sgFailChildUndo();

            throw new RuntimeException('provider down');
        }

        return [];
    }, name: 'outage');
    G360::$queue = [];
    sgPad(3);
    $host = 'run_'.str_repeat('e', 24);
    $failed = sgRunner()->run(sgWorker(3), sgRoot(), RunContext::root($host, new RunBudget(8)), $host, 'Start over');
    $on = false;
    sgUnfailChildUndo();
    expect($failed->outcome->value)->toBe('failed')
        ->and(sgRawChild()['child_binding']['provisional'] ?? null)->toBeString()
        ->and(sgRawChild()['child_binding']['provisional'] ?? null)->not->toBe($confirmed)
        ->and(sgFreshChild()->childBinding())->toBeNull()
        ->and(sgFreshChild()->childTree())->toBe($tree);

    // The next host-driven start succeeds and keeps that tree.
    G360::$queue = [];
    G360::text('Done again.');
    $again = sgRunner()->run(sgWorker(3), sgRoot(), RunContext::root($host, new RunBudget(8)), $host, 'Again');
    expect($again->outcome->value)->toBe('completed')
        ->and(sgFreshChild()->childTree())->toBe($tree);

    // A person cancels the root: a host turn on the child is in that tree, so it is stopped.
    expect(sgRoot()->cancel($root->runId)->value)->toBe('ok');
    G360::$queue = [];
    G360::text('Hello back.');
    $requests = sgRequests();
    expect(sgHostTurnCode('Hello'))->toBe('run_cancelled')
        ->and(sgRequests())->toBe($requests);
});

// Item 2 (Py I3): a model must not void a person's decision by delegating.
// A decided approval no resume has acted on yet blocks a start exactly like
// an answerable pending one; a host resume then runs the approved call once.
it('refuses a re-delegation into a child whose person decided an approval no resume acted on', function (string $entry): void {
    sgParkedChild();
    sgAnswered();
    $records = sgDurable();

    if ($entry === 'tool') {
        $out = sgRedelegate('Start over');
        expect($out)->toContain('"outcome":"denied"')->and($out)->toContain('waiting on a person');
    } else {
        G360::$queue = [];
        sgPad(3);
        $root = 'run_'.str_repeat('b', 24);
        $result = sgRunner()->run(sgWorker(3), sgRoot(), RunContext::root($root, new RunBudget(8)), $root, 'Start over');
        expect($result->outcome->value)->toBe('denied')->and((string) $result->reason)->toContain('waiting on a person');
    }
    expect(sgDurable())->toBe($records)
        ->and($this->ran['weather'])->toBe(0);

    // The host resumes: the approved call runs, once.
    G360::$queue = [];
    G360::text('Child done.');
    G360::text('Child done.');
    sgFreshChild()->send('');
    expect($this->ran['weather'])->toBe(1);
    try {
        sgFreshChild()->send('');
    } catch (Throwable) {
    }
    expect($this->ran['weather'])->toBe(1);
})->with(['tool', 'runner']);

it('re-checks decided approvals under the lock before commit (b)', function (): void {
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Done.');
    G360::text('Asked.');
    sgRoot()->send('Go');

    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::calls([['weather', ['city' => 'Paris']]]);
    G360::text('Handled.');
    for ($i = 0; $i < 4; $i++) {
        G360::text('Pad.');
    }
    $records = null;
    $armed = false;
    $key = sgChild()->key();
    G360::$onRequest = function () use (&$armed, &$records, $key): void {
        if ($armed) {
            return;
        }
        $armed = true;
        // Another process: a host turn parks, and a person decides, before (b).
        sgAfterQuery('lock:'.$key, function () use (&$records): void {
            sgChild()->send('Check the weather');
            sgAnswered();
            $records = sgDurable();
        });
    };
    sgRoot()->send('Go');
    G360::$onRequest = null;

    expect($records)->not->toBeNull()
        ->and(implode(' ', G360::outputs()))->toContain('"outcome":"denied"')
        ->and(implode(' ', G360::outputs()))->toContain('waiting on a person')
        ->and(sgDurable())->toBe($records)
        ->and($this->ran['weather'])->toBe(0);
});

// Item 3 (Py I2): the session mode (b) writes is provisional like the rest.
it('reads the mode a failed start wrote as the mode before it', function (bool $undoFails): void {
    sgChild()->usingMode('chat');
    $on = false;
    sgFlakyRegistry($on);
    if ($undoFails) {
        // The undo's mode restore fails: only a write that puts the old mode back.
        foreach (['UPDATE', 'INSERT'] as $op) {
            DB::statement("CREATE TRIGGER sg_mode_{$op} BEFORE {$op} ON harness_session_state
                WHEN NEW.key LIKE '%::sub::worker:ephemeral' AND NEW.payload LIKE '%\"mode\":\"chat\"%'
                AND EXISTS (SELECT 1 FROM harness_session_state WHERE key = NEW.key AND payload LIKE '%\"mode\":\"work\"%')
                BEGIN SELECT RAISE(ABORT, 'SECRET outage'); END");
        }
    }
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Handled.');
    sgPad(3);
    $on = true;
    sgRoot()->send('Go');
    $on = false;
    DB::statement('DROP TRIGGER IF EXISTS sg_mode_UPDATE');
    DB::statement('DROP TRIGGER IF EXISTS sg_mode_INSERT');

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and(sgMode())->toBe($undoFails ? 'work' : 'chat')
        ->and(sgFreshChild()->mode())->toBe('chat');
})->with(['undo succeeds' => [false], 'undo fails' => [true]]);

it('reads the mode a start wrote once its task row exists, and a garbage mode mark as no mode', function (): void {
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Child done.');
    G360::text('Handled.');
    sgRoot()->send('Go');
    expect(sgFreshChild()->mode())->toBe('work');

    $state = sgRawState();
    expect($state)->toHaveKey('mode_provisional');
    $state['mode_provisional'] = 'garbage';
    DB::table('harness_session_state')->where('key', sgChild()->key().':ephemeral')->update(['payload' => json_encode($state)]);
    expect(sgFreshChild()->mode())->toBeNull();

    // A host setting the mode itself is the mode.
    sgFreshChild()->usingMode('chat');
    expect(sgFreshChild()->mode())->toBe('chat')
        ->and(sgRawState())->not->toHaveKey('mode_provisional');
});

// Item 4: an Error (a Throwable that is not an Exception) from host code
// still fails the run, releases the hold and keeps a dead approval dead.
it('cleans up a failed start when host code throws an Error, and frames it by a named class', function (string $kind): void {
    $failure = match ($kind) {
        'TypeError' => new TypeError('SECRET host failure'),
        'an anonymous Error' => new class('SECRET host failure') extends Error {},
    };
    $dead = sgDeadChild();
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$failure): array {
        if ($failure !== null && str_contains($session->key(), '::sub::worker')) {
            throw $failure;
        }

        return [];
    }, name: 'erroring');
    $events = [];
    Event::listen(RunFailed::class, function (RunFailed $event) use (&$events): void {
        if (str_contains($event->sessionKey, '::sub::worker')) {
            $events[] = $event->exception;
        }
    });
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Handled.');
    sgPad(3);

    sgRoot()->send('Go');
    $failure = null;
    $label = $kind === 'TypeError' ? 'TypeError' : 'Error';

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and(implode(' ', G360::outputs()))->not->toContain('SECRET')
        ->and(implode(' ', G360::outputs()))->toContain('failed with '.$label.'.')
        ->and($events)->toBe([$label])
        ->and(sgApproveCode($dead))->toBe('child_binding_missing')
        ->and(sgRedelegate())->toContain('"outcome":"completed"');
})->with(['TypeError', 'an anonymous Error']);

// Item 5 (TS R13-1): confirmation is a READ, and works in the read-only
// tool-resolution view an agent's tool provider is handed. A provider that
// reads the child's state during a runner start never breaks the start, and
// never manages a write.
function sgAgentSession(string $scope): Session
{
    return app(PrismHarness::class)->forAgent('support')->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session($scope);
}

it('lets an agent tool provider read the child session during a runner start, and never write', function (string $entry): void {
    $modes = app(ModeRegistry::class)->forAgent('support');
    $modes->register('work', ['system_prompt' => 'Work.', 'tools' => ['*'], 'max_steps' => 8], 'support');
    $modes->register('chat', ['tools' => ['worker'], 'max_steps' => 8,
        'subagents' => ['worker' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => 3]]], 'support')->setDefault('chat');
    $reads = [];
    $writes = [];
    app(ToolRegistry::class)->forAgent('support')->registerProvider(function (Session $session) use (&$reads, &$writes): array {
        if (! str_contains($session->scope(), '::sub::worker')) {
            return [];
        }
        $reads[] = [
            'view' => $session->isReadOnlyResolution(),
            'run' => $session->run()['status'] ?? null,
            'binding' => $session->childBinding() !== null,
            'tree' => $session->childTree(),
            'plan' => $session->childPlanLedger(),
            'depth' => $session->childDepth(),
            'ceiling' => $session->childStepCeiling(),
            'delegation' => $session->childDelegation(),
            'linked' => $session->isLinkedChild(),
            'mode' => $session->mode(),
            'approval' => $session->approvalStatus('apr_none'),
        ];
        try {
            $session->usingMode('forged');
            $writes[] = 'written';
        } catch (LogicException) {
            $writes[] = 'refused';
        }

        return [];
    }, 'support', 'reader');

    if ($entry === 'tool') {
        G360::calls([['worker', ['task' => 'Go']]]);
        G360::text('Child done.');
        G360::text('Handled.');
        sgAgentSession('chat')->send('Go');
        expect(implode(' ', G360::outputs()))->toContain('"outcome":"completed"');
    } else {
        G360::text('Child done.');
        $root = 'run_'.str_repeat('c', 24);
        $result = sgRunner()->run(sgWorker(3), sgAgentSession('chat'), RunContext::root($root, new RunBudget(8)), $root, 'Go');
        expect($result->outcome->value)->toBe('completed');
    }
    expect($reads)->not->toBe([])
        ->and(array_column($reads, 'view'))->each->toBeTrue()
        ->and($writes)->each->toBe('refused')
        ->and(sgAgentSession('chat::sub::worker')->mode())->toBe('work');
})->with(['tool', 'runner']);

// The same reads on the legacy (agent-less) path, which hands the provider
// the session itself: a runner start completes.
it('lets a legacy tool provider read the child session during a runner start', function (): void {
    $reads = 0;
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$reads): array {
        if (str_contains($session->key(), '::sub::worker')) {
            $session->run();
            $session->childBinding();
            $session->childTree();
            $session->childStepCeiling();
            $session->isLinkedChild();
            $reads++;
        }

        return [];
    }, name: 'reader');
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Child done.');
    G360::text('Handled.');
    sgRoot()->send('Go');

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"completed"')->and($reads)->toBeGreaterThan(0);
});

// Item 6 (TS I-4): the denial events for calls a start ends as not run are
// emitted only after those rows are durably recorded.
it('emits the denial of a call a start ended only once its row is recorded', function (bool $storeFails): void {
    sgDeadChild();
    $thread = sgChild()->thread()->getKey();
    $seen = [];
    Event::listen(ToolDenied::class, function (ToolDenied $event) use (&$seen, $thread): void {
        if (str_contains($event->sessionKey, '::sub::worker') && $event->decidedBy === 'policy') {
            $seen[] = ['tool' => $event->tool,
                'recorded' => DB::table('harness_thread_messages')->where('thread_id', $thread)->where('type', 'tool_result')
                    ->where('payload', 'like', '%Not run: the delegation%')->exists()];
        }
    });
    if ($storeFails) {
        DB::statement("CREATE TRIGGER sg_rows_down BEFORE INSERT ON harness_thread_messages
            WHEN NEW.thread_id = {$thread} BEGIN SELECT RAISE(ABORT, 'store down'); END");
    }
    $out = sgRedelegate('Start over');
    DB::statement('DROP TRIGGER IF EXISTS sg_rows_down');

    if ($storeFails) {
        expect($out)->toContain('"outcome":"failed"')->and($seen)->toBe([]);
    } else {
        expect($out)->toContain('"outcome":"completed"')->and($seen)->toBe([['tool' => 'weather', 'recorded' => true]]);
    }
    expect($this->ran['weather'])->toBe(0);
})->with(['the rows are recorded' => [false], 'the store fails' => [true]]);

// Item 7 (PHP run12 Low): a framed failure, RunFailed and the failed run row
// name a NAMED class: never an anonymous class's server path and line.
it('names an anonymous host exception by its parent class, never its source path', function (): void {
    $failure = new class('SECRET host failure') extends RuntimeException {};
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$failure): array {
        if ($failure !== null && str_contains($session->key(), '::sub::worker')) {
            throw $failure;
        }

        return [];
    }, name: 'anonymous');
    $events = [];
    Event::listen(RunFailed::class, function (RunFailed $event) use (&$events): void {
        $events[] = $event->exception;
    });
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Handled.');
    sgPad(3);
    sgRoot()->send('Go');
    $failure = null;
    $output = implode(' ', G360::outputs());
    $rows = DB::table('harness_thread_messages')->get()->map(fn ($row): string => (string) $row->payload)->implode("\n");
    $run = (string) json_encode(sgRawState()['run'] ?? null);
    G360::$queue = [];
    G360::text('Next.');
    sgRoot()->send('Next question');
    $next = (string) Http::recorded()[count(Http::recorded()) - 1][0]->body();

    expect($output)->toContain('The subagent failed with RuntimeException.')
        ->and($run)->toContain('"failure":"RuntimeException"')
        ->and($events)->toContain('RuntimeException');
    foreach (['tool result' => $output, 'rows' => $rows, 'run row' => $run, 'next request' => $next, 'events' => implode(' ', $events)] as $text) {
        expect($text)->not->toContain('@anonymous')
            ->and($text)->not->toContain('.php')
            ->and($text)->not->toContain("\0")
            ->and($text)->not->toContain('\\u0000')
            ->and($text)->not->toContain('/')
            ->and($text)->not->toContain(basename(__DIR__).'\\')
            ->and($text)->not->toContain('SECRET');
    }
});

// Item 8 (PHP run12 Info 2): a decided call a start ended reads as ended
// (superseded), never `approved`.
it('reads a decided call a start ended as superseded, never approved', function (bool $decided): void {
    sgParkedChild();
    $approval = sgChild()->thread()->pendingApprovalIds()[0];
    if ($decided) {
        sgAnswered();
    }
    // The binding is lost: nobody can act on the call, and a start ends it.
    DB::table('harness_session_state')->where('key', sgChild()->key().':child')->delete();
    expect(sgRedelegate())->toContain('"outcome":"completed"');

    expect(sgFreshChild()->approvalStatus($approval))->toBe('superseded')
        ->and($this->ran['weather'])->toBe(0);
})->with(['decided' => [true], 'never decided' => [false]]);

// New channels (round 10).

// A host-written record with garbage confirmed refs gains nothing: the record
// still reads as absent (every turn on the child is refused), and the refs as none.
it('reads garbage confirmed refs on an unconfirmed record as no refs, and the record as absent', function (mixed $refs): void {
    sgParkedChild();
    $pending = sgChild()->thread()->pendingApprovalIds();
    sgWriteChild(function (array $payload) use ($refs): array {
        $payload['child_binding']['provisional'] = 'run_'.str_repeat('d', 24);
        $payload['child_binding']['confirmed_refs'] = $refs;

        return $payload;
    });

    $child = sgFreshChild();
    expect($child->childBinding())->toBeNull()
        ->and($child->childTree())->toBeNull()
        ->and($child->childPlanLedger())->toBeNull()
        ->and(sgApproveCode($pending[0]))->toBe('child_binding_missing')
        ->and($this->ran['weather'])->toBe(0);
})->with(['text' => ['garbage'], 'a list' => [[1, 2]], 'a forged tree shape' => [['tree' => ['session' => 1]]]]);

// A decided call holds the child only until a resume acts on it (or nobody
// could): once the host resumes it, the next delegation starts the child.
it('lets a re-delegation start a child once a resume acted on the decided call', function (): void {
    sgParkedChild();
    sgAnswered();
    expect(sgRedelegate('Start over'))->toContain('"outcome":"denied"');
    G360::$queue = [];
    G360::text('Child done.');
    sgFreshChild()->send('');
    expect($this->ran['weather'])->toBe(1)
        ->and(sgRedelegate('Start over'))->toContain('"outcome":"completed"')
        ->and($this->ran['weather'])->toBe(1);
});

// The undo puts the mode back only while it is still the start's: a host that
// chose a mode since keeps it.
it('never clobbers a mode a host chose after commit (b) when it undoes a failed start', function (): void {
    app(ToolRegistry::class)->registerProvider(function (Session $session): array {
        if (str_contains($session->key(), '::sub::worker') && ! $session->isReadOnlyResolution()) {
            $session->usingMode('chat');

            throw new RuntimeException('boom');
        }

        return [];
    }, name: 'mode-writer');
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Handled.');
    sgPad(3);

    sgRoot()->send('Go');

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and(sgMode())->toBe('chat')
        ->and(sgFreshChild()->mode())->toBe('chat');
});

// ---------------------------------------------------------------------------
// Round 11: the round-10 review findings.
// ---------------------------------------------------------------------------

/** The scripted model's last id: the next call or message takes the one after it. */
function sgCallId(): int
{
    return Closure::bind(static fn (): int => self::$call, null, G360::class)();
}

/** Make the next scripted call reuse an earlier id, as a provider that names ids by function does. */
function sgRewindIds(int $to): void
{
    Closure::bind(static function () use ($to): void {
        self::$call = $to;
    }, null, G360::class)();
}

/**
 * The work mode lists `danger` and `probe`; a legacy host provider offers
 * `danger` only to a session whose mode is NOT `work`, and a `probe` whose
 * body records what the session it was resolved for reads.
 *
 * @param  list<array<string, mixed>>  $seen
 */
function sgModeGated(array &$seen, int &$danger): void
{
    config()->set('prism-harness.agent.modes.work.tools', ['weather', 'echo', 'danger', 'probe']);
    config()->set('prism-harness.agent.modes.work.plans', true);
    config()->set('prism-harness.agent.modes.chat.plans', false);
    config()->set('prism-harness.plans.enabled', true);
    app()->forgetInstance(ModeRegistry::class);
    sgFresh();
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$seen, &$danger): array {
        if (! str_contains($session->key(), '::sub::worker')) {
            return [];
        }
        $mode = $session->mode();
        $seen[] = ['where' => 'provider', 'mode' => $mode];
        $tools = [(new Tool)->as('probe')->for('Probe.')->withStringParameter('text', 'Text')
            ->using(function (string $text) use ($session, &$seen): string {
                $outside = sgChild();
                $seen[] = ['where' => 'tool', 'mode' => $session->mode(), 'plans' => app(Plans::class)->enabledFor($session),
                    'binding' => $session->childBinding() !== null, 'ceiling' => $session->childStepCeiling(),
                    'depth' => $session->childDepth(), 'tree' => $session->childTree() !== null,
                    'outside mode' => $outside->mode(), 'outside binding' => $outside->childBinding() !== null];

                return 'ok';
            })];
        if ($mode !== 'work') {
            $tools[] = (new Tool)->as('danger')->for('Danger.')->withStringParameter('text', 'Text')
                ->using(function (string $text) use (&$danger): string {
                    $danger++;

                    return 'done';
                });
        }

        return $tools;
    }, name: 'mode-gated');
}

/** Every tool name offered in any model request so far. */
function sgEverOffered(): array
{
    $names = [];
    foreach (Http::recorded() as $pair) {
        foreach (json_decode((string) $pair[0]->body(), true)['tools'] ?? [] as $tool) {
            $names[] = $tool['name'] ?? null;
        }
    }

    return array_values(array_unique(array_filter($names, 'is_string')));
}

// Item 5 (PHP run13 finding 1, Medium): the run a start begins reads its OWN
// mode, binding, refs, depth and ceiling on its own Session. A host provider
// that gates a tool on Session::mode() never offers it in the start's run;
// a reader OUTSIDE the run keeps the provisional rule.
it('reads the start\'s own state inside its run, so a mode-gated host tool is never offered', function (string $prior, string $entry): void {
    $seen = [];
    $danger = 0;
    sgModeGated($seen, $danger);
    if ($prior === 'chat') {
        sgChild()->usingMode('chat');
    }
    $childScript = function (): void {
        G360::calls([['probe', ['text' => 'look']], ['danger', ['text' => 'now']]]);
        G360::text('Child done.');
    };
    if ($entry === 'tool') {
        G360::calls([['worker', ['task' => 'Go']]]);
        $childScript();
        G360::text('Handled.');
        sgRoot()->send('Go');
    } else {
        $childScript();
        $root = 'run_'.str_repeat('c', 24);
        sgRunner()->run(sgWorker(3), sgRoot(), RunContext::root($root, new RunBudget(8)), $root, 'Go');
    }

    $tool = array_values(array_filter($seen, fn (array $row): bool => $row['where'] === 'tool'));
    expect($danger)->toBe(0)
        ->and(sgEverOffered())->not->toContain('danger')
        ->and(array_column(array_filter($seen, fn (array $row): bool => $row['where'] === 'provider'), 'mode'))->each->toBe('work')
        ->and($tool)->toHaveCount(1)
        ->and($tool[0]['mode'])->toBe('work')
        ->and($tool[0]['plans'])->toBeTrue()
        ->and($tool[0]['binding'])->toBeTrue()
        ->and($tool[0]['ceiling'])->toBe(3)
        ->and($tool[0]['depth'])->toBe(1)
        // In the root's tree through the tool; a host-built context keeps what was saved (none).
        ->and($tool[0]['tree'])->toBe($entry === 'tool')
        // Outside the start's run, its records are still provisional.
        ->and($tool[0]['outside mode'])->toBe($prior === 'chat' ? 'chat' : null)
        ->and($tool[0]['outside binding'])->toBeFalse()
        // And once its task row exists, everyone reads them.
        ->and(sgFreshChild()->mode())->toBe('work')
        ->and(sgFreshChild()->childBinding())->not->toBeNull();
})->with(['a fresh child' => ['none'], 'a child the host had set to chat' => ['chat']])->with(['tool', 'runner']);

// The start's own view ends with its run: the session object (or a view of
// it a host kept) read after a failed start reads the provisional rule.
it('ends the start\'s own view when its run ends, for the session and any view a host kept', function (): void {
    $kept = [];
    $on = true;
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$kept, &$on): array {
        if ($on && str_contains($session->key(), '::sub::worker')) {
            $kept[] = $session;
            $kept[] = [$session->mode(), $session->childBinding() !== null];
            sgFailChildUndo();

            throw new RuntimeException('provider down');
        }

        return [];
    }, name: 'keeper');
    sgChild()->usingMode('chat');
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Handled.');
    sgPad(3);
    sgRoot()->send('Go');
    $on = false;
    sgUnfailChildUndo();

    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and($kept[1])->toBe(['work', true])
        ->and($kept[0]->mode())->toBe('chat')
        ->and($kept[0]->childBinding())->toBeNull()
        ->and(sgFreshChild()->mode())->toBe('chat')
        ->and(sgFreshChild()->childBinding())->toBeNull();
});

// Item 6 (PHP run13 finding 2) and items 1 and 3: "ended by a start" is the
// harness's own mark, scoped to the approval's own request row. A call id
// that repeats (Gemini names ids by function) decides nothing.
it('reads an approved call that ran as approved when its call id repeats one a start ended', function (): void {
    G360::calls([['worker', ['task' => 'Go']]]);
    $reuse = sgCallId();
    G360::calls([['weather', ['city' => 'Paris']]]);
    G360::text('Asked.');
    sgRoot()->send('Go');
    $first = sgChild()->thread()->pendingApprovalIds()[0];
    // The binding is lost: nobody can act on the call, and a start ends it.
    DB::table('harness_session_state')->where('key', sgChild()->key().':child')->delete();
    expect(sgRedelegate())->toContain('"outcome":"completed"')
        ->and(sgFreshChild()->approvalStatus($first))->toBe('superseded');

    // A later delegation: the child asks for weather under the SAME call id.
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Weather again']]]);
    $next = sgCallId();
    sgRewindIds($reuse);
    G360::calls([['weather', ['city' => 'Rome']]]);
    sgRewindIds($next + 10);
    G360::text('Asked.');
    sgRoot()->send('Go');
    $second = sgChild()->thread()->pendingApprovalIds()[0] ?? null;
    expect($second)->toBeString()->not->toBe($first)
        ->and(sgFreshChild()->approvalStatus($second))->toBe('pending');

    G360::$queue = [];
    G360::text('Child done.');
    sgFreshChild()->approve($second);
    expect($this->ran['weather'])->toBe(1)
        ->and(sgFreshChild()->approvalStatus($second))->toBe('approved')
        ->and(sgFreshChild()->approvalStatus($first))->toBe('superseded')
        ->and(sgFreshChild()->thread()->pendingApprovalIds())->toBe([]);
});

it('reads a decided call a start ended as superseded when an earlier call shares its id', function (): void {
    G360::calls([['worker', ['task' => 'Go']]]);
    $reuse = sgCallId();
    G360::calls([['echo', ['text' => 'hi']]]);
    $next = sgCallId();
    sgRewindIds($reuse);
    G360::calls([['weather', ['city' => 'Paris']]]);
    sgRewindIds($next + 10);
    G360::text('Asked.');
    sgRoot()->send('Go');
    expect($this->ran['echo'])->toBe(1);
    $approval = sgChild()->thread()->pendingApprovalIds()[0];
    sgAnswered();
    DB::table('harness_session_state')->where('key', sgChild()->key().':child')->delete();

    expect(sgRedelegate())->toContain('"outcome":"completed"')
        ->and(sgFreshChild()->approvalStatus($approval))->toBe('superseded')
        ->and($this->ran['weather'])->toBe(0);
});

// Item 3 (Py F1, Q10): a plan approval a person gives later, under a call id
// a start once ended, stands: the plan is active, never voided.
it('keeps a later plan approval under a reused call id standing', function (): void {
    config()->set('prism-harness.agent.modes.work.plans', true);
    config()->set('prism-harness.plans.enabled', true);
    app()->forgetInstance(ModeRegistry::class);
    sgFresh();
    G360::calls([['worker', ['task' => 'Go']]]);
    $reuse = sgCallId();
    G360::calls([['weather', ['city' => 'Paris']]]);
    G360::text('Asked.');
    sgRoot()->send('Go');
    DB::table('harness_session_state')->where('key', sgChild()->key().':child')->delete();
    expect(sgRedelegate())->toContain('"outcome":"completed"');

    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Plan it']]]);
    $next = sgCallId();
    sgRewindIds($reuse);
    G360::calls([['plan_draft', ['goal' => 'Plan the trip', 'steps' => G360::steps(2)]]]);
    sgRewindIds($next + 10);
    G360::text('Asked.');
    sgRoot()->send('Go');
    $draft = sgChild()->thread()->pendingApprovalIds()[0] ?? null;
    expect($draft)->toBeString();

    G360::$queue = [];
    G360::text('Plan approved.');
    sgFreshChild()->approve($draft);
    expect(sgFreshChild()->approvalStatus($draft))->toBe('approved')
        ->and(sgFreshChild()->plan()->current()?->toArray()['status'] ?? null)->toBe('active');
});

// Item 1 (TS R14-1): a tool's RESULT never marks its call ended, nor do its
// arguments: a gated call that ran and returned the ended text (or a JSON
// shape naming the mark) reads approved, after a resume and after a runner
// start, also on the path where its result is the last row before the task row.
it('never reads an approved call that ran as ended, whatever its result or arguments say', function (string $text, int $steps): void {
    config()->set('prism-harness.agent.modes.work.requires_approval', ['weather', 'echo']);
    config()->set('prism-harness.agent.modes.chat.subagents.worker.max_steps', $steps);
    app()->forgetInstance(ModeRegistry::class);
    sgFresh();
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::calls([['echo', ['text' => $text]]]);
    G360::text('Asked.');
    sgRoot()->send('Go');
    $approval = sgChild()->thread()->pendingApprovalIds()[0];

    // (a) approved and run in a normal resume.
    G360::$queue = [];
    G360::text('Child done.');
    try {
        sgFreshChild()->approve($approval);
    } catch (RunNotPermitted) {
        // A resume with no step left acts on the decision and stops.
    }
    expect($this->ran['echo'])->toBe(1)
        ->and(sgFreshChild()->approvalStatus($approval))->toBe('approved')
        ->and(sgFreshChild()->thread()->pendingApprovalIds())->toBe([]);

    // (b) then a runner start: still approved, never offered again.
    expect(sgRedelegate())->toContain('"outcome":"completed"')
        ->and(sgFreshChild()->approvalStatus($approval))->toBe('approved')
        ->and(sgFreshChild()->thread()->pendingApprovalIds())->toBe([])
        ->and($this->ran['echo'])->toBe(1)
        ->and(fn () => sgChild()->approve($approval))->toThrow(ApprovalNotPending::class);
    $marked = DB::table('harness_thread_messages')->where('thread_id', sgChild()->thread()->getKey())->get()
        ->filter(fn ($row): bool => array_key_exists(Thread::ENDED_BY_START, json_decode((string) $row->payload, true) ?? []));
    expect($marked)->toHaveCount(0);
})->with([
    'the ended text' => ['Not run: the delegation this call was proposed under ended.'],
    'a JSON object carrying the mark' => ['{"harness_ended_by_start":true}'],
    'a nested metadata shape' => ['{"payload":{"harness_ended_by_start":{"approval_ids":["apr_x"]}},"metadata":{"harness_ended_by_start":true}}'],
])->with(['steps left' => [3], 'no step left' => [1]]);

// Item 2 (TS Info): the task row is the record, read from the store, never an
// in-memory flag an event can race: a denial listener that throws after the
// task row cannot make the start read as not having happened.
it('keeps a start whose task row is recorded when a denial listener throws', function (): void {
    $dead = sgDeadChild();
    Event::listen(ToolDenied::class, function (ToolDenied $event): void {
        if (str_contains($event->sessionKey, '::sub::worker')) {
            throw new RuntimeException('SECRET listener failure');
        }
    });
    $out = sgRedelegate('Start over');

    expect($out)->not->toContain('SECRET')
        ->and(sgFreshChild()->childBinding())->not->toBeNull()
        ->and(sgFreshChild()->mode())->toBe('work')
        ->and(sgFreshChild()->approvalStatus($dead))->toBe('superseded')
        ->and(sgFreshChild()->thread()->storedMessages()->where('type', 'user')->where('payload', 'like', '%Start over%')->exists())->toBeTrue()
        ->and($this->ran['weather'])->toBe(0);
});

// New channel (round 11): the start's own view is the runtime's, for a run
// that is running. A host that names a failed start's run on a session of
// its own confirms nothing: the dead approval stays dead.
it('confirms nothing for a start view on a run that is not running', function (): void {
    $dead = sgDeadChild();
    $on = true;
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$on): array {
        if ($on && str_contains($session->key(), '::sub::worker')) {
            sgFailChildUndo();

            throw new RuntimeException('provider down');
        }

        return [];
    }, name: 'outage');
    expect(sgRedelegate('Start over'))->toContain('"outcome":"failed"');
    $on = false;
    sgUnfailChildUndo();
    $provisional = sgRawChild()['child_binding']['provisional'] ?? null;
    expect($provisional)->toBeString();

    $forged = sgFreshChild();
    $forged->enterStart((string) $provisional);
    $code = 'accepted';
    try {
        $forged->approve($dead);
    } catch (RunNotPermitted $e) {
        $code = $e->code();
    }
    expect($forged->childBinding())->toBeNull()
        ->and($forged->childStepCeiling())->toBeNull()
        ->and($code)->toBe('child_binding_missing')
        ->and($this->ran['weather'])->toBe(0);
});

// ---------------------------------------------------------------------------
// G-387 round 12.
// ---------------------------------------------------------------------------

/** The child's raw run slot, as stored. */
function sgRawRun(): ?array
{
    $row = DB::table('harness_session_state')->where('key', sgChild()->key().':ephemeral')->first();
    $state = $row === null ? [] : (json_decode((string) $row->payload, true) ?? []);

    return is_array($state['run'] ?? null) ? $state['run'] : null;
}

/**
 * Records without the session lock rows: a RunFailed listener runs while
 * the run's own lock is still held, which is not a record of the start.
 *
 * @param  array<string, string>  $records
 * @return array<string, string>
 */
function sgWithoutLocks(array $records): array
{
    return array_filter($records, fn (string $key): bool => ! str_starts_with($key, 'state lock:'), ARRAY_FILTER_USE_KEY);
}

/** A RunStarted listener that throws `$failure` once, for the first run whose session key contains `$on`. */
function sgThrowingStart(string $on, Throwable $failure): void
{
    $armed = true;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$armed, $on, $failure): void {
        if ($armed && str_contains($event->sessionKey, $on)) {
            $armed = false;

            throw $failure;
        }
    });
}

// Item 4 (Py I2) and item 7: an exception that skips the normal handlers
// (in PHP an Error, not an Exception) raised in a RunStarted listener never
// leaves the ROOT run `running`, on any path: the run is failed by class
// name only, RunFailed fires, and the Error propagates. Every run event is
// emitted inside the run's try.
it('fails a root run whose RunStarted listener throws an Error, on every path, and propagates it', function (string $path): void {
    sgThrowingStart('chat', new class('SECRET listener failure') extends Error {});
    $failed = [];
    Event::listen(RunFailed::class, function (RunFailed $event) use (&$failed): void {
        $failed[] = $event->exception;
    });
    G360::text('Hi.');
    G360::text('Again.');
    $schema = new ObjectSchema('doc', 'A document.', [new StringSchema('title', 'The title.')], ['title']);

    $thrown = null;
    try {
        match ($path) {
            'send' => sgRoot()->send('Go'),
            'structured' => sgRoot()->sendStructured('Go', $schema),
            'stream' => iterator_to_array(sgRoot()->streamSteps('Go'), false),
        };
    } catch (Throwable $caught) {
        $thrown = $caught;
    }
    sgFresh();
    $run = sgRoot()->run();

    expect($thrown)->toBeInstanceOf(Error::class)
        ->and($run['status'] ?? null)->toBe('failed')
        ->and(json_encode($run))->not->toContain('SECRET')
        ->and(json_encode($run))->not->toContain('anonymous')
        ->and($failed)->toBe(['Error'])
        ->and(sgRoot()->send('Next')->response->text)->toBe('Hi.');
})->with(['send', 'structured', 'stream']);

// Item 7 (TS F2, probe S8; PHP: a listener throwing an Error): a RunStarted
// listener on the child's run that throws fails the start framed by a named
// class, ends its run with RunFailed, leaves no run row `running`, keeps the
// dead approval dead, and leaves the child free for the next delegation.
it('fails a runner start whose RunStarted listener throws an Error, and leaves the child free', function (string $entry): void {
    $dead = sgDeadChild();
    sgThrowingStart('::sub::worker', new class('SECRET listener failure') extends Error {});
    $failed = [];
    Event::listen(RunFailed::class, function (RunFailed $event) use (&$failed): void {
        if (str_contains($event->sessionKey, '::sub::worker')) {
            $failed[] = $event->exception;
        }
    });

    if ($entry === 'tool') {
        G360::$queue = [];
        G360::calls([['worker', ['task' => 'Start over']]]);
        G360::text('Handled.');
        sgPad(3);
        sgRoot()->send('Go');
        $out = implode(' ', G360::outputs());
        expect($out)->toContain('"outcome":"failed"')
            ->and($out)->toContain('failed with Error.')
            ->and($out)->not->toContain('SECRET');
    } else {
        G360::$queue = [];
        sgPad(3);
        $root = 'run_'.str_repeat('a', 24);
        $result = sgRunner()->run(sgWorker(3), sgRoot(), RunContext::root($root, new RunBudget(8)), $root, 'Start over');
        expect($result->outcome->value)->toBe('failed')
            ->and((string) $result->reason)->not->toContain('SECRET');
    }

    expect($failed)->toBe(['Error'])
        ->and(sgRawRun()['status'] ?? null)->not->toBe('running')
        ->and(sgApproveCode($dead))->toBe('child_binding_missing')
        ->and(sgRedelegate())->toContain('"outcome":"completed"')
        ->and($this->ran['weather'])->toBe(0);
})->with(['tool', 'runner']);

// Item 4 (Py I5): a failed start releases its holds and puts its records
// back BEFORE RunFailed is emitted, so a RunFailed listener never observes
// (or races) a hold that is about to be released.
it('releases a failed start\'s holds before its RunFailed is emitted', function (string $where): void {
    $on = false;
    sgFlakyRegistry($on);
    $dead = sgDeadChild();
    $records = sgDurable();
    $mode = sgMode();
    if ($where === 'a RunStarted listener') {
        sgThrowingStart('::sub::worker', new RuntimeException('SECRET listener failure'));
    }
    $seen = null;
    Event::listen(RunFailed::class, function (RunFailed $event) use (&$seen): void {
        if ($seen === null && str_contains($event->sessionKey, '::sub::worker')) {
            $seen = [
                'tree hold' => app(RunTrees::class)->holds((string) $event->parentRunId),
                'records' => sgDurable(),
                'mode' => sgMode(),
            ];
        }
    });
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Handled.');
    sgPad(3);
    $on = $where === 'the tool registry';

    sgRoot()->send('Go');
    $on = false;

    expect($seen)->not->toBeNull()
        ->and($seen['tree hold'])->toBeFalse()
        ->and(sgWithoutLocks($seen['records']))->toBe(sgWithoutLocks($records))
        ->and($seen['mode'])->toBe($mode)
        ->and(sgApproveCode($dead))->toBe('child_binding_missing')
        ->and(sgRedelegate())->toContain('"outcome":"completed"');
})->with(['the tool registry', 'a RunStarted listener']);

// Item 5 (PHP run14 Info): the start's own view confirms the start's records
// only on the Session object the RUNTIME entered it on. A host that calls
// enterStart() itself, naming a provisional run left `running` in the slot
// (a crash residue), confirms nothing: the dead approval stays dead.
it('confirms nothing for a start view a host sets, even on a provisional run left running', function (): void {
    $dead = sgDeadChild();
    $on = true;
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$on): array {
        if ($on && str_contains($session->key(), '::sub::worker')) {
            sgFailChildUndo();

            throw new RuntimeException('provider down');
        }

        return [];
    }, name: 'outage');
    expect(sgRedelegate('Start over'))->toContain('"outcome":"failed"');
    $on = false;
    sgUnfailChildUndo();
    $provisional = (string) (sgRawChild()['child_binding']['provisional'] ?? '');
    expect($provisional)->not->toBe('');

    // The residue of a crash: the slot still says the start's run is running.
    $key = sgChild()->key().':ephemeral';
    $state = json_decode((string) DB::table('harness_session_state')->where('key', $key)->value('payload'), true);
    $state['run'] = [...$state['run'], 'id' => $provisional, 'status' => 'running'];
    DB::table('harness_session_state')->where('key', $key)->update(['payload' => json_encode($state)]);

    $forged = sgFreshChild();
    $forged->enterStart($provisional);
    $read = [$forged->childBinding(), $forged->childStepCeiling(), $forged->childDelegation()];
    $code = 'accepted';
    try {
        $forged->approve($dead);
    } catch (RunNotPermitted $e) {
        $code = $e->code();
    } catch (Throwable $other) {
        $code = $other::class;
    }

    expect($read)->toBe([null, null, null])
        ->and($code)->toBe('child_binding_missing')
        ->and($this->ran['weather'])->toBe(0);
});

// ---------------------------------------------------------------------------
// G-387 round 13.
// ---------------------------------------------------------------------------

const SG_REPEATED_REASON = 'Tool call was not run because another call in this step has the same id';

/**
 * The child parks on ONE step whose gated weather call shares its id with an
 * ungated echo call, in the order given: a provider that names calls by
 * function (Gemini), or one that sends empty ids. echo runs at step time.
 */
function sgMixedRepeated(string $id, bool $gatedFirst): void
{
    $weather = ['weather', ['city' => 'Paris'], $id];
    $echo = ['echo', ['text' => 'hi'], $id];
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::calls($gatedFirst ? [$weather, $echo] : [$echo, $weather]);
    for ($i = 0; $i < 4; $i++) {
        G360::text('Asked.');
    }
    sgRoot()->send('Go');
}

/** Record a person's decision on the child without resuming it (the window a start can land in). */
function sgDecideOnly(string $approval, bool $approved): void
{
    $child = sgChild();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, $approved)]))->call($child);
}

/**
 * Every recorded decision on the child, by approval id: the first answer and its reason.
 *
 * @return array<string, array{0: bool, 1: ?string}>
 */
function sgRecordedDecisions(): array
{
    $out = [];
    foreach (sgChild()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        foreach ($row->payload['tool_approval_responses'] ?? [] as $response) {
            $out[$response['approval_id']] ??= [$response['approved'], $response['reason'] ?? null];
        }
    }

    return $out;
}

/**
 * The ended marks stored on the child's thread, raw: each marked row's keys and its mark.
 *
 * @return list<array{0: list<string>, 1: mixed}>
 */
function sgEndedMarks(): array
{
    $marks = [];
    foreach (sgChild()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        if (array_key_exists(Thread::ENDED_BY_START, $row->payload)) {
            $marks[] = [array_keys($row->payload), $row->payload[Thread::ENDED_BY_START]];
        }
    }

    return $marks;
}

// Item 3 (Py F1, the PHP reference's rule): a GATED call that shares its id
// with an UNGATED call in the same step. The ungated call's result is ITS
// result, never the gated call's: results are matched to calls by position,
// so the step parks on the gated call's approval. Every decision on such a
// step is recorded as a denial with the G-392 text, and a resume runs nothing.
it('parks a gated call that shares its id with an ungated one and denies every decision on it', function (string $id, bool $gatedFirst): void {
    sgMixedRepeated($id, $gatedFirst);
    $pending = sgChild()->thread()->pendingApprovalIds();
    expect($this->ran)->toBe(['weather' => 0, 'echo' => 1])
        ->and($pending)->toHaveCount(1)
        ->and(array_column(sgChild()->thread()->pendingApprovalCalls(), 'name'))->toBe(['weather']);

    G360::$queue = [];
    G360::text('Child done.');
    G360::text('Pad.');
    sgFreshChild()->approve($pending[0]);

    expect($this->ran)->toBe(['weather' => 0, 'echo' => 1])
        ->and(sgRecordedDecisions())->toBe([$pending[0] => [false, SG_REPEATED_REASON]])
        ->and(sgFreshChild()->approvalStatus($pending[0]))->toBe('denied')
        ->and(sgFreshChild()->thread()->pendingApprovalIds())->toBe([]);
})->with([
    'shared id, gated first' => ['dup', true],
    'shared id, ungated first' => ['dup', false],
    'empty id, gated first' => ['', true],
    'empty id, ungated first' => ['', false],
]);

// Items 1 and 3 together (TS F1 P1, Py F1 P3): a person decides the gated
// call while it is answerable; the child then loses its binding and a start
// ends the call. The start's mark names the approval although an earlier,
// UNMARKED result row carries the same call id: the approval reads ended,
// never approved, and is not offered again.
it('ends a gated call that shares its id with an ungated one when a start lands, and reads it ended', function (string $id, bool $gatedFirst): void {
    sgMixedRepeated($id, $gatedFirst);
    $approval = (string) (sgChild()->thread()->pendingApprovalIds()[0] ?? '');
    expect($approval)->not->toBe('');
    sgDecideOnly($approval, true);
    expect(sgRecordedDecisions())->toBe([$approval => [false, SG_REPEATED_REASON]]);
    DB::table('harness_session_state')->where('key', sgChild()->key().':child')->delete();

    expect(sgRedelegate('Start over'))->toContain('"outcome":"completed"');

    $child = sgFreshChild();
    expect(sgEndedMarks())->toBe([[['tool_results', 'tool_approval_responses', Thread::ENDED_BY_START], ['approval_ids' => [$approval]]]])
        ->and($child->approvalStatus($approval))->toBe('superseded')
        ->and(array_filter($child->thread()->approvalDecisions()))->toBe([])
        ->and($child->thread()->pendingApprovalIds())->toBe([])
        ->and(sgApproveCode($approval))->toBe('not_pending')
        ->and($this->ran)->toBe(['weather' => 0, 'echo' => 1]);
})->with([
    'shared id, gated first' => ['dup', true],
    'shared id, ungated first' => ['dup', false],
    'empty id, gated first' => ['', true],
    'empty id, ungated first' => ['', false],
]);

// Item 1 (TS F1 P1b, Gemini-style): two calls to the SAME tool share an id,
// and one already has a result (its arguments were malformed, so core
// reported it failed at step time). The other is gated.
it('ends the gated one of two same-tool calls sharing an id when one already failed', function (): void {
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::$queue[] = (string) json_encode([
        'id' => 'resp_p1b', 'object' => 'response', 'created_at' => 1741989983, 'status' => 'completed', 'model' => 'gpt-4o-2024-08-06',
        'output' => [
            ['id' => 'w', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'w', 'name' => 'weather'],
            ['id' => 'w', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":', 'call_id' => 'w', 'name' => 'weather'],
        ],
        'usage' => ['input_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 5,
            'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 15],
    ]);
    for ($i = 0; $i < 4; $i++) {
        G360::text('Asked.');
    }
    sgRoot()->send('Go');
    $pending = sgChild()->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1);
    sgDecideOnly($pending[0], true);
    DB::table('harness_session_state')->where('key', sgChild()->key().':child')->delete();

    expect(sgRedelegate('Start over'))->toContain('"outcome":"completed"')
        ->and(sgEndedMarks()[0][1] ?? null)->toBe(['approval_ids' => [$pending[0]]])
        ->and(sgFreshChild()->approvalStatus($pending[0]))->toBe('superseded')
        ->and(sgApproveCode($pending[0]))->toBe('not_pending')
        ->and($this->ran['weather'])->toBe(0);
});

// Item 1, the reading alone, through the writer the harness uses: rows as a
// start leaves them on a step whose call ids repeat. ANY result row after the
// approval's own request and before the next model step that carries the
// harness's mark naming it ends it; a row with no mark, or a mark after the
// next model step, does not.
it('reads an approval ended when any result row of its step carries the mark naming it', function (): void {
    $thread = sgChild()->thread();
    $ended = new ToolResultMessage([new ToolResult('dup', 'weather', ['city' => 'Paris'], 'Not run: the delegation this call was proposed under ended.')]);
    $thread->record([
        new UserMessage('Go'),
        new AssistantMessage('', [new ToolCall('c1', 'weather', ['city' => 'Rome'])], [], [new ToolApprovalRequest('apr_0', 'c1')]),
        new ToolResultMessage([new ToolResult('c1', 'weather', ['city' => 'Rome'], 'Sunny')], [new ToolApprovalResponse('apr_0', true)]),
        new AssistantMessage('', [new ToolCall('dup', 'weather', ['city' => 'Paris']), new ToolCall('dup', 'echo', ['text' => 'hi'])], [],
            [new ToolApprovalRequest('apr_1', 'dup')]),
        new ToolResultMessage([new ToolResult('dup', 'echo', ['text' => 'hi'], 'hi')]),
        new ToolResultMessage([], [new ToolApprovalResponse('apr_1', false, SG_REPEATED_REASON)]),
        $ended,
        new UserMessage('Start over'),
    ], endedByStart: [['message' => $ended, 'approvals' => ['apr_1', 'apr_0']]]);

    expect($thread->endedByStart('apr_1'))->toBeTrue()
        ->and($thread->endedByStart('apr_0'))->toBeFalse()
        ->and($thread->endedByStart('apr_9'))->toBeFalse();
});

// Item 5 (PHP run15, probe ZB): the success path NEVER undoes a start. A
// store read that throws while a completed start is cleaned up only ends the
// start's own view; the binding and the mode stand, and the child's next
// host turn runs. A failure exit after the task row keeps it too.

/**
 * Throw on every "did this run record a row" read for the child's start, from
 * when `$arm` (asked on every query) returns true until the next model request.
 *
 * @param  Closure(string): bool  $arm
 */
function sgFailingTaskRead(Closure $arm, int &$thrown): void
{
    $armed = false;
    $start = null;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$start): void {
        if (str_contains($event->sessionKey, '::sub::worker') && $start === null) {
            $start = $event->runId;
        }
    });
    $checking = false;
    DB::listen(function (QueryExecuted $query) use (&$armed, &$start, &$thrown, &$checking, $arm): void {
        if ($checking || $start === null) {
            return;
        }
        if (! $armed) {
            $checking = true;
            try {
                $armed = $arm($start);
            } finally {
                $checking = false;
            }

            return;
        }
        if (! str_contains($query->sql, 'harness_thread_messages') || ! str_contains($query->sql, 'exists')
            || str_contains($query->sql, '"type"') || ! in_array($start, $query->bindings, true)) {
            return;
        }
        $thrown++;

        throw new RuntimeException('SECRET store outage');
    });
    $previous = G360::$onRequest;
    G360::$onRequest = function (int $n) use (&$armed, $previous): void {
        $armed = false;
        if ($previous !== null) {
            $previous($n);
        }
    };
}

it('keeps a completed start when its cleanup read fails', function (): void {
    $dead = sgDeadChild();
    $finished = false;
    Event::listen(RunFinished::class, function (RunFinished $event) use (&$finished): void {
        if (str_contains($event->sessionKey, '::sub::worker')) {
            $finished = true;
        }
    });
    $thrown = 0;
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::text('Child done.');
    G360::text('Handled.');
    sgPad(3);
    $before = count(G360::outputs());
    sgFailingTaskRead(function () use (&$finished): bool {
        return $finished;
    }, $thrown);
    sgRoot()->send('Go again');
    $out = implode(' ', array_slice(G360::outputs(), $before));
    G360::$onRequest = null;

    G360::$queue = [];
    G360::text('Hello back.');
    expect($out)->toContain('"outcome":"completed"')
        ->and($out)->not->toContain('SECRET')
        ->and(sgFreshChild()->childBinding())->not->toBeNull()
        ->and(sgFreshChild()->mode())->toBe('work')
        ->and(sgFreshChild()->approvalStatus($dead))->toBe('superseded')
        ->and(sgHostTurnCode('Hello'))->toBe('ran')
        ->and($this->ran['weather'])->toBe(0);
});

it('keeps a start that failed after its task row when its cleanup read fails', function (): void {
    $dead = sgDeadChild();
    $thrown = 0;
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    G360::calls([['echo', ['text' => 'step one']]]);
    G360::text('Handled.');
    sgPad(3);
    sgFailingTaskRead(fn (string $start): bool => sgChild()->taskRowExists($start), $thrown);
    // This turn's requests: the root's, the child's first step (echo), then
    // the child's second step, which the provider fails.
    $seen = 0;
    $previous = G360::$onRequest;
    G360::$onRequest = function (int $n) use ($previous, &$seen): void {
        $seen++;
        if ($seen === 3) {
            throw new RuntimeException('provider down');
        }
        $previous($n);
    };
    sgRoot()->send('Go again');
    G360::$onRequest = null;

    G360::$queue = [];
    G360::text('Hello back.');
    expect(implode(' ', G360::outputs()))->toContain('"outcome":"failed"')
        ->and($this->ran['echo'])->toBe(1)
        ->and(sgFreshChild()->childBinding())->not->toBeNull()
        ->and(sgFreshChild()->mode())->toBe('work')
        ->and(sgFreshChild()->approvalStatus($dead))->toBe('superseded')
        ->and(sgHostTurnCode('Hello'))->toBe('ran')
        ->and($this->ran['weather'])->toBe(0);
});

// The other side of the rule: a start that failed BEFORE its task row never
// happened, whether its cleanup read works (it is undone) or throws (its
// records stay unconfirmed, and every reader sees them as absent): a read
// that failed confirms nothing.
it('never confirms a start that failed before its task row, whether or not its cleanup read fails', function (bool $readFails): void {
    $dead = sgDeadChild();
    $on = true;
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$on): array {
        if ($on && str_contains($session->key(), '::sub::worker')) {
            throw new RuntimeException('provider down');
        }

        return [];
    }, name: 'outage');
    $thrown = 0;
    if ($readFails) {
        sgFailingTaskRead(fn (): bool => true, $thrown);
    }
    expect(sgRedelegate('Start over'))->toContain('"outcome":"failed"');
    $on = false;
    G360::$onRequest = null;

    expect(sgFreshChild()->childBinding())->toBeNull()
        ->and(sgApproveCode($dead))->toBe('child_binding_missing')
        ->and(sgHostTurnCode('Hello'))->toBe('child_binding_missing')
        ->and($this->ran['weather'])->toBe(0);
})->with(['read works' => [false], 'read fails' => [true]]);

// Item 4 (Py P5, I2): the object that committed a start cannot re-enter that
// start's view on its own crash residue. In PHP only a view the runtime
// entered confirms anything, and the runtime ends it when the run ends: the
// start's own Session object entering it again by hand confirms nothing.
it('confirms nothing when the start\'s own object re-enters its view on its crash residue', function (): void {
    $dead = sgDeadChild();
    $kept = null;
    $on = true;
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$on, &$kept): array {
        if ($on && str_contains($session->key(), '::sub::worker')) {
            $kept = $session;
            sgFailChildUndo();

            throw new RuntimeException('provider down');
        }

        return [];
    }, name: 'outage');
    expect(sgRedelegate('Start over'))->toContain('"outcome":"failed"');
    $on = false;
    sgUnfailChildUndo();
    $provisional = (string) (sgRawChild()['child_binding']['provisional'] ?? '');
    expect($provisional)->not->toBe('')->and($kept)->toBeInstanceOf(Session::class);
    $key = sgChild()->key().':ephemeral';
    $state = json_decode((string) DB::table('harness_session_state')->where('key', $key)->value('payload'), true);
    $state['run'] = [...$state['run'], 'id' => $provisional, 'status' => 'running'];
    DB::table('harness_session_state')->where('key', $key)->update(['payload' => json_encode($state)]);

    $kept->enterStart($provisional);
    $read = [$kept->childBinding(), $kept->childStepCeiling(), $kept->childDelegation()];
    $code = 'accepted';
    try {
        $kept->approve($dead);
    } catch (RunNotPermitted $e) {
        $code = $e->code();
    } catch (Throwable $other) {
        $code = $other::class;
    }

    expect($read)->toBe([null, null, null])
        ->and($code)->toBe('child_binding_missing')
        ->and($this->ran['weather'])->toBe(0);
});

// Item 4 (Py I3): a run stopped by an Error (PHP's analogue of a Python
// BaseException) is finished through the same path as any other failure:
// Session::finishRun, which writes the run's end and then runs the plan's
// after-turn hook and the run tree's settle. Plans is final, so the hook is
// observed by what it reads: the plan record, after the failed run is written.
it('finishes a run an Error stopped through the path that runs the plan after-turn hook', function (): void {
    config()->set('prism-harness.plans.enabled', true);
    app()->forgetInstance(Plans::class);
    app(ToolRegistry::class)->registerProvider(function (Session $session): array {
        throw new class('SECRET') extends Error {};
    }, name: 'erroring');
    G360::text('Never.');
    $failedWritten = false;
    $planReadAfter = 0;
    DB::listen(function (QueryExecuted $query) use (&$failedWritten, &$planReadAfter): void {
        foreach ($query->bindings as $binding) {
            if (is_string($binding) && str_contains($binding, '"status":"failed"')) {
                $failedWritten = true;
            }
        }
        if ($failedWritten && str_starts_with(strtolower($query->sql), 'select') && str_contains(implode(' ', array_map('strval', $query->bindings)), ':plan')) {
            $planReadAfter++;
        }
    });
    $thrown = null;
    try {
        sgRoot()->send('Go');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(Error::class)
        ->and($failedWritten)->toBeTrue()
        ->and($planReadAfter)->toBeGreaterThan(0)
        ->and(sgRoot()->run()['status'] ?? null)->toBe('failed');
});

// Brief item (Py send_structured AssertionError; PHP checked): a structured
// turn whose FIRST step stops for approval returns the structured response
// type with its pending approvals and no document. It used to reach document
// validation and raise a schema violation over a turn that was waiting on a
// person, so a caller that retries discards what the person was asked about.
it('returns a structured response with its pending approvals when the first step parks', function (): void {
    sgParkingRoot();
    G360::calls([['weather', ['city' => 'Paris']]]);
    G360::text('{"title":"Never read"}');
    $schema = new ObjectSchema('doc', 'A document.', [new StringSchema('title', 'The title.')], ['title']);
    $finished = [];
    Event::listen(RunFinished::class, function (RunFinished $event) use (&$finished): void {
        $finished[] = $event->awaitingApproval;
    });

    $response = sgRoot()->sendStructured('Go', $schema);
    $pending = sgRoot()->thread()->pendingApprovalIds();

    expect($response->awaitingApproval())->toBeTrue()
        ->and(array_map(fn (ToolApprovalRequest $request): string => $request->approvalId, $response->pendingApprovals()))->toBe($pending)
        ->and($pending)->toHaveCount(1)
        ->and($response->structured())->toBe([])
        ->and($finished)->toBe([true])
        ->and($this->ran['weather'])->toBe(0)
        ->and(sgRoot()->run()['status'] ?? null)->toBe('completed');
});
