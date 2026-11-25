<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Enums\CancelOutcome;
use Prism\Harness\Events\RunCancelled;
use Prism\Harness\Events\RunCancelRequested;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\WorkflowBudgetExhausted;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Flow\WorkflowBudgets;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Stores\DatabaseSessionStore;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Tools\CancelGuardedTool;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\Tools\LaravelMcpTool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;
use Tests\Support\G360;

// G-285: a durable, cross-process cancel. Every test drives the public
// session API over Prism's real OpenAI handler on faked HTTP, with stray
// requests refused before the first one. The run executes in the "worker"
// session; the cancel comes from a "web" session built on SEPARATE store
// instances over the same database, as a web request stopping a queued run
// would: nothing in memory is shared between the two.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', []);

    $this->ran = ['search' => 0, 'weather' => 0];
    /** @var array<string, Closure(): void> */
    $this->during = [];
    $ran = &$this->ran;
    $during = &$this->during;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$ran, &$during): string {
                $ran['search']++;
                if (isset($during['search'])) {
                    ($during['search'])();
                }

                return 'Found it';
            }),
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran['weather']++;

                return 'Sunny';
            }),
    ]);

    $this->events = [];
    $events = &$this->events;
    Event::listen([RunCancelRequested::class, RunCancelled::class, RunFinished::class, RunFailed::class, WorkflowBudgetExhausted::class],
        function (object $event) use (&$events): void {
            $events[] = $event;
        });
});

/** The queue worker's session: the one that runs. */
function g285Worker(string $name = 'Ada'): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => $name]))->session('chat');
}

/** Another process's session for the same address: its own store instances, nothing cached. */
function g285Web(string $name = 'Ada', bool $runtime = true): Session
{
    return new Session(Participant::query()->firstOrCreate(['name' => $name]), 'chat',
        new DatabaseSessionStore(DB::connection()), new DatabaseSessionStore(DB::connection()),
        runtime: $runtime ? app(AgentRuntime::class) : null);
}

/** Red by assertion on a build without G-285, rather than by a missing method. */
function g285Cancel(Session $session, string $runId): CancelOutcome
{
    expect(method_exists($session, 'cancel'))->toBeTrue('Session::cancel() is G-285');

    return $session->cancel($runId);
}

/** The run id as another process reads it: from the store, never from the worker's memory. */
function g285RunId(string $name = 'Ada'): string
{
    $id = g285Web($name)->run()['id'] ?? null;
    expect($id)->toBeString();

    return $id;
}

/** @return list<string> */
function g285EventNames(array $events): array
{
    return array_map(fn (object $event): string => class_basename($event), $events);
}

/** Run the worker's turn on a path, returning the refusal it ended with (or null). */
function g285Turn(string $path, string $prompt = 'go'): ?RunNotPermitted
{
    try {
        match ($path) {
            'send' => g285Worker()->send($prompt),
            'streamSteps' => iterator_to_array(g285Worker()->streamSteps($prompt), false),
            'stream' => iterator_to_array(g285Worker()->stream($prompt), false),
        };
    } catch (RunNotPermitted $refused) {
        return $refused;
    }

    return null;
}

it('stops a run from another process at the next tool, and lets the running tool finish', function (string $path): void {
    $outcome = null;
    $this->during['search'] = function () use (&$outcome): void {
        $outcome = g285Cancel(g285Web(), g285RunId());
    };
    G360::calls([['search', ['query' => 'tigers']], ['weather', ['city' => 'Detroit']]]);
    G360::text('This step must never be asked for.');

    $refused = g285Turn($path);

    expect($outcome)->toBe(CancelOutcome::Ok)
        ->and($refused?->code())->toBe('run_cancelled')
        // The in-flight call finished; the next call in the same step did not run.
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 0])
        // And no next model step was requested.
        ->and(Http::recorded())->toHaveCount(1)
        ->and(g285Web()->run()['status'])->toBe('cancelled')
        ->and(G360::threadText())->toContain('Not run: the run was cancelled.')
        ->and(g285EventNames($this->events))->toBe(['RunCancelRequested', 'RunCancelled']);
    [$requested, $cancelled] = $this->events;
    expect($requested->runId)->toBe($cancelled->runId)
        ->and($requested->runId)->toBe(g285Web()->run()['id'])
        ->and($requested->rootRunId)->toBe($requested->runId)
        ->and($requested->sessionKey)->toBe(g285Web()->key())
        ->and($requested->state)->toBe('running');
})->with(['send', 'streamSteps', 'stream']);

