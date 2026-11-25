<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Events\PlanBudgetExhausted;
use Prism\Harness\Exceptions\PlanRefused;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Plans\PlanMachine;
use Prism\Harness\Plans\Plans;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Support\G360;

// G-366: a plan's budget is the app's choice (Wish, q_fb920047): one shared
// budget for all of a plan's steps, the per-turn budget per step (the
// default), or no plan-level budget. Driven through the public session API
// over Prism's real OpenAI handler on faked HTTP with stray requests refused.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather', 'lookup']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    config()->set('prism-harness.agent.modes.chat.plans', true);
    config()->set('prism-harness.plans.enabled', true);

    $this->lookups = 0;
    $this->weatherRan = 0;
    $lookups = &$this->lookups;
    $weather = &$this->weatherRan;
    app(ToolRegistry::class)->register((new Tool)->as('lookup')->for('Look something up.')->withStringParameter('q', 'Query')
        ->using(function (string $q) use (&$lookups): string {
            $lookups++;

            return 'Found';
        }));
    app(ToolRegistry::class)->register((new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$weather): string {
            $weather++;

            return 'Sunny';
        }));
});

/** Draft a plan of $count steps and have the person approve it. */
function g366Active(int $count = 3): void
{
    G360::calls([['plan_draft', ['goal' => 'Plan the trip', 'steps' => G360::steps($count)]]]);
    $pending = G360::session()->send('Plan the trip')->pendingApprovals()[0];
    G360::text('Plan approved.');
    G360::session()->approve($pending->approvalId);
}

/** One step's turn: `$lookups` lookup calls, then the report, then text: lookups + 2 model steps. */
function g366Step(int $lookups, string $summary): void
{
    for ($i = 0; $i < $lookups; $i++) {
        G360::calls([['lookup', ['q' => 'q'.$i]]]);
    }
    G360::calls([['plan_complete_step', ['summary' => $summary]]]);
    G360::text('Step done.');
}

function g366Plan(): array
{
    return G360::session()->plan()->current()?->toArray() ?? [];
}

it('keeps per_step as the default: every step gets the whole per-turn budget and nothing is recorded', function (): void {
    config()->set('prism-harness.plans.budget', null);
    g366Active(2);
    $before = count(Http::recorded());

    g366Step(3, 'one');
    expect(G360::session()->plan()->advance()->status)->toBe('ran');
    g366Step(3, 'two');
    expect(G360::session()->plan()->advance()->status)->toBe('ran');
    expect(G360::session()->plan()->advance()->status)->toBe('done');

    // 5 model steps per step turn, both taken in full.
    expect(count(Http::recorded()) - $before)->toBe(10)
        ->and($this->lookups)->toBe(6)
        ->and(G360::session()->plan()->budget())->toMatchArray(['mode' => 'per_step', 'steps' => 0])
        ->and(G360::session()->planStore()->get(G360::session()->key().':plan-budget'))->toBeNull();
});

it('ignores configured limits under per_step: they apply only to a shared budget', function (): void {
    config()->set('prism-harness.plans.budget', ['mode' => 'per_step', 'max_steps' => 1]);
    g366Active(1);
    g366Step(2, 'one');
    G360::session()->plan()->advance();

    expect($this->lookups)->toBe(2)
        ->and(G360::session()->plan()->advance()->status)->toBe('done');
});

it('keeps the per-turn step limit and the plan advance limit under none', function (): void {
    config()->set('prism-harness.plans.budget', ['mode' => 'none']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 2);
    config()->set('prism-harness.plans.max_advances', 2);
    g366Active(3);
    $before = count(Http::recorded());

    // The step wants four model steps; its turn stops at the per-turn two.
    g366Step(2, 'one');
    G360::session()->plan()->advance();
    expect(count(Http::recorded()) - $before)->toBe(2)
        ->and($this->lookups)->toBe(2);

    G360::$queue = [];
    g366Step(0, 'two');
    G360::session()->plan()->advance();
    // The advance limit still stops the plan.
    $outcome = G360::session()->plan()->advance();
    expect($outcome->status)->toBe('blocked')
        ->and($outcome->reason)->toBe('budget');
});

