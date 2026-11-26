<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

/**
 * How many model steps a run may take (G-387): decided ONCE, when the run
 * begins, beside its membership ({@see RunTrees::membership()}), and the one
 * number every consumer reads. The loop's step gate, the row a run parks on,
 * the subagent runner's pre-check, the tree and plan holds and both decision
 * checks all read the record this produces, or the budget the run was
 * narrowed to with it; none of them works out "steps left" on its own.
 *
 * The inputs are facts this runtime holds, never a host's statement of what
 * is left:
 *
 *  - `mode_steps`: the session's own ceiling (its mode, or its own override),
 *    used only by a turn with no context and no saved ceiling;
 *  - `saved_ceiling`: the ceiling the runner gave a subagent child, saved
 *    with its binding and re-applied on every turn the runner did not start;
 *  - `context_left`: what the runner's or a host's context has left;
 *  - `steps_ceiling`: `max_steps_ceiling`, on the run's whole count;
 *  - `used`: what the parked run this turn resumes had used, its children
 *    included, read from the row it parked on (`harness_steps_used`);
 *  - `tree_left` / `plan_left`: what the run tree's and the plan's durable
 *    records granted it, null when none bounds the run;
 *  - `took`: the steps the run then took (for the row it parks on).
 *
 * A budget of 0 is not a refusal: acting on a decision a person recorded
 * takes no model step, so a run with nothing left still acts on them, and
 * only the next model step is refused (`acts_without_step`).
 *
 * Pure, and pinned across the three languages by the harness-plans corpus
 * op `step_allowance`.
 */
final class RunBudgets
{
    /** @var list<string> */
    public const KINDS = ['none', 'metadata', 'runner', 'host'];

    /**
     * @param  array<string, mixed>  $facts  see the class comment; `context` is the kind
     * @return array{invalid: true}|array{allowance: array{ceiling: int, used: int, own_left: int, tree_left: ?int, plan_left: ?int, allowance: int, acts_without_step: bool, used_at_park: int, left_at_park: int}}
     */
    public static function allowance(array $facts): array
    {
        $kind = $facts['context'] ?? null;
        if (! is_string($kind) || ! in_array($kind, self::KINDS, true)) {
            return ['invalid' => true];
        }
        $contextLeft = self::count($facts['context_left'] ?? null);
        if (($kind === 'runner' || $kind === 'host') && $contextLeft === null) {
            return ['invalid' => true];
        }
        $mode = self::count($facts['mode_steps'] ?? null) ?? 0;
        $saved = self::count($facts['saved_ceiling'] ?? null);
        $stepsCeiling = self::count($facts['steps_ceiling'] ?? null);
        // A runner start is a new run: a count parked on the session before
        // is never carried into a new delegation.
        $used = $kind === 'runner' ? 0 : (self::count($facts['used'] ?? null) ?? 0);
        $tree = self::count($facts['tree_left'] ?? null);
        $plan = self::count($facts['plan_left'] ?? null);
        $took = self::count($facts['took'] ?? null) ?? 0;

        if ($kind === 'runner') {
            $ceiling = (int) $contextLeft;
        } elseif ($saved !== null) {
            $ceiling = $saved;
        } elseif ($kind === 'host') {
            $ceiling = $used + (int) $contextLeft;
        } else {
            $ceiling = $mode;
        }
        if ($stepsCeiling !== null) {
            $ceiling = min($ceiling, $stepsCeiling);
        }
        $ownLeft = max(0, $ceiling - $used);
        if ($kind === 'host') {
            // A host context only ever lowers what the run has left.
            $ownLeft = min($ownLeft, (int) $contextLeft);
        }
        $left = $ownLeft;
        if ($tree !== null) {
            $left = min($left, $tree);
        }
        if ($plan !== null) {
            $left = min($left, $plan);
        }
        $taken = min($took, $left);

        return ['allowance' => [
            'ceiling' => $ceiling,
            'used' => $used,
            'own_left' => $ownLeft,
            'tree_left' => $tree,
            'plan_left' => $plan,
            'allowance' => $left,
            'acts_without_step' => $left === 0,
            'used_at_park' => $used + $taken,
            'left_at_park' => $left - $taken,
        ]];
    }

    /**
     * Whether a stored `harness_steps_used` is a count this runtime could have
     * written: a non-negative integer no larger than a JSON-safe one.
     */
    public static function validUsed(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 9007199254740991;
    }

    private static function count(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        return max(0, is_int($value) ? $value : (int) $value);
    }
}