it('stops between steps when the cancel lands while the model is thinking', function (string $path): void {
    $outcome = null;
    G360::$onRequest = function (int $request) use (&$outcome): void {
        if ($request === 1) {
            $outcome = g285Cancel(g285Web(), g285RunId());
        }
    };
    G360::calls([['search', ['query' => 'tigers']], ['weather', ['city' => 'Detroit']]]);
    G360::text('This step must never be asked for.');

    $refused = g285Turn($path);

    expect($outcome)->toBe(CancelOutcome::Ok)
        ->and($refused?->code())->toBe('run_cancelled')
        ->and($this->ran)->toBe(['search' => 0, 'weather' => 0])
        ->and(Http::recorded())->toHaveCount(1)
        ->and(g285Web()->run()['status'])->toBe('cancelled')
        ->and(g285EventNames($this->events))->toBe(['RunCancelRequested', 'RunCancelled']);
})->with(['send', 'streamSteps', 'stream']);

it('ends a run cancelled when the cancel races its last step', function (string $path): void {
    $outcome = null;
    G360::$onRequest = function (int $request) use (&$outcome): void {
        if ($request === 2) {
            $outcome = g285Cancel(g285Web(), g285RunId());
        }
    };
    G360::calls([['search', ['query' => 'tigers']]]);
    G360::text('All done.');

    $refused = g285Turn($path);

    // The last step had already been asked for, so its answer is kept; but a
    // cancel that returned ok is never followed by a run that reads completed.
    expect($outcome)->toBe(CancelOutcome::Ok)
        ->and(g285Web()->run()['status'])->toBe('cancelled')
        // The STORED record says so, not only the read of it.
        ->and(g285Web()->state()['run']['status'])->toBe('cancelled')
        ->and(g285Web()->state()['run']['ended_as'])->toBe('completed')
        ->and(G360::threadText())->toContain('All done.')
        ->and(Http::recorded())->toHaveCount(2)
        ->and(g285EventNames($this->events))->toBe(['RunCancelRequested', 'RunCancelled']);
    if ($path === 'send') {
        expect($refused?->code())->toBe('run_cancelled');
    }
})->with(['send', 'streamSteps']);

it('refuses to cancel another session’s run, and that run is untouched', function (): void {
    // Bob has a session, and a run of his own, so an id that is not his
    // reads as what it is: not one of his runs.
    G360::text('Bob is done.');
    g285Worker('Bob')->send('mine');
    $this->events = [];
    $outcome = [];
    $this->during['search'] = function () use (&$outcome): void {
        $outcome[] = g285Cancel(g285Web('Bob'), g285RunId('Ada'));
        // A session with no run at all touches nothing either.
        $outcome[] = g285Cancel(g285Web('Cy'), g285RunId('Ada'));
    };
    G360::calls([['search', ['query' => 'tigers']], ['weather', ['city' => 'Detroit']]]);
    G360::text('Both done.');

    expect(g285Turn('send'))->toBeNull()
        ->and($outcome)->toBe([CancelOutcome::Refused, CancelOutcome::NoTurn])
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 1])
        ->and(g285Web('Ada')->run()['status'])->toBe('completed')
        ->and(g285Web('Bob')->run()['status'])->toBe('completed')
        ->and(g285EventNames($this->events))->toBe(['RunFinished']);
});

it('refuses a forged or malformed run id, whatever the session holds', function (): void {
    $outcome = [];
    $this->during['search'] = function () use (&$outcome): void {
        $real = g285RunId();
        foreach (['run_'.bin2hex(random_bytes(12)), '', 'run_', '../'.$real, strtoupper($real), $real."\n", $real.' ', ' '.$real] as $forged) {
            $outcome[] = g285Cancel(g285Web(), $forged);
        }
    };
    G360::calls([['search', ['query' => 'tigers']]]);
    G360::text('Done.');

    expect(g285Turn('send'))->toBeNull()
        ->and(array_unique(array_map(fn (CancelOutcome $o): string => $o->value, $outcome)))->toBe(['refused'])
        ->and($outcome)->toHaveCount(8)
        ->and(g285Web()->run()['status'])->toBe('completed')
        ->and(g285EventNames($this->events))->toBe(['RunFinished']);
});