it('stops a plan whose shared budget is spent across several steps, once, with plan_budget_exhausted', function (): void {
    Event::fake([PlanBudgetExhausted::class]);
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => 5]);
    g366Active(3);
    $before = count(Http::recorded());

    g366Step(1, 'one');                         // 3 model steps
    G360::session()->plan()->advance();
    g366Step(3, 'two');                         // wants 5; 2 are left
    G360::session()->plan()->advance();

    expect(count(Http::recorded()) - $before)->toBe(5)
        ->and($this->lookups)->toBe(3)
        ->and(G360::session()->plan()->budget())->toMatchArray(['mode' => 'shared', 'max_steps' => 5, 'steps' => 5]);

    $outcome = G360::session()->plan()->advance();
    expect($outcome->status)->toBe('blocked')
        ->and($outcome->reason)->toBe('plan_budget_exhausted')
        ->and(g366Plan()['status'])->toBe('blocked')
        ->and(g366Plan()['blockedReason'])->toBe('plan_budget_exhausted')
        ->and(g366Plan()['steps'][0]['state'])->toBe('done');
    Event::assertDispatchedTimes(PlanBudgetExhausted::class, 1);
    Event::assertDispatched(PlanBudgetExhausted::class, fn (PlanBudgetExhausted $event): bool => $event->code === 'plan_budget_exhausted' && $event->limit === 'steps' && $event->steps === 5);

    // Spent is spent: no resume, no further model call, no second event.
    expect(fn () => G360::session()->plan()->resume(RunContext::metadata(actor: 'user:ada')))
        ->toThrow(PlanRefused::class, 'cannot be resumed');
    expect(G360::session()->plan()->advance()->status)->toBe('not_active')
        ->and(count(Http::recorded()) - $before)->toBe(5);
    Event::assertDispatchedTimes(PlanBudgetExhausted::class, 1);
});

it('continues one shared ledger across an approval pause and a restart, without counting a step twice', function (): void {
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => 4]);
    g366Active(2);
    $before = count(Http::recorded());

    G360::calls([['weather', ['city' => 'Paris']]]);   // 1 model step, then the pause
    G360::session()->plan()->advance();
    $held = g366Plan()['steps'][0];
    expect($held['state'])->toBe('held')
        ->and(G360::session()->plan()->budget()['steps'])->toBe(1);

    // A new process: nothing of the ledger is held in memory.
    app()->forgetInstance(Plans::class);
    app()->singleton(Plans::class, fn (): Plans => new Plans);

    // The resumed turn may take the 3 the plan has left, not a fresh 8.
    G360::calls([['lookup', ['q' => 'a']]]);
    G360::calls([['lookup', ['q' => 'b']]]);
    G360::calls([['lookup', ['q' => 'c']]]);
    G360::calls([['lookup', ['q' => 'd']]]);
    G360::session()->decide([new ToolApprovalResponse($held['approvalIds'][0], true)], RunContext::metadata(actor: 'user:ada'));

    expect($this->weatherRan)->toBe(1)
        ->and($this->lookups)->toBe(3)
        ->and(count(Http::recorded()) - $before)->toBe(4)
        ->and(G360::session()->plan()->budget()['steps'])->toBe(4);

    G360::$queue = [];
    $outcome = G360::session()->plan()->advance();
    expect($outcome->status)->toBe('blocked')
        ->and($outcome->reason)->toBe('plan_budget_exhausted')
        ->and(count(Http::recorded()) - $before)->toBe(4);
});

it('lets only one of two concurrent advances run, and charges only the one that ran', function (): void {
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => 4]);
    g366Active(2);
    $second = null;
    $lookups = &$this->lookups;
    app(ToolRegistry::class)->register((new Tool)->as('lookup')->for('Look something up.')->withStringParameter('q', 'Query')
        ->using(function (string $q) use (&$second, &$lookups): string {
            $lookups++;
            // A second worker advances the same plan while this turn runs.
            try {
                G360::session()->plan()->advance();
                $second = 'ran';
            } catch (SessionLocked) {
                $second = 'locked';
            }

            return 'Found';
        }));

    g366Step(1, 'one');
    G360::session()->plan()->advance();

    expect($second)->toBe('locked')
        ->and(G360::session()->plan()->budget()['steps'])->toBe(3);
});

it('never grants two turns the same remainder of a shared budget', function (): void {
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => 10]);
    config()->set('prism-harness.agent.modes.chat.max_steps', 8);
    g366Active(1);
    $plans = app(Plans::class);
    $session = G360::session();
    // Two turns that both passed their locks (two workers, two processes):
    // the ledger itself holds what each was granted.
    $plan = $plans->load($session);
    $claimed = PlanMachine::claim($plan, $plan['steps'][0]['id'], 'worker', $plans->now() + 300, $plans->now());
    $plans->write($session, $claimed, $plan['revision']);

    $first = $plans->planTurn($session, 'run_a', RunContext::root('run_a', new RunBudget(8)), null);
    $other = $plans->planTurn($session, 'run_b', RunContext::root('run_b', new RunBudget(8)), null);
    expect($first->budget->maxSteps)->toBe(8)
        ->and($other->budget->maxSteps)->toBe(2)
        ->and(fn () => $plans->planTurn($session, 'run_c', RunContext::root('run_c', new RunBudget(8)), null))
        ->toThrow(RunNotPermitted::class, 'plan_budget_exhausted');
});

