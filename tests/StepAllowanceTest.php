<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Models\Thread;
use Prism\Harness\Models\ThreadMessage;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Plans\Plans;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;
use Tests\Support\G360;

// G-387: how many model steps a run may take is decided ONCE, when it begins,
// from facts the runtime holds (the count its parked run wrote, the ceiling
// the runner saved with a child, the tree's and the plan's grants), and every
// consumer reads that one record. Driven through the public seam (send,
// approve, decide, streamDecision, sendStructured, SubagentRunner::run and a
// host RunContext) over Prism's real OpenAI handler on faked HTTP with stray
// requests refused. Each resume goes through fresh runtime objects, so what
// holds is what was persisted, never what a process remembered.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 8);
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

/** A model that keeps asking for the gated tool, for as long as it is asked. */
function g387Pad(int $count = 30): void
{
    for ($i = 0; $i < $count; $i++) {
        G360::calls([['weather', ['city' => 'Paris']]]);
    }
}

function g387Ada(): Participant
{
    return Participant::query()->firstOrCreate(['name' => 'Ada']);
}

function g387Root(): Session
{
    return app(PrismHarness::class)->for(g387Ada())->session('chat');
}

function g387Child(string $name): Session
{
    return app(PrismHarness::class)->for(g387Ada())->session('chat::sub::'.$name);
}

/** A new process: nothing a runtime object remembered survives. */
function g387Fresh(): void
{
    foreach ([PrismHarness::class, AgentRuntime::class, SubagentRunner::class, RunTrees::class, Plans::class] as $class) {
        app()->forgetInstance($class);
    }
}

function g387Requests(): int
{
    return count(Http::recorded());
}

/**
 * Approve whatever the session waits on, up to $times, each through fresh
 * objects. Returns the refusal that ended it, if one did.
 *
 * @param  Closure(): Session  $session
 * @param  (Closure(): ?RunContext)|null  $context
 */
function g387ApproveAll(Closure $session, ?Closure $context = null, int $times = 6): ?RunNotPermitted
{
    $refused = null;
    for ($i = 0; $i < $times; $i++) {
        g387Fresh();
        $ids = $session()->thread()->pendingApprovalIds();
        if ($ids === []) {
            break;
        }
        try {
            $session()->approve($ids[0], true, null, $context === null ? null : $context());
        } catch (RunNotPermitted $failure) {
            $refused = $failure;
        }
    }

    return $refused;
}

/** @return (Closure(): ?RunContext)|null */
function g387Context(string $kind, int $hostSteps = 10): ?Closure
{
    return match ($kind) {
        'none' => null,
        'metadata' => fn (): RunContext => RunContext::metadata('turn_1', 'person'),
        'host' => fn (): RunContext => RunContext::root('host_run', new RunBudget($hostSteps)),
    };
}

/** The newest assistant row of a session: the one a parked run parked on. */
function g387ParkedRow(Session $session): ?ThreadMessage
{
    return $session->thread()->storedMessages()->where('type', 'assistant')->reorder('position', 'desc')->first();
}

/** A root that delegates to `$name` (declared `$declared` steps); the child parks on weather after one step. */
function g387ParkedChild(string $name = 'worker', int $declared = 2): void
{
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        $name => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => $declared],
    ]);
    G360::calls([[$name, ['task' => 'Go']]]);       // root
    G360::calls([['weather', ['city' => 'Paris']]]); // child: parks
    G360::text('Asked.');                            // root
    g387Pad();
    g387Root()->send('Go');
    expect(g387Child($name)->thread()->pendingApprovalIds())->toHaveCount(1);
}

// 1. D1: the declared ceiling of a child holds on every turn the runner did not start.
it('holds a child resumed outside the runner to the ceiling it declared', function (string $kind): void {
    g387ParkedChild('worker', 2);
    $before = g387Requests();

    g387ApproveAll(fn (): Session => g387Child('worker'), g387Context($kind));

    expect(g387Requests() - $before)->toBeLessThanOrEqual(1)
        ->and($this->ran['weather'])->toBeGreaterThanOrEqual(1);
})->with(['none', 'metadata', 'host']);