it('answers no-turn, not-running and unsupported without changing anything', function (): void {
    expect(g285Cancel(g285Web(), 'run_'.bin2hex(random_bytes(12))))->toBe(CancelOutcome::NoTurn);

    G360::text('First.');
    G360::text('Second.');
    g285Worker()->send('one');
    $first = g285RunId();
    g285Worker()->send('two');
    $second = g285RunId();

    expect(g285Cancel(g285Web(), $second))->toBe(CancelOutcome::NotRunning)
        // An earlier run of this session is its own, and over.
        ->and(g285Cancel(g285Web(), $first))->toBe(CancelOutcome::NotRunning)
        ->and(g285Cancel(g285Web(runtime: false), $second))->toBe(CancelOutcome::Unsupported)
        ->and(g285Web()->run()['status'])->toBe('completed')
        ->and(g285EventNames($this->events))->toBe(['RunFinished', 'RunFinished']);
});

it('cancels twice harmlessly: one request event, one end, and then not-running', function (): void {
    $outcome = [];
    $this->during['search'] = function () use (&$outcome): void {
        $outcome[] = g285Cancel(g285Web(), g285RunId());
        $outcome[] = g285Cancel(g285Web(), g285RunId());
    };
    G360::calls([['search', ['query' => 'tigers']]]);
    G360::text('Never.');

    expect(g285Turn('send')?->code())->toBe('run_cancelled')
        ->and($outcome)->toBe([CancelOutcome::Ok, CancelOutcome::Ok])
        ->and(g285Cancel(g285Web(), g285RunId()))->toBe(CancelOutcome::NotRunning)
        ->and(g285EventNames($this->events))->toBe(['RunCancelRequested', 'RunCancelled']);
});

it('settles a run parked on an approval as cancelled, and it never resumes', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    G360::calls([['search', ['query' => 'tigers']], ['weather', ['city' => 'Detroit']]]);
    $pending = g285Worker()->send('go')->pendingApprovals()[0];
    $runId = g285RunId();
    expect(g285Web()->approvalStatus($pending->approvalId))->toBe('pending');
    $this->events = [];

    expect(g285Cancel(g285Web(), $runId))->toBe(CancelOutcome::Ok)
        ->and(g285EventNames($this->events))->toBe(['RunCancelRequested', 'RunCancelled'])
        ->and($this->events[0]->state)->toBe('parked')
        ->and(g285Web()->approvalStatus($pending->approvalId))->toBe('cancelled')
        ->and(g285Web()->run()['status'])->toBe('cancelled')
        ->and(g285Cancel(g285Web(), $runId))->toBe(CancelOutcome::NotRunning);

    $requests = count(Http::recorded());
    G360::text('A resumed run must never be asked for.');
    $tries = [
        fn () => g285Worker()->approve($pending->approvalId),
        fn () => g285Worker()->deny($pending->approvalId),
        fn () => g285Worker()->decide([new ToolApprovalResponse($pending->approvalId, true)]),
        fn () => iterator_to_array(g285Worker()->streamDecision([new ToolApprovalResponse($pending->approvalId, true)]), false),
    ];
    foreach ($tries as $try) {
        expect($try)->toThrow(ApprovalNotPending::class);
    }
    expect(fn () => g285Worker()->send(''))->toThrow(RunNotPermitted::class, 'run_cancelled')
        ->and(fn () => iterator_to_array(g285Worker()->streamSteps(''), false))->toThrow(RunNotPermitted::class, 'run_cancelled')
        ->and(Http::recorded())->toHaveCount($requests)
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 0])
        ->and(g285Web()->approvalStatus($pending->approvalId))->toBe('cancelled');
});

