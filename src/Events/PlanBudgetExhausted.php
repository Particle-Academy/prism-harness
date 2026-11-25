<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A plan stopped because its shared budget is spent (G-366).
 *
 * Dispatched once, when advance() blocks the plan for it: the plan stays
 * blocked with `blockedReason` `plan_budget_exhausted`, and resume() refuses
 * it. Counts only; no plan text.
 */
final readonly class PlanBudgetExhausted
{
    public function __construct(
        public string $sessionKey,
        public string $planId,
        /** Always `plan_budget_exhausted`, the code all three languages use. */
        public string $code,
        /** Which limit: `steps`, `cost` or `cost_unmetered`. */
        public string $limit,
        public int $steps,
        public float $costUsd,
        public ?string $harnessAgent = null,
    ) {}
}
