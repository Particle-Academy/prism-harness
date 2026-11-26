<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
use Prism\Harness\Subagents\RunBudgets;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunLedger;
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

// A linked child with no saved ceiling (parked by an earlier release, or
// with its ceiling removed) fails closed: it acts on its decisions with no
// model step, and a new message to it is refused before anything is
// recorded. Only a session that was never a child runs on its mode.
it('fails closed on a linked child with no saved ceiling', function (): void {
    config()->set('prism-harness.agent.modes.work.max_steps', 8);
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    g387SaveChild($child, null);
    expect(g387Child('worker')->childStepCeiling())->toBeNull();
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

it('gives a child the runner starts again a fresh allowance, never the earlier count', function (): void {
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'worker' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => 2],
    ]);
    foreach (['First', 'Again'] as $task) {
        G360::calls([['worker', ['task' => $task]]]);   // root
        G360::calls([['echo', ['text' => $task]]]);      // child: 1
        G360::text('Done '.$task.'.');                   // child: 2, its whole ceiling
        G360::text('Fine.');                             // root
    }
    g387Pad();
    g387Root()->send('Go');
    $before = g387Requests();
    g387Fresh();
    g387Root()->send('Again');

    expect($this->ran['echo'])->toBe(2)
        ->and(g387Requests() - $before)->toBe(4)
        ->and(g387ParkedRow(g387Child('worker'))->payload['content'] ?? null)->toBe('Done Again.');
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

it('refuses a new message to a child whose saved ceiling is 0, recording nothing', function (): void {
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    // As a store writer could leave it: the saved ceiling at 0.
    $child->rememberChildBinding($child->childBinding(), $child->childDepth(), $child->childPlanLedger(), $child->childTree(), 0);
    $rows = g387Rows(g387Child('worker'));
    $before = g387Requests();
    g387Fresh();

    expect(fn () => g387Child('worker')->send('A new question'))->toThrow(RunNotPermitted::class, 'step budget exhausted');
    expect(g387Rows(g387Child('worker')))->toBe($rows)
        ->and(g387Requests())->toBe($before);
});

// A new message starts a new run on the saved ceiling; what a child spends
// across new messages outside the runner is bounded by its tree's record.
it('never lets repeated new messages to a child spend past its tree record', function (): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 6);
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    $tree = $child->childTree();
    $key = $tree['session'].':run-tree:'.$tree['run'];
    $record = $child->planStore()->get($key);
    G360::$queue = [];
    for ($i = 0; $i < 40; $i++) {
        G360::calls([['echo', ['text' => 'e'.$i]]]);
    }
    $before = g387Requests();

    for ($i = 0; $i < 8; $i++) {
        g387Fresh();
        try {
            g387Child('worker')->send('Question '.$i);
        } catch (RunNotPermitted) {
        }
    }

    $after = g387Child('worker')->planStore()->get($key);
    // The delegation's ceiling (2, 1 taken by the runner) binds before the
    // tree's record (3 left): never past either.
    expect(g387Requests() - $before)->toBe(1)
        ->and($after['steps'])->toBeLessThanOrEqual($after['max_steps']);
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

// ---------------------------------------------------------------------------
// G-387 round 2: the final review's findings.
// ---------------------------------------------------------------------------

/**
 * Rewrite a child's saved record as a store writer (or an earlier release)
 * could leave it: the same binding, depth, plan ledger and tree, with the
 * given step ceiling field, or none when `$ceiling` is null and `$present`
 * is false.
 */
function g387SaveChild(Session $child, mixed $ceiling, bool $present = false): void
{
    $binding = $child->childBinding();
    $record = [
        'provider' => $binding?->provider, 'model' => $binding?->model,
        'parent_mode_provider' => $binding?->parentModeProvider, 'parent_mode_model' => $binding?->parentModeModel,
        'depth' => $child->childDepth(),
    ];
    if ($child->childPlanLedger() !== null) {
        $record['plan_ledger'] = $child->childPlanLedger();
    }
    if ($child->childTree() !== null) {
        $record['tree'] = $child->childTree();
    }
    if ($ceiling !== null || $present) {
        $record['step_ceiling'] = $ceiling;
    }
    (fn () => $this->durable->put($this->key().':child', ['child_binding' => $record]))->call($child);
}

/** A model that keeps calling the ungated tool, for as long as it is asked. */
function g387Echoes(int $count = 30): void
{
    G360::$queue = [];
    for ($i = 0; $i < $count; $i++) {
        G360::calls([['echo', ['text' => 'e'.$i]]]);
    }
}

// 1. The saved ceiling is the delegation's lifetime budget.
it('holds repeated new messages to a child to its declared ceiling in total', function (): void {
    g387ParkedChild('worker', 2);
    g387Echoes();
    $before = g387Requests();

    for ($i = 0; $i < 3; $i++) {
        g387Fresh();
        try {
            g387Child('worker')->send('Question '.$i);
        } catch (RunNotPermitted) {
        }
    }

    // The runner's start took 1 of the 2.
    expect(g387Requests() - $before)->toBeLessThanOrEqual(1);
});

it('holds resumes, new messages and empty sends on a child to its ceiling in total', function (): void {
    g387ParkedChild('worker', 3);
    G360::$queue = [];
    g387Pad(2);
    for ($i = 0; $i < 20; $i++) {
        G360::calls([['echo', ['text' => 'e'.$i]]]);
    }
    $before = g387Requests();

    g387ApproveAll(fn (): Session => g387Child('worker'), null, 2);
    foreach (['A question', '', 'Another'] as $prompt) {
        g387Fresh();
        try {
            g387Child('worker')->send($prompt);
        } catch (RunNotPermitted) {
        }
    }

    expect(g387Requests() - $before)->toBeLessThanOrEqual(2);
});

it('never lets a second turn started mid-call on the same child spend its ceiling again', function (): void {
    g387ParkedChild('worker', 3);
    g387Echoes();
    $pending = g387Child('worker')->thread()->pendingApprovalIds();
    $before = g387Requests();
    $fired = false;
    G360::$onRequest = function () use (&$fired): void {
        if ($fired) {
            return;
        }
        $fired = true;
        try {
            g387Child('worker')->send('A second turn, at once');
        } catch (Throwable) {
        }
    };

    try {
        g387Child('worker')->approve($pending[0]);
    } catch (RunNotPermitted) {
    }
    G360::$onRequest = null;

    expect(g387Requests() - $before)->toBeLessThanOrEqual(2);
});

// 2. A missing or garbage saved ceiling on a linked child fails closed.
it('fails closed on a linked child whose saved ceiling is not a count', function (mixed $ceiling): void {
    g387ParkedChild('worker', 2);
    g387SaveChild(g387Child('worker'), $ceiling, present: true);
    $before = g387Requests();

    g387ApproveAll(fn (): Session => g387Child('worker'));
    expect(g387Requests())->toBe($before)
        ->and($this->ran['weather'])->toBe(1);

    $rows = g387Rows(g387Child('worker'));
    expect(fn () => g387Child('worker')->send('A new question'))->toThrow(RunNotPermitted::class);
    expect(g387Rows(g387Child('worker')))->toBe($rows)
        ->and(g387Requests())->toBe($before);
})->with([
    'a string' => ['2'],
    'negative' => [-1],
    'true' => [true],
    'a fraction' => [2.5],
    'null' => [null],
    'unsafe' => [PHP_INT_MAX],
]);

it('refuses a new message to a linked child with no saved ceiling, recording nothing', function (): void {
    g387ParkedChild('worker', 2);
    g387SaveChild(g387Child('worker'), null);
    $rows = g387Rows(g387Child('worker'));
    $before = g387Requests();

    expect(fn () => g387Child('worker')->send('A new question'))->toThrow(RunNotPermitted::class);
    expect(g387Rows(g387Child('worker')))->toBe($rows)
        ->and(g387Requests())->toBe($before);
});

// 3. Non-finite or negative budgets never remove the cap.
it('refuses a run budget that is negative or not a whole number of steps', function (mixed $steps): void {
    expect(fn () => RunBudget::fromArray(['max_steps' => $steps]))->toThrow(InvalidArgumentException::class);
})->with([
    'infinite' => [INF],
    'not a number' => [NAN],
    'huge' => [1e30],
    'fractional' => [2.5],
    'negative' => [-1],
]);

it('refuses a host run context with a negative step budget', function (): void {
    expect(fn () => new RunBudget(-1))->toThrow(InvalidArgumentException::class);
});

it('reads a non-finite or non-numeric max_steps_ceiling as no steps, never as no cap', function (mixed $ceiling): void {
    config()->set('prism-harness.agent.max_steps_ceiling', $ceiling);
    app()->forgetInstance(AgentRuntime::class);
    g387Fresh();
    g387Pad();
    $session = g387Root()->usingMaxSteps(5);

    try {
        $session->send('Go');
    } catch (RunNotPermitted) {
    }

    expect(g387Requests())->toBe(0);
})->with([
    'infinite' => [INF],
    'huge' => [1e30],
    'not a number' => [NAN],
    'a word' => ['lots'],
]);

it('treats a non-finite or non-numeric allowance input as no steps', function (): void {
    foreach ([INF, NAN, 'x', true, [1]] as $garbage) {
        $result = RunBudgets::allowance(['context' => 'none', 'mode_steps' => $garbage, 'saved_ceiling' => null, 'context_left' => null,
            'steps_ceiling' => null, 'used' => 0, 'tree_left' => null, 'plan_left' => null, 'took' => 0]);
        expect($result['allowance']['allowance'])->toBe(0);
        $result = RunBudgets::allowance(['context' => 'host', 'mode_steps' => 8, 'saved_ceiling' => null, 'context_left' => $garbage,
            'steps_ceiling' => null, 'used' => 0, 'tree_left' => null, 'plan_left' => null, 'took' => 0]);
        expect($result['allowance']['allowance'] ?? 0)->toBe(0);
    }
});

// 4. A decision the resuming turn could not act on is refused before it is recorded.
it('refuses a decision under a context that cannot act on it, recording nothing', function (string $why): void {
    $context = match ($why) {
        'tool calls' => RunContext::root('host_run', new RunBudget(10, maxToolCalls: 0)),
        'time' => RunContext::root('host_run', new RunBudget(10, maxSeconds: 0)),
        'cancelled' => RunContext::root('host_run', new RunBudget(10)),
    };
    if ($why === 'cancelled') {
        $context->ledger->cancel(RunLedger::CANCELLED);
    }
    G360::calls([['weather', ['city' => 'Paris']]]);
    g387Pad();
    g387Root()->send('Go');
    $pending = g387Root()->thread()->pendingApprovalIds();
    $rows = g387Rows(g387Root());
    $before = g387Requests();

    expect(fn () => g387Root()->approve($pending[0], true, null, $context))->toThrow(RunNotPermitted::class);
    expect(g387Root()->thread()->pendingApprovalIds())->toBe($pending)
        ->and(g387Rows(g387Root()))->toBe($rows)
        ->and($this->ran['weather'])->toBe(0)
        ->and(g387Requests())->toBe($before);
})->with([
    'its tool-call limit spent' => ['tool calls'],
    'its time spent' => ['time'],
    'its ledger cancelled' => ['cancelled'],
]);

it('refuses a decision on a linked child that lost its binding, recording nothing', function (): void {
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    (fn () => $this->durable->put($this->key().':child', []))->call($child);
    $pending = g387Child('worker')->thread()->pendingApprovalIds();
    $rows = g387Rows(g387Child('worker'));

    expect(fn () => g387Child('worker')->approve($pending[0]))->toThrow(RunNotPermitted::class, 'no saved provider and model binding');
    expect(g387Child('worker')->thread()->pendingApprovalIds())->toBe($pending)
        ->and(g387Rows(g387Child('worker')))->toBe($rows)
        ->and($this->ran['weather'])->toBe(0);
});

it('never runs an approved call after its run tree was cancelled', function (): void {
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    $pending = $child->thread()->pendingApprovalIds();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($pending[0], true)]))->call($child);
    app(RunTrees::class)->cancelTree($child, $child->childTree());

    try {
        g387Child('worker')->send('');
    } catch (Throwable) {
    }

    expect($this->ran['weather'])->toBe(0);
});