it('stops the whole run tree when the root is cancelled during a subagent', function (): void {
    config()->set('prism-harness.agent.modes.op_runner', [
        'system_prompt' => 'You run one Op.', 'tools' => ['search'], 'skills' => [], 'max_steps' => 4,
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_op' => ['description' => 'Run an Op.', 'mode' => 'op_runner', 'max_steps' => 3],
    ]);
    $outcome = null;
    $this->during['search'] = function () use (&$outcome): void {
        // The person stops the ROOT run, from another process, while the child works.
        $outcome = g285Cancel(g285Web(), g285RunId());
    };
    G360::calls([['run_op', ['task' => 'Run the billing Op']]]);
    G360::calls([['search', ['query' => 'billing']]]);
    G360::text('Never asked for.');

    expect(g285Turn('send')?->code())->toBe('run_cancelled')
        ->and($outcome)->toBe(CancelOutcome::Ok)
        ->and($this->ran['search'])->toBe(1)
        // Parent step, child step; neither took another.
        ->and(Http::recorded())->toHaveCount(2)
        ->and(g285Web()->run()['status'])->toBe('cancelled')
        ->and(g285EventNames($this->events))->toBe(['RunCancelRequested', 'RunCancelled', 'RunCancelled']);
    // The child ended cancelled too, under its own id, in the root's tree.
    $child = $this->events[1];
    expect($child->runId)->not->toBe($this->events[2]->runId)
        ->and($child->rootRunId)->toBe($this->events[2]->runId)
        ->and($child->parentRunId)->toBe($this->events[2]->runId);
});

it('blocks a plan whose step turn was cancelled, and the next advance runs nothing', function (): void {
    config()->set('prism-harness.agent.modes.chat.plans', true);
    config()->set('prism-harness.plans.enabled', true);
    G360::calls([['plan_draft', ['goal' => 'Plan the trip', 'steps' => G360::steps(2)]]]);
    $pending = g285Worker()->send('Plan the trip')->pendingApprovals()[0];
    G360::text('Plan approved.');
    g285Worker()->approve($pending->approvalId);
    expect(g285Worker()->plan()->current()?->toArray()['status'])->toBe('active');

    $outcome = null;
    $asked = count(Http::recorded()) + 1;
    G360::$onRequest = function (int $request) use (&$outcome, $asked): void {
        if ($request === $asked) {
            $outcome = g285Cancel(g285Web(), g285RunId());
        }
    };
    G360::calls([['plan_complete_step', ['summary' => 'Booked the flight']]]);
    G360::text('Never asked for.');

    $advanced = g285Worker()->plan()->advance();
    $plan = g285Worker()->plan()->current()?->toArray() ?? [];

    expect($outcome)->toBe(CancelOutcome::Ok)
        ->and($advanced->status)->toBe('blocked')
        ->and($advanced->reason)->toBe('run_cancelled')
        ->and($plan['status'])->toBe('blocked')
        ->and($plan['blockedReason'])->toBe('run_cancelled')
        ->and($plan['steps'][0]['state'])->toBe('todo')
        ->and(Http::recorded())->toHaveCount($asked);

    // Blocked plans do not advance: nothing runs until the host resumes it.
    $again = g285Worker()->plan()->advance();
    expect($again->ran())->toBeFalse()
        ->and($again->planStatus)->toBe('blocked')
        ->and(Http::recorded())->toHaveCount($asked);
});

it('stops the workflow a cancelled agent node belongs to, for every later process', function (): void {
    $budget = app(WorkflowBudgets::class)->open('g285-workflow');
    G360::$onRequest = function (int $request): void {
        if ($request === 1) {
            g285Cancel(g285Web(), g285RunId());
        }
    };
    G360::calls([['search', ['query' => 'tigers']]]);
    G360::text('Never asked for.');

    expect(fn () => g285Worker()->send('go', context: $budget->nodeContext()))->toThrow(RunNotPermitted::class, 'run_cancelled');

    // Another worker opens the same workflow run: it is cancelled there too.
    app()->forgetInstance(WorkflowBudgets::class);
    $elsewhere = app(WorkflowBudgets::class)->open('g285-workflow');
    expect(fn () => $elsewhere->assertAvailable())->toThrow(RunNotPermitted::class, 'run_cancelled')
        ->and($elsewhere->context()->ledger->cancelRequested())->toBeTrue()
        ->and($this->ran['search'])->toBe(0)
        ->and(Http::recorded())->toHaveCount(1)
        // A cancel is never reported as a spent budget.
        ->and(g285EventNames($this->events))->not->toContain('WorkflowBudgetExhausted');
});

it('keeps the tool call it guards identical in every other respect', function (): void {
    $thread = Thread::query()->count();
    G360::calls([['search', ['query' => 'tigers']], ['weather', ['city' => 'Detroit']]]);
    G360::text('Both done.');

    expect(g285Turn('send'))->toBeNull()
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 1])
        ->and(G360::outputs())->toBe(['call_g360_1' => 'Found it', 'call_g360_2' => 'Sunny'])
        ->and(Thread::query()->count())->toBe($thread + 1);
});