// 2. A host context never raises the saved ceiling, and can lower it: one
// resume, with a model that would keep going on an ungated tool.
it('lets a host context only lower a child the runner bounded', function (int $hostSteps, int $took): void {
    g387ParkedChild('worker', 3);
    G360::$queue = [];
    for ($i = 0; $i < 10; $i++) {
        G360::calls([['echo', ['text' => 'e'.$i]]]);
    }
    $before = g387Requests();
    g387Fresh();

    g387ApproveAll(fn (): Session => g387Child('worker'), g387Context('host', $hostSteps), 1);

    expect(g387Requests() - $before)->toBe($took)
        ->and($this->ran['weather'])->toBe(1);
})->with([
    'a larger host budget' => [10, 2],
    'a smaller host budget' => [1, 1],
]);

// 3. A sibling spent the tree: the other child's approved call still runs, with no model step.
it('acts on a child decision, with no model step, when a sibling spent the tree', function (): void {
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.max_steps', 6);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'worker_a' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => 8],
        'worker_b' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => 8],
    ]);
    G360::calls([['worker_a', ['task' => 'Go']]]);   // root
    G360::calls([['weather', ['city' => 'Oslo']]]);   // child a: parks
    G360::calls([['worker_b', ['task' => 'Go']]]);   // root
    G360::calls([['weather', ['city' => 'Rome']]]);   // child b: parks
    G360::text('Both asked.');                        // root: 5 of the tree's 6
    g387Pad();
    g387Root()->send('Go');

    // Child a, resumed outside the runner, spends what the tree has left.
    g387ApproveAll(fn (): Session => g387Child('worker_a'));
    $record = g387Root()->planStore()->get(g387Root()->key().':run-tree:'.g387Child('worker_b')->childTree()['run']);
    expect($record['steps'])->toBe($record['max_steps']);

    g387Fresh();
    $child = g387Child('worker_b');
    $pending = $child->thread()->pendingApprovalIds();
    $call = g387ParkedRow($child)->payload['tool_approval_requests'][0]['tool_call_id'];
    $ran = $this->ran['weather'];
    $before = g387Requests();
    try {
        $child->approve($pending[0]);
    } catch (RunNotPermitted $failure) {
        // The run ends on its spent budget AFTER acting: never before.
        expect($failure->code())->not->toBe('run_cancelled');
    }

    $results = g387Child('worker_b')->thread()->storedMessages()->where('type', 'tool_result')->get()
        ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])->where('tool_call_id', $call)->count();
    expect($this->ran['weather'])->toBe($ran + 1)
        ->and($results)->toBe(1)
        ->and(g387Requests())->toBe($before)
        ->and(g387Child('worker_b')->thread()->pendingApprovalIds())->toBe([]);
});

// 4. Re-park: a root that delegated counts its children's steps across every resume.
it('counts the steps a root spent through a child across six approvals', function (): void {
    config()->set('prism-harness.agent.modes.helper', ['system_prompt' => 'Help.', 'tools' => ['echo'], 'skills' => [], 'max_steps' => 8]);
    config()->set('prism-harness.agent.modes.chat.subagents', ['helper' => ['description' => 'Help.', 'mode' => 'helper', 'max_steps' => 8]]);
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    G360::calls([['helper', ['task' => 'Help']]]);   // root: 1
    G360::calls([['echo', ['text' => 'a']]]);         // child: 2
    G360::calls([['echo', ['text' => 'b']]]);         // child: 3
    G360::text('Helped.');                            // child: 4
    G360::calls([['weather', ['city' => 'Paris']]]); // root: 5, parks
    g387Pad();
    g387Root()->send('Go');
    expect(g387Requests())->toBe(5)
        ->and(g387ParkedRow(g387Root())->payload['harness_steps_used'] ?? null)->toBe(5);

    g387ApproveAll(fn (): Session => g387Root(), null, 6);

    expect(g387Requests() - 5)->toBeLessThanOrEqual(3);
});

it('never lets a child resumed outside the runner past its ceiling across six approvals', function (string $kind): void {
    g387ParkedChild('worker', 3);
    $before = g387Requests();

    g387ApproveAll(fn (): Session => g387Child('worker'), g387Context($kind, 50), 6);

    expect(g387Requests() - $before)->toBeLessThanOrEqual(2);
})->with(['none', 'host']);