// 5. The runner refuses an empty task, and a delegation into a child a person is answering.
it('refuses a delegation that would dismiss a person pending approval on the child', function (string $task): void {
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    $pending = $child->thread()->pendingApprovalIds();
    $rows = g387Rows($child);

    $result = app(SubagentRunner::class)->run(new Subagent('worker', 'Work.', 'work', new RunBudget(2)), g387Root(),
        RunContext::root('run_root', new RunBudget(8)), 'run_root', $task);

    expect($result->outcome->value)->toBe('denied')
        ->and(g387Child('worker')->thread()->pendingApprovalIds())->toBe($pending)
        ->and(g387Rows(g387Child('worker')))->toBe($rows);
})->with(['an empty task' => [''], 'a blank task' => ["  \n\t"], 'a new task' => ['Do something else']]);

it('refuses an empty task before building the child session', function (): void {
    $threads = Thread::query()->count();

    $result = app(SubagentRunner::class)->run(new Subagent('worker', 'Work.', 'work', new RunBudget(2)), g387Root(),
        RunContext::root('run_root', new RunBudget(8)), 'run_root', ' ');

    expect($result->outcome->value)->toBe('denied')
        ->and(Thread::query()->count())->toBe($threads)
        ->and(g387Requests())->toBe(0);
});

