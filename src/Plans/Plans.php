<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

use Closure;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Events\PlanBudgetExhausted;
use Prism\Harness\Events\PlanUpdated;
use Prism\Harness\Exceptions\PlanRefused;
use Prism\Harness\Exceptions\PlansDisabled;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Tools\CanonicalJson;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use RuntimeException;
use Throwable;

/**
 * Agent plans (G-360): the store, the agent's tools, and the hooks the
 * runtime calls at the moments a plan has to look at the thread.
 *
 * OFF UNLESS BOTH SWITCHES ARE ON: `prism-harness.plans.enabled` and the
 * mode's `plans` flag. Off, no plan tool is offered, no context is added, no
 * plan step is emitted and Session::plan() throws PlansDisabled.
 *
 * A person approves a plan before it runs. That is on by default, and only
 * the host's config (`plans.require_approval`) turns it off: never a per-call
 * option, never anything the model can send.
 *
 * Bound as a singleton; config is read when it is used, so a host that
 * changes config at runtime is read correctly.
 */
final class Plans
{
    public const INDEX_KEY = 'prism-harness:plans:live';

    public const HEARTBEAT_KEY = 'prism-harness:plans:heartbeat';

    /** @var array<string, array<int, Closure(array<string, mixed>): void>> */
    private array $watchers = [];

    private int $watcherSeq = 0;

    /**
     * Turns spending from a shared plan budget, by run id, until they settle.
     *
     * @var array<string, array{session: string, ledger: string, plan: string, context: RunContext, steps: int, cost: float, unmetered: int}>
     */
    private array $budgetTurns = [];

    /**
     * Sessions whose advance() is running the turn of the step it claimed,
     * counted per session so a nested hold releases only its own.
     *
     * @var array<string, int>
     */
    private array $advancing = [];

    /** @var (Closure(string, string): void)|null */
    private ?Closure $reapDispatcher = null;

    /** @var (Closure(): int)|null */
    private ?Closure $clock = null;

    public function __construct(private readonly ?PlanIds $ids = null) {}

    public function enabled(): bool
    {
        return config('prism-harness.plans.enabled', false) === true;
    }

    public function limits(): PlanLimits
    {
        $config = config('prism-harness.plans', []);

        return PlanLimits::fromConfig(is_array($config) ? $config : []);
    }

    /**
     * The configured plan budget (G-366), validated: an invalid mode or limit
     * throws here, before any step runs.
     *
     * @return array{mode: string, max_steps: ?int, max_cost_micros: ?int}
     */
    public function budgetSettings(): array
    {
        return PlanBudget::settings(config('prism-harness.plans.budget'));
    }

    /** Whether this session's current mode runs with plans. */
    public function enabledFor(Session $session, ?string $mode = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        try {
            return app(ModeRegistry::class)->forAgent($session->agent())->resolve($mode ?? $session->mode())->plans;
        } catch (Throwable) {
            return false;
        }
    }

    public function handle(Session $session): PlanHandle
    {
        if (! $this->enabledFor($session)) {
            throw PlansDisabled::forSession();
        }
        // An invalid plan budget fails here, before anything can run under it.
        $this->budgetSettings();

        return new PlanHandle($session, $this);
    }

    public function ids(): PlanIds
    {
        return $this->ids ?? new PlanIds;
    }

