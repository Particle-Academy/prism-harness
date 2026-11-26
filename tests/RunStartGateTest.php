<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Models\Thread;
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
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
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
    sgAnswered();
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
    sgAnswered();
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
    sgAnswered();
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
        ->and(sgChild()->childDelegation())->toBe('dlg_later');
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