it('ignores budget fields the model sends and refuses a run tree handed to a shared plan step', function (): void {
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => 3]);

    // The model cannot choose a budget: a draft that carries one is refused.
    G360::calls([['plan_draft', ['goal' => 'Plan', 'steps' => G360::steps(1), 'budget' => ['mode' => 'none', 'max_steps' => 999]]]]);
    G360::text('ok');
    G360::session()->send('Plan');
    expect(G360::session()->plan()->current())->toBeNull();

    g366Active(2);
    // A per-call run tree does not widen it either: refused before any call.
    $before = count(Http::recorded());
    G360::text('unused');
    expect(fn () => G360::session()->plan()->advance(RunContext::root('host', new RunBudget(100))))
        ->toThrow(PlanRefused::class);
    expect(count(Http::recorded()))->toBe($before)
        ->and(G360::session()->plan()->budget())->toMatchArray(['mode' => 'shared', 'max_steps' => 3]);
});

it('lets host code choose a plan budget, fixes it on first use, and only lowers it after', function (): void {
    config()->set('prism-harness.plans.budget', ['mode' => 'per_step', 'max_steps' => 50]);
    g366Active(2);
    $plan = G360::session()->plan();

    $plan->useBudget(['mode' => 'shared', 'max_steps' => 2]);
    expect($plan->budget())->toMatchArray(['mode' => 'shared', 'max_steps' => 2]);

    $plan->useBudget(['mode' => 'shared', 'max_steps' => 40]);   // raising is ignored
    expect($plan->budget()['max_steps'])->toBe(2);
    expect(fn () => $plan->useBudget(['mode' => 'none']))->toThrow(PlanRefused::class);

    g366Step(2, 'one');
    G360::session()->plan()->advance();
    expect($this->lookups)->toBe(2)
        ->and(G360::session()->plan()->advance()->reason)->toBe('plan_budget_exhausted');
});

it('refuses an invalid plan budget in config before anything runs', function (mixed $budget): void {
    config()->set('prism-harness.plans.budget', $budget);

    expect(fn () => G360::session()->plan())->toThrow(InvalidArgumentException::class);
})->with([
    'unknown mode' => [['mode' => 'unlimited']],
    'shared without a limit' => [['mode' => 'shared']],
    'zero steps' => [['mode' => 'shared', 'max_steps' => 0]],
    'steps as text' => [['mode' => 'shared', 'max_steps' => '5']],
    'negative cost' => [['mode' => 'shared', 'max_steps' => 5, 'max_cost_usd' => -1]],
    'unknown key' => [['mode' => 'shared', 'max_steps' => 5, 'per_call' => true]],
    'not a map' => ['shared'],
]);

// Only the plan's own turns spend from a shared budget: the turns advance()
// runs, and the resume of an approval its held step asked for. A person
// chatting while a step waits is not the plan, and is never refused for it.

/** A shared plan whose first step paused on a weather approval after one model step. */
function g366Held(int $maxSteps): string
{
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => $maxSteps]);
    g366Active(2);
    G360::calls([['weather', ['city' => 'Paris']]]);
    G360::session()->plan()->advance();
    $step = g366Plan()['steps'][0];
    expect($step['state'])->toBe('held')
        ->and(G360::session()->plan()->budget()['steps'])->toBe(1);

    return $step['approvalIds'][0];
}

it('does not charge a shared plan for a chat turn while its step is held', function (): void {
    g366Held(10);

    G360::calls([['lookup', ['q' => 'a']]]);
    G360::calls([['lookup', ['q' => 'b']]]);
    G360::text('Unrelated answer.');
    G360::session()->send('Unrelated question');

    expect($this->lookups)->toBe(2)
        ->and(G360::session()->plan()->budget()['steps'])->toBe(1);
});

it('lets the person chat when a shared budget is spent while a step is held', function (): void {
    g366Held(1);

    G360::text('Unrelated answer.');
    expect(G360::session()->send('Unrelated question')->text())->toBe('Unrelated answer.')
        ->and(G360::session()->plan()->budget()['steps'])->toBe(1);
});