// 6. A refused capped stream writes nothing either.
it('refuses a new message on a capped stream with nothing left, recording nothing', function (string $path): void {
    config()->set('prism-harness.agent.max_cost_usd', 5.0);
    config()->set('prism-harness.agent.max_output_tokens_per_call', 1000);
    config()->set('prism-harness.pricing.openai.gpt-4o', ['inputPerMTok' => 2.5, 'outputPerMTok' => 10.0, 'toolOverheadTokens' => 300]);
    app()->forgetInstance(AgentRuntime::class);
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    $child->rememberChildBinding($child->childBinding(), $child->childDepth(), $child->childPlanLedger(), $child->childTree(), 0);
    $pending = g387Child('worker')->thread()->pendingApprovalIds();
    $rows = g387Rows(g387Child('worker'));
    $before = g387Requests();
    g387Fresh();

    expect(fn () => iterator_to_array($path === 'stream' ? g387Child('worker')->stream('A new question')
        : g387Child('worker')->streamSteps('A new question'), false))->toThrow(RunNotPermitted::class);
    expect(g387Rows(g387Child('worker')))->toBe($rows)
        ->and(g387Child('worker')->thread()->pendingApprovalIds())->toBe($pending)
        ->and(g387Requests())->toBe($before);
})->with(['stream', 'streamSteps']);

