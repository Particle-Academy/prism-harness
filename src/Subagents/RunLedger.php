<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

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

    public function __construct(
        public readonly string $rootRunId,
        private readonly float $startedAt = 0.0,
    ) {}

    public static function start(string $rootRunId): self
    {
        return new self($rootRunId, microtime(true));
    }

    public function steps(): int
    {
        return $this->steps;
    }

    public function costUsd(): float
    {
        return $this->costUsd;
    }

    public function elapsedSeconds(): float
    {
        return microtime(true) - $this->startedAt;
    }

    public function recordSteps(int $steps): void
    {
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
        if ($usd === null) {
            $this->unmeteredRuns++;

            return;
        }

        $this->costUsd += max(0.0, $usd);
    }

    public function unmeteredRuns(): int
    {
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
        $this->cancelled = true;
        $this->cancelReason = $reason;
    }

    public function cancelled(): bool
    {
        return $this->cancelled;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function remainingCost(RunBudget $budget): ?float
    {
        return $budget->maxCostUsd === null ? null : max(0.0, $budget->maxCostUsd - $this->costUsd);
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
}