it('does not charge a shared plan for a turn while its step is claimed by no running advance', function (): void {
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => 10]);
    g366Active(1);
    $plans = app(Plans::class);
    $session = G360::session();
    // A worker claimed the step and died before its turn ran.
    $plan = $plans->load($session);
    $plans->write($session, PlanMachine::claim($plan, $plan['steps'][0]['id'], 'worker', $plans->now() + 300, $plans->now()), $plan['revision']);

    G360::calls([['lookup', ['q' => 'a']]]);
    G360::text('Unrelated answer.');
    G360::session()->send('Unrelated question');

    expect($this->lookups)->toBe(1)
        ->and(G360::session()->plan()->budget()['steps'])->toBe(0);
});

it('refuses an approval a spent shared budget cannot run, records nothing, and blocks the plan once', function (): void {
    Event::fake([PlanBudgetExhausted::class]);
    $held = g366Held(1);
    $before = count(Http::recorded());

    expect(fn () => G360::session()->approve($held))->toThrow(RunNotPermitted::class, 'plan_budget_exhausted');
    expect(G360::session()->thread()->pendingApprovalIds())->toBe([$held])
        ->and($this->weatherRan)->toBe(0)
        ->and(count(Http::recorded()))->toBe($before);

    $outcome = G360::session()->plan()->advance();
    expect($outcome->status)->toBe('blocked')
        ->and($outcome->reason)->toBe('plan_budget_exhausted');
    Event::assertDispatchedTimes(PlanBudgetExhausted::class, 1);
    expect(G360::session()->plan()->advance()->status)->toBe('not_active');
    Event::assertDispatchedTimes(PlanBudgetExhausted::class, 1);

    // Still refused once the plan is blocked: the call never runs outside the budget.
    expect(fn () => G360::session()->decide([new ToolApprovalResponse($held, true)]))
        ->toThrow(RunNotPermitted::class, 'plan_budget_exhausted');
    expect(G360::session()->thread()->pendingApprovalIds())->toBe([$held])
        ->and($this->weatherRan)->toBe(0)
        ->and(count(Http::recorded()))->toBe($before);
});

it('closes the run when a shared plan budget cannot be written', function (): void {
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => 10]);
    g366Active(1);
    // The durable store fails on the plan budget record, and only there.
    DB::statement("CREATE TRIGGER g366_budget_down BEFORE INSERT ON harness_session_state
        WHEN NEW.key LIKE '%:plan-budget' AND NEW.key NOT LIKE 'lock:%'
        BEGIN SELECT RAISE(ABORT, 'store down'); END");
    G360::text('unused');

    expect(fn () => G360::session()->plan()->advance())->toThrow(Illuminate\Database\QueryException::class);
    expect(G360::session()->run()['status'])->toBe('failed');
});

// A subagent child that pauses on an approval is resumed later on its own
// session, outside the runner. When the turn that started it spent from a
// shared plan budget, the resumed child spends from the same one.

function g366ChildModes(): void
{
    config()->set('prism-harness.agent.modes.research', [
        'system_prompt' => 'Research.', 'tools' => ['weather'], 'requires_approval' => ['weather'], 'max_steps' => 4,
    ]);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'research' => ['description' => 'Research something.', 'mode' => 'research'],
    ]);
}

/** A plan step that delegates; the child pauses on weather. Returns the child session and its approval id. */
function g366PausedChild(int $maxSteps): array
{
    g366ChildModes();
    config()->set('prism-harness.plans.budget', ['mode' => 'shared', 'max_steps' => $maxSteps]);
    g366Active(1);
    G360::calls([['research', ['task' => 'Find the weather.']]]);   // parent
    G360::calls([['weather', ['city' => 'Paris']]]);                 // child: pauses
    G360::calls([['plan_complete_step', ['summary' => 'Asked.']]]);  // parent
    G360::text('Step done.');                                        // parent
    G360::session()->plan()->advance();

    $child = app(Prism\Harness\PrismHarness::class)
        ->for(Tests\Fixtures\Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat::sub::research');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1)
        ->and(G360::session()->plan()->budget()['steps'])->toBe(4);

    return [$child, $pending[0]];
}

it('charges a child resumed outside the runner to the shared plan budget it was started under', function (): void {
    [$child, $id] = g366PausedChild(10);

    G360::text('Child answer.');
    $child->approve($id);

    expect($this->weatherRan)->toBe(1)
        ->and(G360::session()->plan()->budget()['steps'])->toBe(5);
});

it('refuses, recording nothing, an approval for a child whose shared plan budget is spent', function (): void {
    [$child, $id] = g366PausedChild(4);
    $before = count(Http::recorded());

    expect(fn () => $child->approve($id))->toThrow(RunNotPermitted::class, 'plan_budget_exhausted');
    expect($child->thread()->pendingApprovalIds())->toBe([$id])
        ->and($this->weatherRan)->toBe(0)
        ->and(count(Http::recorded()))->toBe($before);
});