it('guards a plain tool, and leaves alone the tools a copy would change', function (): void {
    $cancelled = false;
    $plain = (new Tool)->as('plain')->for('Plain.')->using(fn (): string => 'ran');
    $guarded = CancelGuardedTool::guard($plain, function () use (&$cancelled): bool {
        return $cancelled;
    }, null);

    expect($guarded)->toBeInstanceOf(CancelGuardedTool::class)
        ->and($guarded->name())->toBe('plain')
        ->and($guarded->handle())->toBe('ran');
    $cancelled = true;
    expect($guarded->handle())->toBe(CancelGuardedTool::REFUSAL);

    // Core filters Laravel MCP tools by class; a copy would slip past it.
    $mcp = (new ReflectionClass(LaravelMcpTool::class))->newInstanceWithoutConstructor();
    $concurrent = (new Tool)->as('together')->for('Concurrent.')->using(fn (): string => 'ran')->concurrent();
    $overriding = new class extends Tool
    {
        public function name(): string
        {
            return 'mine';
        }
    };
    // A subclass with properties of its own: copying them would create
    // dynamic properties, which PHP deprecates.
    $stateful = new class extends Tool
    {
        public string $extra = 'x';

        protected string $inner = 'y';
    };
    $stateful->as('stateful')->for('Stateful.')->using(fn (): string => 'ran');
    foreach ([$mcp, $concurrent, $overriding, $stateful] as $tool) {
        expect(CancelGuardedTool::guard($tool, fn (): bool => true, null))->toBe($tool);
    }
});

// A subagent child parked on an approval when its tree is cancelled is
// cancelled with the tree: the person stopped the whole tree, so the child's
// approval can no longer be answered and nothing more of it runs.
it('cancels a subagent child parked on an approval when its run tree is cancelled', function (): void {
    config()->set('prism-harness.agent.modes.op_runner', [
        'system_prompt' => 'You run one Op.', 'tools' => ['weather'], 'requires_approval' => ['weather'], 'skills' => [], 'max_steps' => 4,
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', ['search']);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_op' => ['description' => 'Run an Op.', 'mode' => 'op_runner', 'max_steps' => 3],
    ]);
    $outcome = null;
    $this->during['search'] = function () use (&$outcome): void {
        $outcome = g285Cancel(g285Web(), g285RunId());
    };
    G360::calls([['run_op', ['task' => 'Run the billing Op']]]);
    G360::calls([['weather', ['city' => 'Detroit']]]);
    G360::calls([['search', ['query' => 'billing']]]);
    G360::text('Never asked for.');

    expect(g285Turn('send')?->code())->toBe('run_cancelled')
        ->and($outcome)->toBe(CancelOutcome::Ok);
    $child = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat::sub::run_op');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1)
        ->and($child->approvalStatus($pending[0]))->toBe('cancelled');

    $requests = count(Http::recorded());
    G360::text('A cancelled child must never be asked for.');
    expect(fn () => $child->approve($pending[0]))->toThrow(ApprovalNotPending::class)
        ->and(fn () => $child->send(''))->toThrow(RunNotPermitted::class, 'run_cancelled')
        ->and(fn () => $child->send('Carry on'))->toThrow(RunNotPermitted::class, 'run_cancelled')
        ->and(Http::recorded())->toHaveCount($requests)
        ->and($this->ran['weather'])->toBe(0);
});

// A plan step held on an approval whose run a person then cancels is blocked
// with run_cancelled, the step back at todo, instead of waiting on an
// approval nobody can answer; resume() starts it again.
it('blocks a plan whose step is held on an approval of a cancelled run', function (): void {
    config()->set('prism-harness.agent.modes.chat.plans', true);
    config()->set('prism-harness.plans.enabled', true);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    G360::calls([['plan_draft', ['goal' => 'Plan the trip', 'steps' => G360::steps(2)]]]);
    $pending = g285Worker()->send('Plan the trip')->pendingApprovals()[0];
    G360::text('Plan approved.');
    g285Worker()->approve($pending->approvalId);

    G360::calls([['weather', ['city' => 'Detroit']]]);
    $held = g285Worker()->plan()->advance();
    $plan = g285Worker()->plan()->current()?->toArray() ?? [];
    expect($plan['steps'][0]['state'])->toBe('held');

    expect(g285Cancel(g285Web(), g285RunId()))->toBe(CancelOutcome::Ok);
    $requests = count(Http::recorded());
    $advanced = g285Worker()->plan()->advance();
    $plan = g285Worker()->plan()->current()?->toArray() ?? [];

    expect($advanced->status)->toBe('blocked')
        ->and($advanced->reason)->toBe('run_cancelled')
        ->and($plan['status'])->toBe('blocked')
        ->and($plan['blockedReason'])->toBe('run_cancelled')
        ->and($plan['steps'][0]['state'])->toBe('todo')
        ->and($plan['steps'][0]['approvalIds'])->toBe([])
        ->and(Http::recorded())->toHaveCount($requests)
        ->and($this->ran['weather'])->toBe(0);
});

