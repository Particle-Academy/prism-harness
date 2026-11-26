<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Exceptions\RunNotPermitted;

/**
 * What a run TREE has actually spent, and whether it has been cancelled.
 *
 * Shared by reference from a parent to every descendant, which is the whole
 * point: {@see RunBudget} says budgets nest, and nesting is only real if the
 * child's spend lands in the same account the parent is measured against.
 * A per-run ledger would let each node report itself within budget while the
 * tree went far past it.
 *
 * Mutable on purpose, and the only mutable thing in this namespace. Spend is a
 * running total; modelling it immutably would mean threading a new instance
 * back up through every return, and the one place that must not be missed is
 * the failure path.
 */
final class RunLedger
{
    private int $steps = 0;

    private int $toolCalls = 0;

    private float $costUsd = 0.0;

    private int $unmeteredRuns = 0;

    private bool $cancelled = false;

    private ?string $cancelReason = null;

    /** @var array<int, float> */
    private array $reservations = [];

    private int $reservationSequence = 0;

    /**
     * Which agent each open reservation belongs to, and what each agent has
     * been charged. LABELS on the one account, not accounts: a reservation
     * still counts against the tree first, and every charge still lands in
     * the tree's own total.
     *
     * @var array<int, string>
     */
    private array $reservationAgents = [];

    /** @var array<string, float> */
    private array $agentCosts = [];

    private ?CallCostObserver $observer = null;

    /** Set for a ledger shared across processes; see {@see LedgerGuard}. */
    private ?LedgerGuard $guard = null;

    private bool $guarding = false;

    /**
     * The reason a ledger stopped by a person's cancel carries (G-285), so a
     * cancel is never confused with a spent budget.
     */
    public const CANCELLED = 'run cancelled';

    /**
     * Durable cancel flags this tree watches, one per run executing on it in
     * this process (G-285). Not part of the snapshot: each reads the store.
     *
     * @var array<int, \Closure(): bool>
     */
    private array $cancelProbes = [];

    private int $probeSequence = 0;

    public function __construct(
        public readonly string $rootRunId,
        private float $startedAt = 0.0,
    ) {}

    /**
     * @internal Bind this ledger to the shared account it caches (G-348).
     */
    public function guardedBy(LedgerGuard $guard): self
    {
        $this->guard = $guard;

        return $this;
    }

    /**
     * @internal The totals a {@see LedgerGuard} stores.
     *
     * @return array{steps: int, tool_calls: int, cost_usd: float, unmetered: int, cancelled: bool, cancel_reason: ?string, reservations: array<int, float>, reservation_agents: array<int, string>, reservation_sequence: int, agent_costs: array<string, float>, started_at: float}
     */
    public function snapshot(): array
    {
        return [
            'steps' => $this->steps,
            'tool_calls' => $this->toolCalls,
            'cost_usd' => $this->costUsd,
            'unmetered' => $this->unmeteredRuns,
            'cancelled' => $this->cancelled,
            'cancel_reason' => $this->cancelReason,
            'reservations' => $this->reservations,
            'reservation_agents' => $this->reservationAgents,
            'reservation_sequence' => $this->reservationSequence,
            'agent_costs' => $this->agentCosts,
            'started_at' => $this->startedAt,
        ];
    }

