<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

/**
 * What a run is allowed to spend.
 *
 * `maxSteps` alone was never a budget. It bounds ITERATIONS, and twenty steps
 * each calling an expensive tool sits comfortably inside it — so a run could
 * respect its declared limit and still cost more than anyone intended. Cost and
 * wall-clock are the two that a person actually cares about when they say
 * "bounded".
 *
 * The vocabulary is deliberately the one prism-labs already uses for benchmark
 * budgets (cost / turn / time) rather than a second one invented here. Two
 * spellings of the same idea across one ecosystem is how a limit gets set in the
 * place that isn't enforced.
 */
final readonly class RunBudget
{
    public function __construct(
        public int $maxSteps,
        public ?float $maxCostUsd = null,
        public ?int $maxSeconds = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config, int $defaultSteps = 8): self
    {
        $steps = $config['max_steps'] ?? $defaultSteps;
        $cost = $config['max_cost_usd'] ?? null;
        $seconds = $config['max_seconds'] ?? null;

        return new self(
            maxSteps: is_numeric($steps) ? (int) $steps : $defaultSteps,
            maxCostUsd: is_numeric($cost) ? (float) $cost : null,
            maxSeconds: is_numeric($seconds) ? (int) $seconds : null,
        );
    }

    /**
     * The budget a CHILD actually gets.
     *
     * BUDGETS NEST; THEY DO NOT RESET. This was the open question in the README
     * and it only has one defensible answer: a resetting budget is not a budget.
     * A parent limited to 8 steps that may spawn subagents each entitled to a
     * fresh 8 has no bound at all — it has a bound per node in a tree it also
     * controls the width of, which is unbounded spend wearing a limit's
     * clothing.
     *
     * So a child gets the SMALLER of what it declares and what the tree has
     * left. A child may ask for less than it is offered; it may never ask for
     * more than remains.
     */
    public function nestedWithin(self $parent, RunLedger $ledger): self
    {
        $seconds = self::lesser(
            $this->maxSeconds === null ? null : (float) $this->maxSeconds,
            $ledger->remainingSeconds($parent) === null ? null : (float) $ledger->remainingSeconds($parent),
        );

        return new self(
            maxSteps: min($this->maxSteps, max(0, $parent->maxSteps - $ledger->steps())),
            maxCostUsd: self::lesser($this->maxCostUsd, $ledger->remainingCost($parent)),
            maxSeconds: $seconds === null ? null : (int) $seconds,
        );
    }

    private static function lesser(?float $a, ?float $b): ?float
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        return min($a, $b);
    }
}