// 5. Which ceiling binds: the mode on a root, the declaration on a child (above),
// and max_steps_ceiling the same on a no-context and a host-context resume (D2).
it('holds a root to its mode across approvals', function (): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 3);
    g387Pad();
    g387Root()->send('Go');

    g387ApproveAll(fn (): Session => g387Root(), null, 6);

    expect(g387Requests())->toBe(3);
});

it('applies max_steps_ceiling to the whole count under no context and a host context alike', function (string $kind): void {
    config()->set('prism-harness.agents.support', ['max_steps_ceiling' => 2]);
    app()->forgetInstance(AgentRuntime::class);
    app(ToolRegistry::class)->forAgent('support')->register((new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
        ->using(fn (string $city): string => 'Sunny'), 'support');
    app(ModeRegistry::class)->forAgent('support')->register('reply', [
        'system_prompt' => 'Reply.', 'tools' => ['weather'], 'requires_approval' => ['weather'], 'max_steps' => 8,
    ], 'support')->setDefault('reply');
    $session = fn (): Session => app(PrismHarness::class)->forAgent('support')->for(g387Ada())->session('same');
    g387Pad();
    $session()->send('Go');
    expect(g387Requests())->toBe(1);

    g387ApproveAll($session, g387Context($kind, 10), 6);

    expect(g387Requests())->toBe(2);
})->with(['none', 'host']);

// A workflow agent node's run that parks continues its count on every resume
// under a node context, never restarting it from the session's ceiling.
it('continues a workflow node run count across resumes under node contexts', function (): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 3);
    $node = fn (): RunContext => RunContext::root('workflow', new RunBudget(100))->forWorkflowNode();
    g387Pad();
    g387Root()->send('Go', context: $node());
    expect(g387Requests())->toBe(1)
        ->and(g387ParkedRow(g387Root())->payload['harness_steps_used'] ?? null)->toBe(1);

    g387ApproveAll(fn (): Session => g387Root(), $node, 6);

    expect(g387Requests())->toBe(3);
});

// The runner's pre-check reads what the child's context leaves above the
// tree's running count, in the ledger's own unit: a child with nothing left is
// refused before it costs a session, a thread or a provider call.
it('refuses a child with no steps left before spawning it, whatever the tree has counted', function (): void {
    $parent = g387Root();
    $parent->thread();
    $context = RunContext::root('run_root', new RunBudget(8));
    $context->ledger->recordSteps(3);
    $threads = Thread::query()->count();

    $result = app(SubagentRunner::class)->run(new Subagent('worker', 'Work.', 'work', new RunBudget(0)), $parent, $context, 'run_root', 'go');

    expect($result->outcome->value)->toBe('exhausted')
        ->and(Thread::query()->count())->toBe($threads)
        ->and(g387Requests())->toBe(0);
});

// 6. Plan-path decisions (D3/D4/D5).
function g387PlanSetup(int $planSteps): void
{
    config()->set('prism-harness.agent.modes.chat.tools', ['weather', 'echo']);
    config()->set('prism-harness.agent.modes.chat.plans', true);
    config()->set('prism-harness.plans.enabled', true);
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => $planSteps]);
    G360::calls([['plan_draft', ['goal' => 'Plan the trip', 'steps' => G360::steps(2)]]]);
    $pending = G360::session()->send('Plan the trip')->pendingApprovals()[0];
    G360::text('Plan approved.');
    G360::session()->approve($pending->approvalId);
}

/** The plan's first step parks on weather after `$lookups` echo steps. Returns its approval id. */
function g387HeldStep(int $echoes = 0): string
{
    for ($i = 0; $i < $echoes; $i++) {
        G360::calls([['echo', ['text' => 'e'.$i]]]);
    }
    G360::calls([['weather', ['city' => 'Paris']]]);
    G360::session()->plan()->advance();
    $step = G360::session()->plan()->current()?->toArray()['steps'][0];
    expect($step['state'])->toBe('held');

    return $step['approvalIds'][0];
}