// A cancel made on a subagent child stops the whole tree, so a root parked on
// its own approval reads cancelled too, and approving it runs nothing.
it('cancels a parked root when a child of its tree is cancelled', function (): void {
    config()->set('prism-harness.agent.modes.op_runner', [
        'system_prompt' => 'You run one Op.', 'tools' => ['weather'], 'requires_approval' => ['weather'], 'skills' => [], 'max_steps' => 4,
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', ['search']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search']);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_op' => ['description' => 'Run an Op.', 'mode' => 'op_runner', 'max_steps' => 3],
    ]);
    G360::calls([['run_op', ['task' => 'Run the billing Op']]]);
    G360::calls([['weather', ['city' => 'Detroit']]]);
    G360::calls([['search', ['query' => 'billing']]]);
    $root = g285Worker()->send('go')->pendingApprovals()[0];
    $child = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat::sub::run_op');
    $childRun = $child->run()['id'] ?? null;
    expect($childRun)->toBeString()
        ->and($child->thread()->pendingApprovalIds())->toHaveCount(1);

    expect(g285Cancel($child, $childRun))->toBe(CancelOutcome::Ok);
    $requests = count(Http::recorded());
    G360::text('A cancelled root must never be asked for.');

    expect(g285Web()->approvalStatus($root->approvalId))->toBe('cancelled')
        ->and(fn () => g285Worker()->approve($root->approvalId))->toThrow(ApprovalNotPending::class)
        ->and(Http::recorded())->toHaveCount($requests)
        ->and($this->ran)->toBe(['search' => 0, 'weather' => 0]);
});

// A child the runner starts again under a run tree the host built keeps the
// tree it saved before, but a cancel of its run there belongs to the host's
// tree: the tree it saved before is not marked.
it('does not mark the tree a child saved before when its run under a host-built tree is cancelled', function (): void {
    config()->set('prism-harness.agent.modes.op_runner', [
        'system_prompt' => 'You run one Op.', 'tools' => ['search'], 'skills' => [], 'max_steps' => 4,
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_op' => ['description' => 'Run an Op.', 'mode' => 'op_runner', 'max_steps' => 3],
    ]);
    G360::calls([['run_op', ['task' => 'First']]]);
    G360::text('Child done.');
    G360::text('Parent done.');
    g285Worker()->send('go');
    $child = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat::sub::run_op');
    $saved = $child->childTree();
    expect($saved)->not->toBeNull();

    $outcome = null;
    $this->during['search'] = function () use (&$outcome): void {
        $web = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat::sub::run_op');
        $outcome = g285Cancel($web, (string) ($web->run()['id'] ?? ''));
    };
    G360::calls([['run_op', ['task' => 'Again']]]);
    G360::calls([['search', ['query' => 'billing']]]);
    G360::text('Parent again.');
    G360::text('Spare.');
    try {
        g285Worker()->send('again', null, [], RunContext::root('host_root', new RunBudget(20)));
    } catch (RunNotPermitted) {
    }

    expect($outcome)->toBe(CancelOutcome::Ok)
        ->and($child->childTree())->toBe($saved)
        ->and(app(RunTrees::class)->treeCancelled($child, $saved))->toBeFalse();
});