// New channels the round-2 fixes could open, each closed.
it('fails closed on a child whose delegation count is gone or is another delegation', function (string $how): void {
    g387ParkedChild('worker', 3);
    $child = g387Child('worker');
    $key = $child->key().':delegation-steps';
    if ($how === 'gone') {
        $child->planStore()->forget($key);
    } else {
        $child->planStore()->put($key, [...$child->planStore()->get($key), 'planId' => 'dlg_someone_else', 'steps' => 0]);
    }
    g387Echoes();
    $before = g387Requests();

    g387ApproveAll(fn (): Session => g387Child('worker'));

    expect(g387Requests())->toBe($before)
        ->and($this->ran['weather'])->toBe(1);
})->with(['gone', 'another delegation']);

it('lets the runner delegate again into a child whose only pending approval was cancelled', function (): void {
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    expect($child->cancel((string) $child->run()['id'])->value)->toBe('ok');
    G360::$queue = [];
    G360::text('Done again.');

    $result = app(SubagentRunner::class)->run(new Subagent('worker', 'Work.', 'work', new RunBudget(2)), g387Root(),
        RunContext::root('run_root', new RunBudget(8)), 'run_root', 'Start over');

    expect($result->outcome->value)->toBe('completed');
});

it('reads a whole-number max_steps_ceiling as itself', function (mixed $ceiling): void {
    config()->set('prism-harness.agent.max_steps_ceiling', $ceiling);
    app()->forgetInstance(AgentRuntime::class);
    g387Fresh();
    g387Echoes();
    config()->set('prism-harness.agent.modes.chat.tools', ['echo']);
    app()->forgetInstance(ModeRegistry::class);

    try {
        g387Root()->usingMaxSteps(5)->send('Go');
    } catch (RunNotPermitted) {
    }

    expect(g387Requests())->toBe(2);
})->with(['an integer' => [2], 'a numeric string' => ['2'], 'a whole float' => [2.0]]);