/** @return array{ran: int, requests: int, pending: list<string>} */
function g387ApprovePlanStep(string $approval, object $test): array
{
    $requests = g387Requests();
    $ran = $test->ran['weather'];
    try {
        g387Fresh();
        G360::session()->approve($approval);
    } catch (RunNotPermitted $failure) {
        expect($failure->code())->not->toBe('plan_budget_exhausted');
    }

    return ['ran' => $test->ran['weather'] - $ran, 'requests' => g387Requests() - $requests,
        'pending' => G360::session()->thread()->pendingApprovalIds()];
}

it('acts on a plan step decision when the shared budget was spent on its last step', function (): void {
    g387PlanSetup(1);
    $approval = g387HeldStep();
    g387Pad();

    expect(g387ApprovePlanStep($approval, $this))->toBe(['ran' => 1, 'requests' => 0, 'pending' => []]);
});

it('acts on a plan step decision when live holds took what the shared budget had left', function (): void {
    g387PlanSetup(3);
    $approval = g387HeldStep();
    // Another worker holds the rest of the plan's budget while this decision lands.
    $plans = app(Plans::class);
    $serving = $plans->servingStep(G360::session());
    $plans->planTurn(G360::session(), 'run_other', RunContext::root('run_other', new RunBudget(2)), null);
    $serving();
    g387Pad();

    expect(g387ApprovePlanStep($approval, $this))->toBe(['ran' => 1, 'requests' => 0, 'pending' => []]);
});

it('acts on a plan step decision when the run spent its own last step', function (): void {
    g387PlanSetup(20);
    config()->set('prism-harness.agent.modes.chat.max_steps', 2);
    app()->forgetInstance(ModeRegistry::class);
    $approval = g387HeldStep(1);
    g387Pad();

    expect(g387ApprovePlanStep($approval, $this))->toBe(['ran' => 1, 'requests' => 0, 'pending' => []]);
});

it('writes the plan-narrowed allowance on the row a plan step parks on', function (): void {
    g387PlanSetup(3);
    g387HeldStep();

    $row = g387ParkedRow(G360::session())->payload;
    expect($row['harness_steps_used'] ?? null)->toBe(1)
        ->and($row['harness_steps_left'] ?? null)->toBe(2);
});

// 7. Spent on its last step: send, stream and structured end it the same way.
function g387Schema(): ObjectSchema
{
    return new ObjectSchema('answer', 'An answer.', [new StringSchema('text', 'The text.')], ['text']);
}

/** @return array{refused: ?string, failed: int} */
function g387Resume(string $path): array
{
    Event::fake([RunFailed::class]);
    $refused = null;
    try {
        match ($path) {
            'send' => g387Root()->send(''),
            'stream' => iterator_to_array(g387Root()->stream(''), false),
            'streamSteps' => iterator_to_array(g387Root()->streamSteps(''), false),
            'structured' => g387Root()->sendStructured('', g387Schema()),
        };
    } catch (RunNotPermitted $failure) {
        $refused = $failure->getMessage();
    }

    return ['refused' => $refused, 'failed' => count(Event::dispatched(RunFailed::class)), 'status' => g387Root()->run()['status'] ?? null];
}

it('acts on a decision taken on the last step identically on every turn shape', function (string $path): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 1);
    G360::calls([['weather', ['city' => 'Paris']]]);
    g387Pad();
    g387Root()->send('Go');
    $pending = g387Root()->thread()->pendingApprovalIds();
    $root = g387Root();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($pending[0], true)]))->call($root);
    $before = g387Requests();

    $acted = g387Resume($path);

    expect($this->ran['weather'])->toBe(1)
        ->and(g387Requests())->toBe($before)
        ->and($acted)->toBe(['refused' => 'This run may not proceed: step budget exhausted (1 of 1 used).', 'failed' => 1, 'status' => 'failed']);

    // Nothing is left to act on: every shape refuses before any provider call,
    // the same way (send used to end it unannounced while a stream failed it).
    expect(g387Resume($path))->toBe(['refused' => 'This run may not proceed: step budget exhausted (1 of 1 used).', 'failed' => 0, 'status' => 'failed'])
        ->and($this->ran['weather'])->toBe(1)
        ->and(g387Requests())->toBe($before);
})->with(['send', 'stream', 'streamSteps', 'structured']);