// A child resumed outside the runner continues its run tree, and a grandchild
// it starts belongs to that tree too: cancelling the grandchild marks the
// tree, so every other run of it stops.
it('marks the run tree when a grandchild started by a resumed child is cancelled', function (): void {
    config()->set('prism-harness.agent.modes.deep_runner', [
        'system_prompt' => 'You dig.', 'tools' => ['search'], 'skills' => [], 'max_steps' => 4,
    ]);
    config()->set('prism-harness.agent.modes.op_runner', [
        'system_prompt' => 'You run one Op.', 'tools' => ['weather'], 'requires_approval' => ['weather'], 'skills' => [], 'max_steps' => 6,
        'subagents' => ['dig' => ['description' => 'Dig.', 'mode' => 'deep_runner', 'max_steps' => 3]],
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_op' => ['description' => 'Run an Op.', 'mode' => 'op_runner', 'max_steps' => 5],
    ]);
    $participant = Participant::query()->firstOrCreate(['name' => 'Ada']);
    G360::calls([['run_op', ['task' => 'Run the billing Op']]]);
    G360::calls([['weather', ['city' => 'Detroit']]]);
    G360::text('Root done.');
    g285Worker()->send('go');
    $child = app(PrismHarness::class)->for($participant)->session('chat::sub::run_op');
    $tree = $child->childTree();
    $pending = $child->thread()->pendingApprovalIds();
    expect($tree)->not->toBeNull()->and($pending)->toHaveCount(1);

    $outcome = null;
    $this->during['search'] = function () use (&$outcome, $participant): void {
        $grandchild = app(PrismHarness::class)->for($participant)->session('chat::sub::run_op::sub::dig');
        $outcome = g285Cancel($grandchild, (string) ($grandchild->run()['id'] ?? ''));
    };
    G360::calls([['dig', ['task' => 'Dig into billing']]]);
    G360::calls([['search', ['query' => 'billing']]]);
    G360::text('Spare.');
    G360::text('Spare.');
    try {
        $child->approve($pending[0]);
    } catch (RunNotPermitted) {
    }

    expect($outcome)->toBe(CancelOutcome::Ok)
        ->and(app(RunTrees::class)->treeCancelled($child, $tree))->toBeTrue();
});

// The same, with the host answering the child under a run context of its own:
// the child continues its saved tree, and so does the grandchild it starts.
it('marks the run tree when a grandchild of a child resumed under a host context is cancelled', function (): void {
    config()->set('prism-harness.agent.modes.deep_runner', [
        'system_prompt' => 'You dig.', 'tools' => ['search'], 'skills' => [], 'max_steps' => 4,
    ]);
    config()->set('prism-harness.agent.modes.op_runner', [
        'system_prompt' => 'You run one Op.', 'tools' => ['weather'], 'requires_approval' => ['weather'], 'skills' => [], 'max_steps' => 6,
        'subagents' => ['dig' => ['description' => 'Dig.', 'mode' => 'deep_runner', 'max_steps' => 3]],
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_op' => ['description' => 'Run an Op.', 'mode' => 'op_runner', 'max_steps' => 5],
    ]);
    $participant = Participant::query()->firstOrCreate(['name' => 'Ada']);
    G360::calls([['run_op', ['task' => 'Run the billing Op']]]);
    G360::calls([['weather', ['city' => 'Detroit']]]);
    G360::text('Root done.');
    g285Worker()->send('go');
    $child = app(PrismHarness::class)->for($participant)->session('chat::sub::run_op');
    $tree = $child->childTree();
    $pending = $child->thread()->pendingApprovalIds();
    expect($tree)->not->toBeNull()->and($pending)->toHaveCount(1);

    $outcome = null;
    $this->during['search'] = function () use (&$outcome, $participant): void {
        $grandchild = app(PrismHarness::class)->for($participant)->session('chat::sub::run_op::sub::dig');
        $outcome = g285Cancel($grandchild, (string) ($grandchild->run()['id'] ?? ''));
    };
    G360::calls([['dig', ['task' => 'Dig into billing']]]);
    G360::calls([['search', ['query' => 'billing']]]);
    G360::text('Spare.');
    G360::text('Spare.');
    try {
        $child->approve($pending[0], true, null, RunContext::root('host_answer', new RunBudget(100)));
    } catch (RunNotPermitted) {
    }

    $grandchild = app(PrismHarness::class)->for($participant)->session('chat::sub::run_op::sub::dig');

    expect($outcome)->toBe(CancelOutcome::Ok)
        ->and($grandchild->childTree())->toBe($tree)
        ->and(app(RunTrees::class)->treeCancelled($child, $tree))->toBeTrue()
        ->and(g285Web()->run()['status'])->toBe('cancelled');
});
