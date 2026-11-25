<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Events\PlanBudgetExhausted;
use Prism\Harness\Exceptions\PlanRefused;
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
        ->toThrow(Prism\Harness\Exceptions\RunNotPermitted::class, 'plan_budget_exhausted');
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