// 8. A forged count on a paused row fails closed, and a lower one never beats the transcript.
it('fails closed on a forged harness_steps_used', function (mixed $forged): void {
    G360::calls([['weather', ['city' => 'Paris']]]);
    g387Pad();
    g387Root()->send('Go');
    $row = g387ParkedRow(g387Root());
    $row->payload = [...$row->payload, 'harness_steps_used' => $forged];
    $row->save();
    $before = g387Requests();

    g387ApproveAll(fn (): Session => g387Root(), null, 3);

    expect($this->ran['weather'])->toBe(1)
        ->and(g387Requests())->toBe($before);
})->with([
    'negative' => [-1],
    'fractional' => [1.5],
    'a string' => ['0'],
    'a list' => [[0]],
    'unsafe' => [PHP_INT_MAX],
    'null' => [null],
]);

// New channels the fix could open, each closed.
it('never lets a paused row forged down to 0 grant more than the transcript shows was used', function (): void {
    config()->set('prism-harness.agent.modes.chat.tools', []);
    $parent = app(PrismHarness::class)->for(g387Ada())->session('chat');
    $subagent = new Subagent('worker', 'Work.', 'work', new RunBudget(3));
    G360::calls([['echo', ['text' => 'a']]]);
    G360::calls([['weather', ['city' => 'Paris']]]);
    g387Pad();
    app(SubagentRunner::class)->run($subagent, $parent, RunContext::root('run_root', new RunBudget(8)), 'run_root', 'go');
    $child = g387Child('worker');
    expect(g387Requests())->toBe(2);
    $row = g387ParkedRow($child);
    $row->payload = [...$row->payload, 'harness_steps_used' => 0];
    $row->save();

    g387ApproveAll(fn (): Session => g387Child('worker'), null, 6);

    expect(g387Requests())->toBeLessThanOrEqual(3);
});

// A count absent from a child's paused row, on a session that saved a step
// ceiling, was not written by this runtime (the two are written together):
// it fails closed. Absent on a session with no saved ceiling (an earlier
// release's child), it is counted from the transcript, never read as 0.
it('fails closed on a paused child row with no harness_steps_used beside a saved ceiling', function (): void {
    g387ParkedChild('worker', 2);
    $row = g387ParkedRow(g387Child('worker'));
    $payload = $row->payload;
    unset($payload['harness_steps_used'], $payload['harness_steps_left']);
    $row->payload = $payload;
    $row->save();
    $before = g387Requests();

    g387ApproveAll(fn (): Session => g387Child('worker'));

    expect(g387Requests())->toBe($before)
        ->and($this->ran['weather'])->toBe(1);
});

it('counts a legacy paused row with no harness_steps_used and no saved ceiling from the transcript', function (): void {
    config()->set('prism-harness.agent.modes.work.max_steps', 2);
    g387ParkedChild('worker', 8);
    $child = g387Child('worker');
    // As an earlier release saved it: no step ceiling, no count on the row.
    $child->rememberChildBinding($child->childBinding(), $child->childDepth(), $child->childPlanLedger(), $child->childTree());
    expect(g387Child('worker')->childStepCeiling())->toBeNull();
    $row = g387ParkedRow(g387Child('worker'));
    $payload = $row->payload;
    unset($payload['harness_steps_used'], $payload['harness_steps_left']);
    $row->payload = $payload;
    $row->save();
    $before = g387Requests();

    g387ApproveAll(fn (): Session => g387Child('worker'));

    // The mode's 2, less the 1 the transcript shows: never a fresh 2.
    expect(g387Requests() - $before)->toBe(1);
});

it('gives a child the runner starts again a fresh allowance, never the parked count', function (): void {
    g387ParkedChild('worker', 2);
    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Again']]]);  // root
    G360::calls([['echo', ['text' => 'a']]]);         // child: 1
    G360::text('Done again.');                        // child: 2
    G360::text('Fine.');                              // root
    g387Pad();
    $before = g387Requests();
    g387Fresh();
    g387Root()->send('Again');

    expect($this->ran['echo'])->toBe(1)
        ->and(g387Requests() - $before)->toBe(4)
        ->and(g387ParkedRow(g387Child('worker'))->payload['content'] ?? null)->toBe('Done again.');
});

