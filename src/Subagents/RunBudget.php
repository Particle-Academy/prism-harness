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
        // A TREE-ABSOLUTE ceiling, not a remainder. The ledger is shared by the
        // whole tree and counts CUMULATIVELY, and `RunLedger::exhaustion()`
        // compares its running totals against whatever budget it is handed --
        // so a budget expressed as "what is left" is in the wrong unit and the
        // comparison is nonsense the moment the parent has spent anything.
        //
        // It used to be a remainder, and the arithmetic went like this: a parent
        // allowed 8 steps that had taken 7, spawning a child asking for 2, got
        // `min(2, 8 - 7) = 1` -- and then `exhaustion()` asked `7 >= 1` and
        // refused the child outright. One step remained and the child received
        // none, worsening with depth. Reported as prism-harness#10.
        //
        // Expressed as a ceiling instead, both halves are in the ledger's own
        // unit: the child may run until the tree total reaches whichever comes
        // first, its own allowance added to what has already been spent, or the
        // parent's hard limit.
        $seconds = self::lesser(
            $parent->maxSeconds === null ? null : (float) $parent->maxSeconds,
            $this->maxSeconds === null ? null : $ledger->elapsedSeconds() + (float) $this->maxSeconds,
        );

        return new self(
            maxSteps: min($parent->maxSteps, $ledger->steps() + $this->maxSteps),
            maxCostUsd: self::lesser(
                $parent->maxCostUsd,
                $this->maxCostUsd === null ? null : $ledger->costUsd() + $this->maxCostUsd,
            ),
            maxSeconds: $seconds === null ? null : (int) ceil($seconds),
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