// An approved subagent call on a spent run is acted on: its result is the
// runner's framed refusal, and nothing asks the model.
it('acts on an approved subagent call on a spent run with the runner exhausted result', function (): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 1);
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['worker']);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'worker' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => 2],
    ]);
    G360::calls([['worker', ['task' => 'Go']]]);
    g387Pad();
    g387Root()->send('Go');
    $pending = g387Root()->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1);
    $before = g387Requests();

    try {
        g387Root()->approve($pending[0]);
    } catch (RunNotPermitted) {
    }

    $results = g387Root()->thread()->storedMessages()->where('type', 'tool_result')->get()
        ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])->pluck('result')->implode(' ');
    expect(g387Requests())->toBe($before)
        ->and($results)->toContain('exhausted')
        ->and(g387Root()->thread()->pendingApprovalIds())->toBe([]);
});

// ---------------------------------------------------------------------------
// G-387 round 3: the second final review's findings.
// ---------------------------------------------------------------------------

/** The bodies of every provider request so far, as one string. */
function g387Sent(): string
{
    return collect(Http::recorded())->map(fn ($pair): string => (string) $pair[0]->body())->implode("\n");
}

/** A root that may delegate to `worker` (declared `$declared`), with the given mode for it. */
function g387Delegating(int $declared = 2, int $rootSteps = 8): void
{
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.max_steps', $rootSteps);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'worker' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => $declared],
    ]);
}

function g387Delegation(Session $child): mixed
{
    return $child->planStore()->get($child->key().':delegation-steps');
}

/** Move every live hold on a durable budget record `$seconds` into the past. */
function g387Backdate(string $key, int $seconds): void
{
    $store = g387Root()->planStore();
    $state = $store->get($key);
    foreach ($state['holds'] as $id => $hold) {
        $state['holds'][$id]['at'] = $hold['at'] - $seconds;
    }
    $store->put($key, $state);
}

function g387PutDelegation(Session $child, mixed $state): void
{
    $child->planStore()->put($child->key().':delegation-steps', $state);
}

// 1. Everything the runner does before and around the child is framed:
// any exception becomes `failed` with its class name, never its message.
it('frames a store failure in the runner pre-checks, so no exception text reaches the model', function (): void {
    g387Delegating();
    g387Child('worker')->thread();
    Thread::retrieved(function (Thread $thread): void {
        if (str_contains((string) $thread->scope, '::sub::')) {
            throw new RuntimeException('SECRET-host=db.internal key=sk-live-123');
        }
    });
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::text('Handled.');
    g387Pad();

    g387Root()->send('Go');

    $outputs = implode(' ', G360::outputs());
    expect(g387Sent())->not->toContain('SECRET-host')
        ->and($outputs)->toContain('failed with RuntimeException');
});

it('denies a subagent task that is not a string, never a raw error', function (mixed $task): void {
    g387Delegating();
    G360::calls([['worker', ['task' => $task]]]);
    G360::text('Handled.');
    g387Pad();

    g387Root()->send('Go');

    $outputs = implode(' ', G360::outputs());
    expect($outputs)->toContain('"outcome":"denied"')
        ->and(g387Sent())->not->toContain('Tool execution error');
})->with(['null' => [null], 'a number' => [42], 'a list' => [['a']]]);