    /** @internal Tests pin the clock. */
    public function useClock(?Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    // ------------------------------------------------------------------
    // Store
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function load(Session $session): ?array
    {
        $payload = $session->planStore()->get(self::key($session->key()));
        $plan = $payload['plan'] ?? null;

        return is_array($plan) ? $plan : null;
    }

    /**
     * Write a plan, compare-and-set on its revision. Refused with
     * `plan.conflict` when the stored revision is not the one read.
     *
     * @param  array<string, mixed>  $plan
     */
    public function write(Session $session, array $plan, ?int $expectedRevision): void
    {
        $store = $session->planStore();
        $key = self::key($session->key());
        $store->withLock($key, function () use ($store, $key, $plan, $expectedRevision): void {
            $current = $store->get($key)['plan'] ?? null;
            $revision = is_array($current) ? ($current['revision'] ?? null) : null;
            if ($revision !== $expectedRevision && ! ($expectedRevision === null && is_array($current) && ! PlanMachine::isLive($current))) {
                throw PlanRefused::because('plan.conflict');
            }
            $store->put($key, ['plan' => $plan]);
        }, 10, 5);

        $this->indexed($session->key(), $plan);
        $this->announce($session, $plan);
    }

    /**
     * Load, transform, and write when the transform changed anything.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>|null
     */
    public function mutate(Session $session, Closure $change): ?array
    {
        $plan = $this->load($session);
        if ($plan === null) {
            return null;
        }
        $next = $change($plan);
        if ($next !== $plan) {
            $this->write($session, $next, $plan['revision']);
        }

        return $next;
    }

    /**
     * The plan brought in line with the thread, written when it moved.
     *
     * @return array<string, mixed>|null
     */
    public function reconciled(Session $session, bool $superseding = false): ?array
    {
        $plan = $this->load($session);
        if (! PlanMachine::isLive($plan)) {
            return $plan;
        }
        $thread = $session->thread();
        $pending = $superseding ? [] : $thread->pendingApprovalCalls();
        $decided = $thread->approvalDecisions();

        return $this->mutate($session, fn (array $plan): array => PlanMachine::reconcile($plan, $pending, $decided, $this->now(), $this->runId($session), $superseding));
    }

    private static function key(string $sessionKey): string
    {
        return $sessionKey.':plan';
    }

    /** @param array<string, mixed> $plan */
    private function indexed(string $sessionKey, array $plan): void
    {
        $store = $this->indexStore();
        if ($store === null) {
            return;
        }
        $store->withLock(self::INDEX_KEY, function () use ($store, $sessionKey, $plan): void {
            $index = $store->get(self::INDEX_KEY) ?? [];
            if (PlanMachine::isLive($plan)) {
                $index[$sessionKey] = $plan['id'];
            } else {
                unset($index[$sessionKey]);
            }
            $store->put(self::INDEX_KEY, $index);
        }, 10, 5);
    }

    private function indexStore(): ?SessionStore
    {
        try {
            return app(SessionStoreManager::class)->durable();
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Events and stream watchers
    // ------------------------------------------------------------------

    /**
     * Watch one session's plan writes, for a streamed run.
     *
     * @param  Closure(array<string, mixed>): void  $watcher
     * @return Closure(): void the unwatch
     */
    public function watch(string $sessionKey, Closure $watcher): Closure
    {
        $id = ++$this->watcherSeq;
        $this->watchers[$sessionKey][$id] = $watcher;

        return function () use ($sessionKey, $id): void {
            unset($this->watchers[$sessionKey][$id]);
        };
    }

    /** @param array<string, mixed> $plan */
    private function announce(Session $session, array $plan): void
    {
        foreach ($this->watchers[$session->key()] ?? [] as $watcher) {
            $watcher($plan);
        }
        try {
            event(new PlanUpdated(
                sessionKey: $session->key(),
                runId: $plan['lastRunId'] ?? '',
                planId: $plan['id'],
                revision: $plan['revision'],
                status: $plan['status'],
                holdingApprovalId: $plan['holdingApprovalId'],
                counts: PlanView::counts($plan),
                harnessAgent: $session->agent(),
            ));
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    // ------------------------------------------------------------------
    // Runtime hooks
    // ------------------------------------------------------------------

    /**
     * After the runtime records a turn: bind the approvals the turn staged,
     * and notice the ones a new message superseded.
     */
    public function afterTurn(Session $session): void
    {
        $this->settleTurn($session);
        if (! $this->enabled()) {
            return;
        }
        try {
            $this->reconciled($session);
        } catch (Throwable $failure) {
            // The run is over either way; the next advance reconciles again.
            self::reportQuietly($failure);
        }
    }

    /** After decisions are recorded, before the run resumes. */
    public function decided(Session $session): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $this->reconciled($session);
        } catch (Throwable $failure) {
            // The decision is recorded; the plan reads it again at the end of
            // the run and on the next advance.
            self::reportQuietly($failure);
        }
    }

    /**
     * The plan context for the turn about to start. A turn with a new user
     * message ends every pending approval, so the plan is reconciled as if
     * nothing were pending before it is rendered.
     */
    public function context(Session $session, string $prompt): string
    {
        if (! $this->enabledFor($session)) {
            return '';
        }
        try {
            $plan = $this->reconciled($session, superseding: $prompt !== '');
        } catch (Throwable $failure) {
            self::reportQuietly($failure);

            return '';
        }

        return $plan === null ? '' : PlanView::context($plan, $this->limits());
    }

    /**
     * Report a failure by its class only. A store error's message can carry
     * the SQL and its bindings, which here would be plan text the model wrote,
     * and plan text never goes into a log.
     */
    private static function reportQuietly(Throwable $failure): void
    {
        report(new RuntimeException('A plan update failed: '.$failure::class.'.'));
    }

    /**
     * The plan tools for a run in this session, or none when plans are off.
     *
     * @return array<string, Tool>
     */
    public function tools(Session $session, ?string $mode): array
    {
        if (! $this->enabledFor($session, $mode)) {
            return [];
        }
        // No plan can be drafted under an invalid plan budget (G-366).
        $this->budgetSettings();

        return (new PlanTools($this, $session))->all();
    }

    /** @internal */
    public function runId(Session $session): ?string
    {
        $run = $session->run();

        return is_array($run) && is_string($run['id'] ?? null) && ($run['status'] ?? null) === 'running' ? $run['id'] : null;
    }

    // ------------------------------------------------------------------
    // Plan budget (G-366)
    // ------------------------------------------------------------------

    /**
     * The budget record of this plan, or null when none was fixed yet.
     *
     * @return array<string, mixed>|null
     */
    public function budgetRecord(Session $session, string $planId): ?array
    {
        return $this->budgetRecordAt($session->planStore(), $session->key(), $planId);
    }

    /** @return array<string, mixed>|null */
    private function budgetRecordAt(SessionStore $store, string $sessionKey, string $planId): ?array
    {
        $record = $store->get(self::budgetKey($sessionKey));

        return is_array($record) && ($record['planId'] ?? null) === $planId ? $record : null;
    }

    private function exhaustionAt(SessionStore $store, string $sessionKey, string $planId): ?string
    {
        $record = $this->budgetRecordAt($store, $sessionKey, $planId);

        return $record === null ? null : PlanBudget::exhaustion(PlanBudget::expire($record, $this->now(), $this->holdTtl()));
    }

    /**
     * The mode and limits this plan runs under: the fixed record, else config.
     *
     * @return array{mode: string, max_steps: ?int, max_cost_micros: ?int}
     */
    public function budgetFor(Session $session, string $planId): array
    {
        $record = $this->budgetRecord($session, $planId);

        return $record === null ? $this->budgetSettings()
            : ['mode' => $record['mode'], 'max_steps' => $record['max_steps'], 'max_cost_micros' => $record['max_cost_micros']];
    }

    /**
     * Host code chooses this plan's budget. The first choice fixes it; after
     * that the mode cannot change and a limit can only be lowered.
     *
     * @return array<string, mixed>
     */
    public function useBudget(Session $session, string $planId, mixed $requested): array
    {
        $chosen = PlanBudget::forPlan($this->budgetSettings(), $requested);

        return $this->budgetWrite($session, $planId, $chosen, static fn (array $state): array => [PlanBudget::narrow($state, $chosen), null])[0];
    }

    /** Which shared limit this plan has spent, or null. */
    public function budgetExhaustion(Session $session, string $planId): ?string
    {
        return $this->exhaustionAt($session->planStore(), $session->key(), $planId);
    }

    /** Announce that a plan stopped on its shared budget. */
    public function announceBudgetExhausted(Session $session, string $planId, string $limit): void
    {
        $record = $this->budgetRecord($session, $planId) ?? PlanBudget::open($planId, $this->budgetFor($session, $planId));
        try {
            event(new PlanBudgetExhausted($session->key(), $planId, PlanBudget::EXHAUSTED, $limit,
                $record['steps'], $record['cost_micros'] / 1_000_000, $session->agent()));
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /**
     * The run a turn executes under, when the turn is one of the plan's own
     * and its budget is shared: the per-turn budget, narrowed to what the
     * plan's ledger holds for it. Any other run is returned unchanged.
     *
     * The plan's own turns are the one advance() runs for the step it
     * claimed, the resume of an approval that step asked for, and a subagent
     * child those turns started, resumed outside the runner. Any other turn
     * on the session (a person chatting while a step waits on them, or while
     * a claim whose worker died is still live) is not the plan's: it spends
     * nothing from the plan's budget and is never refused for it.
     *
     * The plan's own turn handed a run tree of its own (a subagent, a
     * workflow node) cannot also spend from the plan's ledger, so it is
     * refused rather than let around it. A host context on a child's turn is
     * narrowed to what the plan's ledger holds for it instead.
     *
     * @internal Called by the runtime once a run's own budget is known.
     *
     * @throws PlanRefused plan.budget_context
     * @throws RunNotPermitted plan_budget_exhausted
     */
    public function planTurn(Session $session, string $runId, RunContext $run, ?RunContext $context, string $prompt = ''): RunContext
    {
        $target = $this->ledgerFor($session, $prompt, $context);
        if ($target === null) {
            return $run;
        }
        if ($target['refused']) {
            throw PlanRefused::because('plan.budget_context');
        }

        $ledger = $run->ledger;
        $budget = $run->budget;
        $baseSteps = $ledger->steps();
        $baseCost = $ledger->costUsd();
        $turnCost = $budget->maxCostUsd === null ? null : PlanBudget::micros(max(0.0, $budget->maxCostUsd - $baseCost));
        $now = $this->now();
        $ttl = $this->holdTtl();
        [, $grant] = $this->budgetWriteAt($session->planStore(), $target['session'], $target['plan'], $this->budgetSettings(),
            static fn (array $state): array => PlanBudget::reserve($state, $runId, max(0, $budget->maxSteps - $baseSteps), $turnCost, $now, $ttl));
        if (isset($grant['refused'])) {
            throw RunNotPermitted::planBudget((string) $grant['limit']);
        }

        $narrowed = $run->withBudget(new RunBudget(
            maxSteps: $baseSteps + (int) $grant['steps'],
            maxCostUsd: $grant['cost_micros'] === null ? $budget->maxCostUsd : $baseCost + $grant['cost_micros'] / 1_000_000,
            maxSeconds: $budget->maxSeconds,
            maxOutputTokensPerCall: $budget->maxOutputTokensPerCall,
            maxToolCalls: $budget->maxToolCalls,
        ));
        $this->budgetTurns[$runId] = [
            'session' => $session->key(), 'ledger' => $target['session'], 'plan' => $target['plan'], 'context' => $narrowed,
            'steps' => $baseSteps, 'cost' => $baseCost, 'unmetered' => $ledger->unmeteredRuns(),
        ];

        return $narrowed;
    }

    /**
     * The shared budget this turn would spend from, or null.
     *
     * @internal The runtime routes such a turn to the step path.
     *
     * @return array{mode: string, max_steps: ?int, max_cost_micros: ?int}|null
     */
    public function servingSharedPlan(Session $session, string $prompt = '', ?RunContext $context = null): ?array
    {
        return $this->ledgerFor($session, $prompt, $context)['settings'] ?? null;
    }

    /** @internal Whether this run spends from a shared plan budget. */
    public function isPlanTurn(string $runId): bool
    {
        return isset($this->budgetTurns[$runId]);
    }

    /**
     * The shared plan budget a run spends from, as the session key the plan
     * belongs to and the plan id; null when it spends from none.
     *
     * @internal Saved with a subagent child the run starts.
     *
     * @return array{session: string, plan: string}|null
     */
    public function ledgerOf(string $runId): ?array
    {
        $turn = $this->budgetTurns[$runId] ?? null;

        return $turn === null ? null : ['session' => $turn['ledger'], 'plan' => $turn['plan']];
    }

    /**
     * Mark the turn advance() runs for the step it claimed as the plan's own
     * until the returned release is called.
     *
     * @internal
     *
     * @return Closure(): void
     */
    public function servingStep(Session $session): Closure
    {
        $key = $session->key();
        $this->advancing[$key] = ($this->advancing[$key] ?? 0) + 1;
        $released = false;

        return function () use ($key, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            $this->advancing[$key] = ($this->advancing[$key] ?? 1) - 1;
            if ($this->advancing[$key] <= 0) {
                unset($this->advancing[$key]);
            }
        };
    }

    /**
     * Refuse, before anything is recorded, a decision on an approval whose
     * resume would spend from a shared plan budget that is already spent, or
     * that is handed a run tree of its own. Recorded, it would be consumed by
     * a resume the budget then refuses, and the approved call would never run.
     *
     * @internal Called by the session before it records decisions.
     *
     * @param  list<string>  $approvalIds
     *
     * @throws PlanRefused plan.budget_context
     * @throws RunNotPermitted plan_budget_exhausted
     */
    public function assertDecisionsFunded(Session $session, array $approvalIds, ?RunContext $context): void
    {
        $ledger = $this->decisionLedger($session, $approvalIds, $context);
        if ($ledger === null) {
            return;
        }
        if ($ledger['refused']) {
            throw PlanRefused::because('plan.budget_context');
        }
        $spent = $this->exhaustionAt($session->planStore(), $ledger['session'], $ledger['plan']);
        if ($spent !== null) {
            throw RunNotPermitted::planBudget($spent);
        }
    }

    /**
     * The shared ledger a turn spends from, as {@see PlanBudget::route()}
     * decides: its own plan's, when the turn is the plan's own; else, for a
     * turn on a subagent child the runner did not start (an answer, a new
     * message, or a host context, which the ledger narrows), the ledger of
     * the plan turn that started it. `refused` marks the plan's own turn
     * handed a run tree of its own.
     *
     * @return array{session: string, plan: string, settings: array{mode: string, max_steps: ?int, max_cost_micros: ?int}, refused: bool}|null
     */
    private function ledgerFor(Session $session, string $prompt, ?RunContext $context): ?array
    {
        if (! $this->enabled()) {
            return null;
        }
        $plan = $this->enabledFor($session) ? $this->load($session) : null;
        $step = self::activeAgentStep($plan);
        $advancing = $step !== null && isset($this->advancing[$session->key()]);
        $stepApproval = $step !== null && $step['approvalIds'] !== []
            && array_intersect($step['approvalIds'], $session->thread()->pausedApprovalIds()) !== [];

        return $this->routedLedger($session, $plan, $prompt, $advancing, $stepApproval, $context);
    }

    /**
     * The shared ledger a decision on these approvals would resume into.
     *
     * @param  list<string>  $approvalIds
     * @return array{session: string, plan: string, settings: array{mode: string, max_steps: ?int, max_cost_micros: ?int}, refused: bool}|null
     */
    private function decisionLedger(Session $session, array $approvalIds, ?RunContext $context): ?array
    {
        if (! $this->enabled() || $approvalIds === []) {
            return null;
        }
        $plan = $this->enabledFor($session) ? $this->load($session) : null;
        $step = self::activeAgentStep($plan);
        $stepApproval = $step !== null && array_intersect($step['approvalIds'], $approvalIds) !== [];

        // A decision resumes as a turn with no new message.
        return $this->routedLedger($session, $plan, '', false, $stepApproval, $context);
    }

    /**
     * @param  array<string, mixed>|null  $plan
     * @return array{session: string, plan: string, settings: array{mode: string, max_steps: ?int, max_cost_micros: ?int}, refused: bool}|null
     */
    private function routedLedger(Session $session, ?array $plan, string $prompt, bool $advancing, bool $stepApproval, ?RunContext $context): ?array
    {
        $route = PlanBudget::route(app(RunTrees::class)->contextKind($context), $prompt, $advancing, $stepApproval,
            $session->childTree() !== null, $session->childPlanLedger() !== null)['plan'];
        if (($route === 'own' || $route === 'refused') && $plan !== null) {
            $settings = $this->budgetFor($session, $plan['id']);

            return $settings['mode'] === 'shared'
                ? ['session' => $session->key(), 'plan' => $plan['id'], 'settings' => $settings, 'refused' => $route === 'refused'] : null;
        }

        return $route === 'child' ? $this->childLedger($session) : null;
    }

    /**
     * The plan ledger saved with a subagent child, while it is still that
     * plan's shared ledger.
     *
     * @return array{session: string, plan: string, settings: array{mode: string, max_steps: ?int, max_cost_micros: ?int}, refused: bool}|null
     */
    private function childLedger(Session $session): ?array
    {
        $saved = $session->childPlanLedger();
        if ($saved === null) {
            return null;
        }
        $record = $this->budgetRecordAt($session->planStore(), $saved['session'], $saved['plan']);
        if ($record === null || ($record['mode'] ?? null) !== 'shared') {
            return null;
        }

        return [...$saved, 'settings' => ['mode' => 'shared', 'max_steps' => $record['max_steps'], 'max_cost_micros' => $record['max_cost_micros']], 'refused' => false];
    }

    /**
     * The plan's head step when it is the agent's and claimed for a turn or
     * held on an approval that turn asked for; null otherwise.
     *
     * @param  array<string, mixed>|null  $plan
     * @return array<string, mixed>|null
     */
    private static function activeAgentStep(?array $plan): ?array
    {
        if (! is_array($plan) || ! in_array($plan['status'], ['active', 'waiting', 'blocked'], true)) {
            return null;
        }
        $head = PlanMachine::headIndex($plan);
        if ($head === null) {
            return null;
        }
        $step = $plan['steps'][$head];

        return $step['owner'] === 'agent' && in_array($step['state'], ['running', 'held'], true) ? $step : null;
    }

    /** Replace a finished turn's hold with what it spent. */
    private function settleTurn(Session $session): void
    {
        $run = $session->run();
        $runId = is_array($run) && is_string($run['id'] ?? null) ? $run['id'] : null;
        $turn = $runId === null ? null : ($this->budgetTurns[$runId] ?? null);
        if ($turn === null || $turn['session'] !== $session->key()) {
            return;
        }
        unset($this->budgetTurns[$runId]);
        $ledger = $turn['context']->ledger;
        $steps = $ledger->steps() - $turn['steps'];
        $cost = $ledger->unmeteredRuns() > $turn['unmetered'] ? null : PlanBudget::micros(max(0.0, $ledger->costUsd() - $turn['cost']));
        try {
            $this->budgetWriteAt($session->planStore(), $turn['ledger'], $turn['plan'], $this->budgetSettings(),
                static fn (array $state): array => [PlanBudget::settle($state, $runId, $steps, $cost), null]);
        } catch (Throwable $failure) {
            // The hold stays and is charged in full once it expires.
            self::reportQuietly($failure);
        }
    }

    /**
     * One locked change to this plan's budget record, opening it from
     * $settings when it is absent or belongs to an earlier plan.
     *
     * @param  array{mode: string, max_steps: ?int, max_cost_micros: ?int}  $settings
     * @param  Closure(array<string, mixed>): array{0: array<string, mixed>, 1: mixed}  $change
     * @return array{0: array<string, mixed>, 1: mixed}
     */
    private function budgetWrite(Session $session, string $planId, array $settings, Closure $change): array
    {
        return $this->budgetWriteAt($session->planStore(), $session->key(), $planId, $settings, $change);
    }

    /**
     * The same change to the budget record of the plan kept under another
     * session key, in the same durable store (a subagent child's parent).
     *
     * @param  array{mode: string, max_steps: ?int, max_cost_micros: ?int}  $settings
     * @param  Closure(array<string, mixed>): array{0: array<string, mixed>, 1: mixed}  $change
     * @return array{0: array<string, mixed>, 1: mixed}
     */
    private function budgetWriteAt(SessionStore $store, string $sessionKey, string $planId, array $settings, Closure $change): array
    {
        $key = self::budgetKey($sessionKey);

        return $store->withLock($key, function () use ($store, $key, $planId, $settings, $change): array {
            $state = $store->get($key);
            if (! is_array($state) || ($state['planId'] ?? null) !== $planId) {
                $state = PlanBudget::open($planId, $settings);
            }
            [$next, $result] = $change($state);
            $store->put($key, $next);

            return [$next, $result];
        }, 10, 5);
    }

    /** A hold outlives its turn by no more than the run lock does. */
    private function holdTtl(): int
    {
        $ttl = config('prism-harness.agent.lock_ttl', 300);

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }

    private static function budgetKey(string $sessionKey): string
    {
        return $sessionKey.':plan-budget';
    }

    // ------------------------------------------------------------------
    // Reaper
    // ------------------------------------------------------------------

    /**
     * The callback the reaper hands a stalled plan to: normally "dispatch the
     * job that calls advance() for this session".
     *
     * @param  Closure(string $sessionKey, string $planId): void  $dispatch
     */
    public function reapUsing(Closure $dispatch): void
    {
        $this->reapDispatcher = $dispatch;
    }

    /** @return (Closure(string, string): void)|null */
    public function reapDispatcher(): ?Closure
    {
        return $this->reapDispatcher;
    }

    /** @return array{at: int}|null */
    public function heartbeat(): ?array
    {
        $beat = $this->indexStore()?->get(self::HEARTBEAT_KEY);

        return is_array($beat) && is_int($beat['at'] ?? null) ? ['at' => $beat['at']] : null;
    }

    /**
     * Find active plans whose head step has no live claim and that have not
     * moved for `reaper.stale_after` seconds, and hand each to $dispatch.
     * Writes the heartbeat the doctor checks. Returns how many it handed on.
     *
     * @param  Closure(string $sessionKey, string $planId): void  $dispatch
     */
    public function sweep(Closure $dispatch): int
    {
        $store = $this->indexStore();
        if ($store === null) {
            return 0;
        }
        $now = $this->now();
        $staleAfter = $this->limits()->staleAfter;
        $index = $store->get(self::INDEX_KEY) ?? [];
        $handed = 0;
        foreach ($index as $sessionKey => $planId) {
            $plan = $store->get(self::key((string) $sessionKey))['plan'] ?? null;
            if (! is_array($plan) || $plan['id'] !== $planId) {
                continue;
            }
            if (PlanMachine::stalled($plan, $now, $staleAfter)) {
                $dispatch((string) $sessionKey, (string) $planId);
                $handed++;
            }
        }
        $store->put(self::HEARTBEAT_KEY, ['at' => $now]);

        return $handed;
    }

    // ------------------------------------------------------------------

    /** @param array<array-key, mixed> $arguments */
    public static function digest(array $arguments): string
    {
        return CanonicalJson::digest($arguments);
    }

    /** @internal Schemas for the plan tools. */
    public static function stepSchema(bool $withId): ObjectSchema
    {
        $properties = [
            new StringSchema('title', 'A short title for the step, at most 160 characters, one line.', nullable: $withId),
            new StringSchema('instruction', 'What to do in this step, at most 600 characters, one line. Required for your own steps.', nullable: true),
            new EnumSchema('owner', 'agent for your own step; human for a question to the person.', ['agent', 'human'], nullable: $withId),
            new EnumSchema('interaction', 'input for a question to the person; omit for your own steps.', ['input'], nullable: true),
            new StringSchema('question', 'The question for the person, for a human step.', nullable: true),
            new ArraySchema('options', 'Suggested answers for a human step; free text is always allowed.', new StringSchema('option', 'An option'), nullable: true),
        ];
        if ($withId) {
            array_unshift($properties, new StringSchema('id', 'To keep an existing step unchanged, its id and nothing else.', nullable: true));
        }

        return new ObjectSchema('step', 'One step.', $properties, $withId ? [] : ['title', 'owner']);
    }
}