    /**
     * @internal Load totals a {@see LedgerGuard} stored.
     *
     * @param  array<string, mixed>  $state
     */
    public function restore(array $state): void
    {
        $this->steps = self::int($state['steps'] ?? 0);
        $this->toolCalls = self::int($state['tool_calls'] ?? 0);
        $this->costUsd = self::float($state['cost_usd'] ?? 0.0);
        $this->unmeteredRuns = self::int($state['unmetered'] ?? 0);
        $this->cancelled = ($state['cancelled'] ?? false) === true;
        $this->cancelReason = isset($state['cancel_reason']) && is_string($state['cancel_reason']) ? $state['cancel_reason'] : null;
        $this->reservations = [];
        $this->reservationAgents = [];
        $this->agentCosts = [];
        foreach (is_array($state['reservations'] ?? null) ? $state['reservations'] : [] as $id => $amount) {
            $this->reservations[(int) $id] = self::float($amount);
        }
        foreach (is_array($state['reservation_agents'] ?? null) ? $state['reservation_agents'] : [] as $id => $agent) {
            if (is_string($agent)) {
                $this->reservationAgents[(int) $id] = $agent;
            }
        }
        foreach (is_array($state['agent_costs'] ?? null) ? $state['agent_costs'] : [] as $agent => $cost) {
            $this->agentCosts[(string) $agent] = self::float($cost);
        }
        $this->reservationSequence = self::int($state['reservation_sequence'] ?? 0);
        $this->startedAt = self::float($state['started_at'] ?? $this->startedAt);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * The refusal for a run that may not spend again. A shared workflow
     * ledger records it as the run's exhaustion and names it as such.
     */
    public function refusal(string $reason): RunNotPermitted
    {
        if ($reason === self::CANCELLED) {
            return RunNotPermitted::cancelled();
        }

        return $this->guard === null || $this->guarding
            ? RunNotPermitted::exhausted($reason)
            : $this->guard->refusal($this, $reason);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    private function guarded(\Closure $operation): mixed
    {
        if ($this->guard === null || $this->guarding) {
            return $operation();
        }

        $this->guarding = true;
        try {
            return $this->guard->atomically($this, $operation);
        } finally {
            $this->guarding = false;
        }
    }

    public static function start(string $rootRunId): self
    {
        return new self($rootRunId, microtime(true));
    }

    public function steps(): int
    {
        return $this->guarded(fn (): int => $this->steps);
    }

    /** Workflow tool handlers started in this tree (G-348). Sessions never count them. */
    public function toolCalls(): int
    {
        return $this->guarded(fn (): int => $this->toolCalls);
    }

    public function costUsd(): float
    {
        return $this->guarded(fn (): float => $this->costUsd);
    }

    public function reservedCostUsd(): float
    {
        return $this->guarded(fn (): float => array_sum($this->reservations));
    }

    /** Once selected, the tree's observer cannot be dropped or replaced by a child context. */
    public function inheritCostObserver(?CallCostObserver $observer): ?CallCostObserver
    {
        if ($this->observer !== null && $observer !== null && $observer !== $this->observer) {
            throw new \LogicException('A run tree cannot replace its cost observer.');
        }
        $this->observer ??= $observer;

        return $this->observer;
    }

    /**
     * Admission and reservation happen together, before a provider or nested tool can spend.
     *
     * A call must fit the tree's cost cap when there is one AND, for a named
     * agent with a `spend_ceiling`, that agent's charged plus reserved spend in
     * this same ledger. Either refusal happens before the provider request.
     */
    public function reserveCost(RunBudget $budget, float $worstCase, ?string $agent = null, ?float $agentCeilingUsd = null): int
    {
        return $this->guarded(fn (): int => $this->reserveCostNow($budget, $worstCase, $agent, $agentCeilingUsd));
    }

    private function reserveCostNow(RunBudget $budget, float $worstCase, ?string $agent, ?float $agentCeilingUsd): int
    {
        $treeCapped = $budget->maxCostUsd !== null;
        if (! is_finite($worstCase) || $worstCase < 0 || (! $treeCapped && $agentCeilingUsd === null)
            || ($treeCapped && (! is_finite($budget->maxCostUsd) || $budget->maxCostUsd < 0
                || $this->costUsd + $this->reservedCostUsd() + $worstCase > $budget->maxCostUsd))) {
            throw RunNotPermitted::callBudget('call_budget_exceeded');
        }
        if ($agentCeilingUsd !== null && ($agent === null || ! is_finite($agentCeilingUsd) || $agentCeilingUsd <= 0
            || $this->agentCostUsd($agent) + $this->agentReservedUsd($agent) + $worstCase > $agentCeilingUsd)) {
            throw RunNotPermitted::callBudget('agent_spend_ceiling_exceeded');
        }

        $this->assertCallAvailable($budget);

        $id = ++$this->reservationSequence;
        $this->reservations[$id] = $worstCase;
        if ($agent !== null) {
            $this->reservationAgents[$id] = $agent;
        }

        return $id;
    }

    /** What calls labelled with this agent have been charged in this tree. */
    public function agentCostUsd(string $agent): float
    {
        return $this->guarded(fn (): float => $this->agentCosts[$agent] ?? 0.0);
    }

    private function agentReservedUsd(string $agent): float
    {
        $reserved = 0.0;
        foreach ($this->reservationAgents as $id => $label) {
            if ($label === $agent) {
                $reserved += $this->reservations[$id];
            }
        }

        return $reserved;
    }

    /** Check cancellation/steps/time before resolving an approved side effect, then again before spending. */
    public function assertCallAvailable(RunBudget $budget): void
    {
        $this->guarded(function () use ($budget): void {
            $this->assertCallAvailableNow($budget);
        });
    }

    /**
     * Check cancellation and time before acting on the person's recorded
     * decisions, but not the step limit. Resolving takes no model step: a run
     * parked on its last step still runs the calls it was approved for and
     * records their results, then stops on its step budget with no model step
     * after them. A decision that was accepted is acted on.
     */
    public function assertResolutionAvailable(RunBudget $budget): void
    {
        $this->assertCallAvailable(self::withoutStepLimit($budget));
    }

    /**
     * Whether the step limit is the only thing this run has spent: it has
     * spent something, and acting on recorded decisions is still allowed.
     *
     * ONE RULE with {@see self::assertResolutionAvailable()} (G-387): "may it
     * act on decisions" is answered by the very check the loop makes before
     * it acts. This used to ask exhaustion() without the step limit, which
     * counts cost spent with `>=`, while the loop's check admits cost with
     * `>` at reservation and never refuses a resolution for cost: a run at
     * exactly its cost cap was refused a decision the loop would have acted
     * on. Acting on a decision makes no provider call, so it reserves no
     * cost; a cost cap the provider gives no numbers for still fails closed.
     */
    public function onlyStepsSpent(RunBudget $budget): bool
    {
        return $this->guarded(fn (): bool => $this->exhaustionNow($budget) !== null
            && $this->callStop(self::withoutStepLimit($budget)) === null);
    }

    private static function withoutStepLimit(RunBudget $budget): RunBudget
    {
        return new RunBudget(PHP_INT_MAX, $budget->maxCostUsd, $budget->maxSeconds, $budget->maxOutputTokensPerCall, $budget->maxToolCalls);
    }

    private function assertCallAvailableNow(RunBudget $budget): void
    {
        $stop = $this->callStop($budget);
        if ($stop !== null) {
            throw $stop === self::CANCELLED && $this->cancelled ? RunNotPermitted::cancelled() : RunNotPermitted::exhausted($stop);
        }
    }

    /** Why a call (or, without the step limit, a resolution) may not proceed now, or null. */
    private function callStop(RunBudget $budget): ?string
    {
        // Cost admission uses >, allowing an exact fit (including a genuinely
        // free call). Preserve the legacy >= exhaustion API for its callers.
        return $this->unmeteredRuns > 0 ? $this->exhaustionNow($budget)
            : $this->exhaustionNow(new RunBudget($budget->maxSteps, maxSeconds: $budget->maxSeconds, maxToolCalls: $budget->maxToolCalls));
    }

    /** Missing usage charges the proven upper bound, preserving only the unspent allowance. */
    public function settleCost(int $reservation, ?float $cost): void
    {
        $this->chargeReservation($reservation, $cost);
    }

    /** Settle and return this call's charge, independently of other calls sharing the ledger. */
    public function chargeReservation(int $reservation, ?float $cost): float
    {
        return $this->guarded(fn (): float => $this->chargeReservationNow($reservation, $cost));
    }

    private function chargeReservationNow(int $reservation, ?float $cost): float
    {
        if (! array_key_exists($reservation, $this->reservations)) {
            throw new \LogicException('The call reservation has already been settled.');
        }
        $cost ??= $this->reservations[$reservation];
        unset($this->reservations[$reservation]);
        $agent = $this->reservationAgents[$reservation] ?? null;
        unset($this->reservationAgents[$reservation]);
        if ($agent !== null) {
            $this->agentCosts[$agent] = ($this->agentCosts[$agent] ?? 0.0) + max(0.0, $cost);
        }
        $this->recordCost($cost);

        return max(0.0, $cost);
    }

    /** A host veto happened before any provider request, so nothing was spent. */
    public function releaseCost(int $reservation): void
    {
        $this->guarded(function () use ($reservation): void {
            if (! array_key_exists($reservation, $this->reservations)) {
                throw new \LogicException('The call reservation has already been settled.');
            }
            unset($this->reservations[$reservation], $this->reservationAgents[$reservation]);
        });
    }

    public function elapsedSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function recordSteps(int $steps): void
    {
        $this->guarded(function () use ($steps): void {
            $this->steps += max(0, $steps);
        });
    }

    /**
     * Admit one model step and charge it, in one decision (G-348). A shared
     * ledger takes both under one hold, so two workers cannot both take the
     * last step.
     */
    public function takeStep(RunBudget $budget): void
    {
        $this->guarded(function () use ($budget): void {
            $this->assertCallAvailableNow($budget);
            $this->steps++;
        });
    }

    /** Admit one workflow tool handler and charge it, in one decision (G-348). */
    public function takeToolCall(RunBudget $budget): void
    {
        $this->guarded(function () use ($budget): void {
            $this->assertCallAvailableNow($budget);
            $this->toolCalls++;
        });
    }

    /**
     * One workflow tool handler is about to start (G-348). Charged before the
     * handler and never refunded, whether it succeeds or throws.
     */
    public function recordToolCall(): void
    {
        $this->guarded(function (): void {
            $this->toolCalls++;
        });
    }

    /**
     * Charge a run's cost to the tree.
     *
     * NULL IS NOT ZERO. `Usage::$cost` is nullable because not every provider
     * reports one, and folding that into `+= 0.0` would leave a cost budget
     * that can never trip — enforced in the documentation, absent at runtime,
     * and indistinguishable from a tree that genuinely spent nothing. Counted
     * separately so {@see self::exhaustion()} can say the cap is unenforceable
     * instead of quietly failing open.
     */
    public function recordCost(?float $usd): void
    {
        $this->guarded(function () use ($usd): void {
            if ($usd === null) {
                $this->unmeteredRuns++;

                return;
            }

            $this->costUsd += max(0.0, $usd);
        });
    }

    public function unmeteredRuns(): int
    {
        return $this->guarded(fn (): int => $this->unmeteredRuns);
    }

    /**
     * Stop the tree.
     *
     * Cooperative rather than pre-emptive: PHP cannot interrupt a tool that is
     * already executing, and pretending otherwise would be the more dangerous
     * lie. A half-executed tool is precisely the state the durability layer
     * exists to protect, so the in-flight call is allowed to finish and the
     * NEXT step is refused.
     */
    public function cancel(string $reason = 'cancelled'): void
    {
        $this->guarded(function () use ($reason): void {
            $this->cancelled = true;
            $this->cancelReason = $reason;
        });
    }

    /**
     * Watch a run's durable cancel flag (G-285). Every check this tree makes
     * before a step, a tool or a child reads it; once one reads true the tree
     * is cancelled, for every run on it, and stays cancelled. Returns the
     * unwatch, called when that run ends.
     *
     * @internal Registered by the runtime for each run it executes.
     *
     * @param  \Closure(): bool  $probe
     * @return \Closure(): void
     */
    public function watchCancel(\Closure $probe): \Closure
    {
        $id = ++$this->probeSequence;
        $this->cancelProbes[$id] = $probe;

        return function () use ($id): void {
            unset($this->cancelProbes[$id]);
        };
    }

    /**
     * Whether a person cancelled this tree (G-285), reading every watched
     * flag now. A budget stop is not a cancel and never reads as one.
     */
    public function cancelRequested(): bool
    {
        return $this->guarded(fn (): bool => $this->pollCancel());
    }

    private function pollCancel(): bool
    {
        if ($this->cancelled) {
            return $this->cancelReason === self::CANCELLED;
        }
        foreach ($this->cancelProbes as $probe) {
            if ($probe()) {
                $this->cancelled = true;
                $this->cancelReason = self::CANCELLED;

                return true;
            }
        }

        return false;
    }

    public function cancelled(): bool
    {
        return $this->guarded(fn (): bool => $this->cancelled);
    }

    public function cancelReason(): ?string
    {
        return $this->guarded(fn (): ?string => $this->cancelReason);
    }

    public function remainingCost(RunBudget $budget): ?float
    {
        return $this->guarded(fn (): ?float => $budget->maxCostUsd === null ? null
            : max(0.0, $budget->maxCostUsd - $this->costUsd - array_sum($this->reservations)));
    }

    public function remainingSeconds(RunBudget $budget): ?int
    {
        return $budget->maxSeconds === null
            ? null
            : (int) max(0, $budget->maxSeconds - (int) $this->elapsedSeconds());
    }

    /**
     * Why the tree may not spend again — or null when it may.
     *
     * Returns a REASON rather than a bool. The states are genuinely different
     * (cancelled / out of steps / out of money / out of time) and a caller that
     * cannot tell them apart writes one message for four causes, which is the
     * collapse this ecosystem keeps finding. See decision 0020.
     */
    public function exhaustion(RunBudget $budget): ?string
    {
        return $this->guarded(fn (): ?string => $this->exhaustionNow($budget));
    }

    private function exhaustionNow(RunBudget $budget): ?string
    {
        $this->pollCancel();
        if ($this->cancelled) {
            return $this->cancelReason ?? 'cancelled';
        }

        if ($this->steps >= $budget->maxSteps) {
            return sprintf('step budget exhausted (%d of %d used)', $this->steps, $budget->maxSteps);
        }

        if ($budget->maxToolCalls !== null && $this->toolCalls >= $budget->maxToolCalls) {
            return sprintf('tool call budget exhausted (%d of %d used)', $this->toolCalls, $budget->maxToolCalls);
        }

        if ($budget->maxCostUsd !== null && $this->unmeteredRuns > 0) {
            // Failing CLOSED. A cost cap the provider gives us no numbers to
            // enforce is not a cap, and continuing would spend without limit
            // under a budget the operator believes is holding.
            return sprintf(
                'cost budget cannot be enforced: %d run(s) reported no cost, so spend against the %.4f USD cap is unknown',
                $this->unmeteredRuns,
                $budget->maxCostUsd,
            );
        }

        if ($budget->maxCostUsd !== null && $this->costUsd >= $budget->maxCostUsd) {
            return sprintf('cost budget exhausted (%.4f of %.4f USD used)', $this->costUsd, $budget->maxCostUsd);
        }

        if ($budget->maxSeconds !== null && $this->elapsedSeconds() >= $budget->maxSeconds) {
            return sprintf('time budget exhausted (%ds of %ds used)', (int) $this->elapsedSeconds(), $budget->maxSeconds);
        }

        return null;
    }
}