// 2. The durable count fails closed on anything this runtime could not have written.
it('fails closed on a delegation count with a garbage field', function (Closure $mutate): void {
    g387ParkedChild('worker', 3);
    $child = g387Child('worker');
    $state = g387Delegation($child);
    g387PutDelegation($child, $mutate($state));
    $written = g387Delegation(g387Child('worker'));
    g387Echoes();
    $before = g387Requests();

    for ($i = 0; $i < 3; $i++) {
        g387Fresh();
        try {
            g387Child('worker')->send('Question '.$i);
        } catch (RunNotPermitted) {
        }
    }

    expect(g387Requests())->toBe($before)
        // Never repaired into a permissive record.
        ->and(g387Delegation(g387Child('worker')))->toBe($written);
})->with([
    'negative steps' => [fn (array $s): array => [...$s, 'steps' => -5]],
    'string steps' => [fn (array $s): array => [...$s, 'steps' => 'x']],
    'null steps' => [fn (array $s): array => [...$s, 'steps' => null]],
    'fractional steps' => [fn (array $s): array => [...$s, 'steps' => 1.5]],
    'no steps' => [function (array $s): array {
        unset($s['steps']);

        return $s;
    }],
    'holds not a map' => [fn (array $s): array => [...$s, 'holds' => 'x']],
    'no holds' => [function (array $s): array {
        unset($s['holds']);

        return $s;
    }],
    'a negative hold' => [fn (array $s): array => [...$s, 'holds' => ['run_x' => ['steps' => -1, 'cost_micros' => 0, 'at' => time()]]]],
    'a hold with a garbage time' => [fn (array $s): array => [...$s, 'holds' => ['run_x' => ['steps' => 1, 'cost_micros' => 0, 'at' => 'x']]]],
    'max_steps garbage' => [fn (array $s): array => [...$s, 'max_steps' => 'x']],
]);

it('never repairs a delegation count that turns garbage while a turn runs', function (): void {
    g387ParkedChild('worker', 4);
    $pending = g387Child('worker')->thread()->pendingApprovalIds();
    g387Echoes();
    $fired = false;
    G360::$onRequest = function () use (&$fired): void {
        if (! $fired) {
            $fired = true;
            g387PutDelegation(g387Child('worker'), [...g387Delegation(g387Child('worker')), 'steps' => 'x']);
        }
    };

    try {
        g387Child('worker')->approve($pending[0]);
    } catch (RunNotPermitted) {
    }
    G360::$onRequest = null;

    expect(g387Delegation(g387Child('worker'))['steps'])->toBe('x');
});

// 4. A settle replaces the run's own charge, live or expired: counted once.
it('counts an expired-then-settled hold once, so a 400 s turn that took 1 of 4 leaves 3', function (): void {
    g387ParkedChild('worker', 5);
    $pending = g387Child('worker')->thread()->pendingApprovalIds();
    g387Pad();
    $fired = false;
    G360::$onRequest = function () use (&$fired): void {
        if (! $fired) {
            $fired = true;
            // 400 s on: the live run's hold is past the lock TTL (300 s).
            g387Backdate(g387Child('worker')->key().':delegation-steps', 400);
            // Another claimant on the count then charges that hold in full.
            app(RunTrees::class)->claimDelegation(g387Child('worker'), 'run_other', RunLedger::start('other'), fn (int $used): int => 0);
        }
    };
    try {
        g387Child('worker')->approve($pending[0]);
    } catch (RunNotPermitted) {
    }
    G360::$onRequest = null;
    expect(g387Delegation(g387Child('worker'))['steps'])->toBe(2);

    g387Echoes();
    $before = g387Requests();
    g387ApproveAll(fn (): Session => g387Child('worker'), null, 1);

    expect(g387Requests() - $before)->toBe(3);
});