it('still refuses a child decision on a cancelled tree, recording nothing', function (): void {
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    app(RunTrees::class)->cancelTree($child, $child->childTree());
    $pending = $child->thread()->pendingApprovalIds();
    $before = g387Requests();

    expect(fn () => g387Child('worker')->approve($pending[0]))->toThrow(ApprovalNotPending::class, 'was cancelled')
        ->and(g387Child('worker')->thread()->pendingApprovalIds())->toBe($pending)
        ->and($this->ran['weather'])->toBe(0)
        ->and(g387Requests())->toBe($before);
});

// COST never refuses a decision either: acting on an approved call makes no
// provider call. A spent cost allowance on a resume acts, with no model step.
it('acts on a child decision when its tree cost is spent, with no model step', function (): void {
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    $tree = $child->childTree();
    $key = $tree['session'].':run-tree:'.$tree['run'];
    $record = $child->planStore()->get($key);
    $child->planStore()->put($key, [...$record, 'max_cost_micros' => 1000, 'cost_micros' => 1000]);
    $pending = $child->thread()->pendingApprovalIds();
    $before = g387Requests();

    try {
        g387Child('worker')->approve($pending[0]);
    } catch (RunNotPermitted $failure) {
        expect($failure->getMessage())->toContain('budget exhausted');
    }

    expect($this->ran['weather'])->toBe(1)
        ->and(g387Child('worker')->thread()->pendingApprovalIds())->toBe([])
        ->and(g387Requests())->toBe($before);
});

it('acts on a plan step decision when the shared budget cost is spent, with no model step', function (): void {
    g387PlanSetup(10);
    $approval = g387HeldStep();
    $session = G360::session();
    $record = $session->planStore()->get($session->key().':plan-budget');
    $session->planStore()->put($session->key().':plan-budget', [...$record, 'max_cost_micros' => 1000, 'cost_micros' => 1000]);
    g387Pad();

    expect(g387ApprovePlanStep($approval, $this))->toBe(['ran' => 1, 'requests' => 0, 'pending' => []]);
});

// A NEW message (it resumes no recorded decision) with nothing left is
// refused before anything is recorded: run_not_permitted for the run's own
// ceiling or its tree.
function g387Rows(Session $session): int
{
    return $session->thread()->storedMessages()->count();
}

it('refuses a new message to a child whose saved ceiling is spent, recording nothing', function (): void {
    g387ParkedChild('worker', 1);
    $rows = g387Rows(g387Child('worker'));
    $before = g387Requests();
    g387Fresh();

    expect(fn () => g387Child('worker')->send('A new question'))->toThrow(RunNotPermitted::class, 'step budget exhausted');
    expect(g387Rows(g387Child('worker')))->toBe($rows)
        ->and(g387Requests())->toBe($before);
});

it('refuses a new message to a child whose tree a sibling spent, recording nothing', function (): void {
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.max_steps', 6);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'worker_a' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => 8],
        'worker_b' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => 8],
    ]);
    G360::calls([['worker_a', ['task' => 'Go']]]);
    G360::calls([['weather', ['city' => 'Oslo']]]);
    G360::calls([['worker_b', ['task' => 'Go']]]);
    G360::calls([['weather', ['city' => 'Rome']]]);
    G360::text('Both asked.');
    g387Pad();
    g387Root()->send('Go');
    g387ApproveAll(fn (): Session => g387Child('worker_a'));
    $rows = g387Rows(g387Child('worker_b'));
    $before = g387Requests();
    g387Fresh();

    expect(fn () => g387Child('worker_b')->send('A new question'))->toThrow(RunNotPermitted::class, 'step budget exhausted');
    expect(g387Rows(g387Child('worker_b')))->toBe($rows)
        ->and(g387Requests())->toBe($before)
        ->and($this->ran['weather'])->toBe(2);
});
