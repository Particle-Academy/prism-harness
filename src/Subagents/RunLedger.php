<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Contracts\RunLedgerStore;
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

    private float $costUsd = 0.0;

    private int $unmeteredRuns = 0;

    private bool $cancelled = false;

    private ?string $cancelReason = null;

    /** @var array<int, float> */
    private array $reservations = [];

    private int $reservationSequence = 0;

    private ?CallCostObserver $observer = null;

    private ?string $observerType = null;

    private ?RunLedgerStore $store = null;

    private bool $synchronizing = false;

    public function __construct(
        public readonly string $rootRunId,
        private readonly float $startedAt = 0.0,
    ) {}

    public static function start(string $rootRunId): self
    {
        return new self($rootRunId, microtime(true));
    }

    /** Attach once. Duplicate root creation refuses instead of resetting spend. */
    public function persistUsing(RunLedgerStore $store): self
    {
        if ($this->store !== null) {
            if ($this->store !== $store) {
                throw new \LogicException('A run tree cannot replace its durable ledger store.');
            }

            return $this;
        }
        if (! preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $this->rootRunId)) {
            throw new \LogicException('The durable root run identity is invalid.');
        }
        $store->create($this->rootRunId, $this->snapshot());
        $this->store = $store;

        return $this;
    }

    /** Only trusted harness state may choose the root; never a model argument. */
    public static function resume(string $rootRunId, RunLedgerStore $store, ?CallCostObserver $observer = null): self
    {
        $state = $store->read($rootRunId);
        $startedAt = $state['started_at'] ?? null;
        if ((! is_float($startedAt) && ! is_int($startedAt)) || ! is_finite((float) $startedAt)) {
            throw new \LogicException('The durable ledger clock is invalid.');
        }
        $ledger = new self($rootRunId, (float) $startedAt);
        $ledger->observer = $observer;
        $ledger->hydrate($state);
        if ($ledger->observerType !== null && ($observer === null || $observer::class !== $ledger->observerType)) {
            throw new \LogicException('The durable run requires its configured cost observer.');
        }
        $ledger->store = $store;

        return $ledger;
    }

    public function steps(): int
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): int => $this->steps());
        }

        return $this->steps;
    }

    public function costUsd(): float
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): float => $this->costUsd());
        }

        return $this->costUsd;
    }

    public function reservedCostUsd(): float
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): float => $this->reservedCostUsd());
        }

        return array_sum($this->reservations);
    }

    /** Once selected, the tree's observer cannot be dropped or replaced by a child context. */
    public function inheritCostObserver(?CallCostObserver $observer): ?CallCostObserver
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): ?CallCostObserver => $this->inheritCostObserver($observer));
        }
        if ($this->observer !== null && $observer !== null && $observer !== $this->observer) {
            throw new \LogicException('A run tree cannot replace its cost observer.');
        }
        $this->observer ??= $observer;
        if ($this->observer !== null) {
            $this->observerType = $this->observer::class;
        }

        return $this->observer;
    }

    /** Admission and reservation happen together, before a provider or nested tool can spend. */
    public function reserveCost(RunBudget $budget, float $worstCase): int
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): int => $this->reserveCost($budget, $worstCase));
        }
        if (! is_finite($worstCase) || $worstCase < 0 || $budget->maxCostUsd === null
            || ! is_finite($budget->maxCostUsd) || $budget->maxCostUsd < 0
            || $this->costUsd + $this->reservedCostUsd() + $worstCase > $budget->maxCostUsd) {
            throw RunNotPermitted::callBudget('call_budget_exceeded');
        }

        $this->assertCallAvailable($budget);

        $id = ++$this->reservationSequence;
        $this->reservations[$id] = $worstCase;

        return $id;
    }

    /** Check cancellation/steps/time before resolving an approved side effect, then again before spending. */
    public function assertCallAvailable(RunBudget $budget): void
    {
        if ($this->needsSynchronization()) {
            $this->synchronized(fn () => $this->assertCallAvailable($budget));

            return;
        }
        // Cost admission uses >, allowing an exact fit (including a genuinely
        // free call). Preserve the legacy >= exhaustion API for its callers.
        $stop = $this->unmeteredRuns > 0 ? $this->exhaustion($budget)
            : $this->exhaustion(new RunBudget($budget->maxSteps, maxSeconds: $budget->maxSeconds));
        if ($stop !== null) {
            throw RunNotPermitted::exhausted($stop);
        }
    }

    /** Missing usage charges the proven upper bound, preserving only the unspent allowance. */
    public function settleCost(int $reservation, ?float $cost): void
    {
        $this->chargeReservation($reservation, $cost);
    }

    /** Settle and return this call's charge, independently of other calls sharing the ledger. */
    public function chargeReservation(int $reservation, ?float $cost): float
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): float => $this->chargeReservation($reservation, $cost));
        }
        if ($this->store !== null && $cost !== null && ! is_finite($cost)) {
            throw new \LogicException('A durable ledger cannot settle an invalid cost.');
        }
        if (! array_key_exists($reservation, $this->reservations)) {
            throw new \LogicException('The call reservation has already been settled.');
        }
        $cost ??= $this->reservations[$reservation];
        unset($this->reservations[$reservation]);
        $this->recordCost($cost);

        return max(0.0, $cost);
    }

    /** A host veto happened before any provider request, so nothing was spent. */
    public function releaseCost(int $reservation): void
    {
        if ($this->needsSynchronization()) {
            $this->synchronized(fn () => $this->releaseCost($reservation));

            return;
        }
        if (! array_key_exists($reservation, $this->reservations)) {
            throw new \LogicException('The call reservation has already been settled.');
        }
        unset($this->reservations[$reservation]);
    }

    public function elapsedSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function recordSteps(int $steps): void
    {
        if ($this->needsSynchronization()) {
            $this->synchronized(fn () => $this->recordSteps($steps));

            return;
        }
        $this->steps += max(0, $steps);
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
        if ($this->needsSynchronization()) {
            $this->synchronized(fn () => $this->recordCost($usd));

            return;
        }
        if ($this->store !== null && $usd !== null && ! is_finite($usd)) {
            throw new \LogicException('A durable ledger cannot record an invalid cost.');
        }
        if ($usd === null) {
            $this->unmeteredRuns++;

            return;
        }

        $this->costUsd += max(0.0, $usd);
    }

    public function unmeteredRuns(): int
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): int => $this->unmeteredRuns());
        }

        return $this->unmeteredRuns;
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
        if ($this->needsSynchronization()) {
            $this->synchronized(fn () => $this->cancel($reason));

            return;
        }
        $this->cancelled = true;
        $this->cancelReason = $reason;
    }

    public function cancelled(): bool
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): bool => $this->cancelled());
        }

        return $this->cancelled;
    }

    public function cancelReason(): ?string
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): ?string => $this->cancelReason());
        }

        return $this->cancelReason;
    }

    public function remainingCost(RunBudget $budget): ?float
    {
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): ?float => $this->remainingCost($budget));
        }

        return $budget->maxCostUsd === null ? null : max(0.0, $budget->maxCostUsd - $this->costUsd - $this->reservedCostUsd());
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
        if ($this->needsSynchronization()) {
            return $this->synchronized(fn (): ?string => $this->exhaustion($budget));
        }
        if ($this->cancelled) {
            return $this->cancelReason ?? 'cancelled';
        }

        if ($this->steps >= $budget->maxSteps) {
            return sprintf('step budget exhausted (%d of %d used)', $this->steps, $budget->maxSteps);
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

    private function needsSynchronization(): bool
    {
        return $this->store !== null && ! $this->synchronizing;
    }

    /** @template TReturn
     * @param  Closure(): TReturn  $operation
     * @return TReturn
     */
    private function synchronized(Closure $operation): mixed
    {
        if ($this->store === null) {
            throw new \LogicException('No durable ledger store is attached.');
        }

        return $this->store->update($this->rootRunId, function (array $state) use ($operation): array {
            $this->hydrate($state);
            $this->synchronizing = true;
            try {
                $result = $operation();
            } finally {
                $this->synchronizing = false;
            }

            return ['state' => $this->snapshot(), 'result' => $result];
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'version' => 1, 'root_run_id' => $this->rootRunId, 'started_at' => $this->startedAt,
            'steps' => $this->steps, 'cost_usd' => $this->costUsd, 'unmetered_runs' => $this->unmeteredRuns,
            'cancelled' => $this->cancelled, 'cancel_reason' => $this->cancelReason,
            'reservations' => $this->reservations, 'reservation_sequence' => $this->reservationSequence,
            'observer_type' => $this->observerType,
        ];
    }

    /** @param array<string, mixed> $state */
    private function hydrate(array $state): void
    {
        $keys = array_keys($state);
        $expected = array_keys($this->snapshot());
        sort($keys);
        sort($expected);
        if ($keys !== $expected || $state['version'] !== 1 || $state['root_run_id'] !== $this->rootRunId
            || (! is_float($state['started_at']) && ! is_int($state['started_at']))
            || (float) $state['started_at'] !== $this->startedAt
            || ! is_int($state['steps']) || $state['steps'] < 0
            || (! is_float($state['cost_usd']) && ! is_int($state['cost_usd']))
            || ! is_finite((float) $state['cost_usd']) || $state['cost_usd'] < 0
            || ! is_int($state['unmetered_runs']) || $state['unmetered_runs'] < 0
            || ! is_bool($state['cancelled'])
            || ($state['cancel_reason'] !== null && ! is_string($state['cancel_reason']))
            || ! is_array($state['reservations']) || count($state['reservations']) > 1024
            || ! is_int($state['reservation_sequence']) || $state['reservation_sequence'] < 0
            || ($state['observer_type'] !== null && ! is_string($state['observer_type']))) {
            throw new \LogicException('The durable run ledger state is malformed.');
        }
        foreach ($state['reservations'] as $id => $cost) {
            if (! is_int($id) || $id < 1 || $id > $state['reservation_sequence']
                || (! is_int($cost) && ! is_float($cost)) || ! is_finite((float) $cost) || $cost < 0) {
                throw new \LogicException('The durable run reservation state is malformed.');
            }
        }
        if ($state['observer_type'] !== null && ($this->observer === null || $state['observer_type'] !== $this->observer::class)) {
            throw new \LogicException('The durable run requires its configured cost observer.');
        }
        $this->steps = $state['steps'];
        $this->costUsd = (float) $state['cost_usd'];
        $this->unmeteredRuns = $state['unmetered_runs'];
        $this->cancelled = $state['cancelled'];
        $this->cancelReason = $state['cancel_reason'];
        $this->reservations = $state['reservations'];
        $this->reservationSequence = $state['reservation_sequence'];
        $this->observerType = $state['observer_type'];
    }
}