it('counts a tree hold that expired while its run was live once, at what it took', function (): void {
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.max_steps', 20);
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
    $tree = g387Child('worker_a')->childTree();
    $key = $tree['session'].':run-tree:'.$tree['run'];
    $settled = g387Root()->planStore()->get($key)['steps'];
    $a = g387Child('worker_a')->thread()->pendingApprovalIds();
    $b = g387Child('worker_b')->thread()->pendingApprovalIds();
    $fired = false;
    G360::$onRequest = function () use (&$fired, $b, $key): void {
        if (! $fired) {
            $fired = true;
            // 400 s on: A's live hold on the tree is past the lock TTL, and
            // B's resume (another session, another lock) expires it.
            g387Backdate($key, 400);
            try {
                g387Child('worker_b')->approve($b[0]);
            } catch (RunNotPermitted) {
            }
        }
    };

    try {
        g387Child('worker_a')->approve($a[0]);
    } catch (RunNotPermitted) {
    }
    G360::$onRequest = null;

    // Each child took one model step: the tree counts two, never A's hold in full.
    expect(g387Root()->planStore()->get($key)['steps'])->toBe($settled + 2);
});

// 5. No re-delegation over a running turn, and no second claim by one run.
it('refuses a delegation into a child while a turn on it holds its count', function (): void {
    g387ParkedChild('worker', 4);
    $pending = g387Child('worker')->thread()->pendingApprovalIds();
    g387Pad();
    $result = null;
    $holds = null;
    G360::$onRequest = function () use (&$result, &$holds): void {
        if ($result === null) {
            $holds = g387Delegation(g387Child('worker'))['holds'];
            $result = app(SubagentRunner::class)->run(new Subagent('worker', 'Work.', 'work', new RunBudget(4)), g387Root(),
                RunContext::root('run_root', new RunBudget(8)), 'run_root', 'Start over');
        }
    };

    try {
        g387Child('worker')->approve($pending[0]);
    } catch (RunNotPermitted) {
    }
    G360::$onRequest = null;

    expect($result?->outcome->value)->toBe('denied')
        ->and($holds)->not->toBe([]);
});

it('refuses a second claim on the count by a run that already holds', function (): void {
    g387ParkedChild('worker', 4);
    $trees = app(RunTrees::class);
    $ledger = RunLedger::start('x');

    $first = $trees->claimDelegation(g387Child('worker'), 'run_twice', $ledger, fn (int $used): int => 1);
    $second = $trees->claimDelegation(g387Child('worker'), 'run_twice', $ledger, fn (int $used): int => 1);

    expect($first)->toBe(1)->and($second)->toBe(-1);
});

// 6. Blank is one explicit codepoint set, the same in all three languages.
function g387Blanks(): array
{
    $codepoints = [...range(0x09, 0x0D), 0x20, 0x85, 0xA0, 0x1680, ...range(0x2000, 0x200D), 0x2028, 0x2029, 0x202F, 0x205F, 0x2060, 0x3000, 0xFEFF];
    $rows = [];
    foreach ($codepoints as $codepoint) {
        $rows[sprintf('U+%04X', $codepoint)] = [mb_chr($codepoint, 'UTF-8')];
    }
    $rows['a mixed run'] = [mb_chr(0x20, 'UTF-8').mb_chr(0xA0, 'UTF-8').mb_chr(0x200B, 'UTF-8').mb_chr(0x3000, 'UTF-8').mb_chr(0xFEFF, 'UTF-8')."\t\n"];

    return $rows;
}

it('refuses a task made only of blank codepoints', function (string $task): void {
    $result = app(SubagentRunner::class)->run(new Subagent('worker', 'Work.', 'work', new RunBudget(2)), g387Root(),
        RunContext::root('run_root', new RunBudget(8)), 'run_root', $task);

    expect($result->outcome->value)->toBe('denied')
        ->and($result->reason)->toBe('the task is empty')
        ->and(g387Requests())->toBe(0);
})->with(g387Blanks());

it('delegates a task with any character outside the blank set', function (string $task): void {
    G360::text('Done.');

    $result = app(SubagentRunner::class)->run(new Subagent('worker', 'Work.', 'work', new RunBudget(2)), g387Root(),
        RunContext::root('run_root', new RunBudget(8)), 'run_root', $task);

    expect($result->outcome->value)->toBe('completed');
})->with([
    'a letter among blanks' => [mb_chr(0xA0, 'UTF-8').'a'.mb_chr(0x3000, 'UTF-8')],
    'U+200E' => [mb_chr(0x200E, 'UTF-8')],
    'U+180E' => [mb_chr(0x180E, 'UTF-8')],
]);

// 7. A pending approval blocks re-delegation only while a person could answer it.
it('lets the runner start again a child stuck on an approval nobody can answer', function (): void {
    g387ParkedChild('worker', 2);
    $child = g387Child('worker');
    // The binding is gone: a decision on it would be refused (round 2, item 4).
    (fn () => $this->durable->put($this->key().':child', []))->call($child);
    G360::$queue = [];
    G360::text('Done again.');

    $result = app(SubagentRunner::class)->run(new Subagent('worker', 'Work.', 'work', new RunBudget(2)), g387Root(),
        RunContext::root('run_root', new RunBudget(8)), 'run_root', 'Start over');

    expect($result->outcome->value)->toBe('completed');
});

// 9. A denial runs nothing, so a spent tool-call or time limit does not refuse it.
it('records a denial under a context whose tool-call or time limit is spent, and refuses an approval', function (string $limit): void {
    G360::calls([['weather', ['city' => 'Paris']]]);
    g387Pad();
    g387Root()->send('Go');
    $pending = g387Root()->thread()->pendingApprovalIds();
    $context = fn (): RunContext => RunContext::root('host_run', $limit === 'tool calls'
        ? new RunBudget(10, maxToolCalls: 0) : new RunBudget(10, maxSeconds: 0));

    expect(fn () => g387Root()->approve($pending[0], true, null, $context()))->toThrow(RunNotPermitted::class);
    expect(g387Root()->thread()->pendingApprovalIds())->toBe($pending);

    try {
        g387Root()->deny($pending[0], 'No.', $context());
    } catch (RunNotPermitted) {
    }

    expect(g387Root()->approvalStatus($pending[0]))->toBe('denied')
        ->and($this->ran['weather'])->toBe(0);
})->with(['tool calls', 'time']);

// 3. A child parked by the RELEASED code (v0.26.8: a linked thread, a
// `::sub::` scope, nothing else saved), resumed by this code. The rows are
// recorded from a run of the released code (tests/Fixtures/legacy-children).
function g387LoadLegacyChild(bool $keepParentLink = true): void
{
    $dump = json_decode((string) file_get_contents(__DIR__.'/Fixtures/legacy-children/parked-child-v0.26.8.json'), true);
    foreach ($dump['tables'] as $table => $rows) {
        $columns = Schema::getColumnListing($table);
        foreach ($rows as $row) {
            $row = array_intersect_key($row, array_flip($columns));
            if ($table === 'harness_threads' && ! $keepParentLink) {
                $row['parent_thread_id'] = null;
            }
            DB::table($table)->insert($row);
        }
    }
}

it('refuses, recording nothing, a decision on a child the released code parked', function (): void {
    g387LoadLegacyChild();
    $child = g387Child('worker');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1);
    $rows = g387Rows($child);
    g387Pad();

    expect(fn () => g387Child('worker')->approve($pending[0]))->toThrow(RunNotPermitted::class, 'no saved provider and model binding');
    expect(g387Child('worker')->thread()->pendingApprovalIds())->toBe($pending)
        ->and(g387Rows(g387Child('worker')))->toBe($rows)
        ->and(g387Requests())->toBe(0);
});

it('fails closed on a released child that lost even its parent link: its address is a child\'s', function (): void {
    g387LoadLegacyChild(keepParentLink: false);
    expect(g387Child('worker')->thread()->parent_thread_id)->toBeNull()
        ->and(g387Child('worker')->childBinding())->toBeNull();
    $pending = g387Child('worker')->thread()->pendingApprovalIds();
    g387Pad();

    try {
        g387Child('worker')->approve($pending[0]);
    } catch (RunNotPermitted) {
    }

    // Its declared 2 (1 taken) is not known here: it gets no model step, never its mode's 8.
    expect(g387Requests())->toBe(0)
        ->and($this->ran['weather'])->toBe(1);
});
