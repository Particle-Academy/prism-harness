<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

/**
 * Whether a runner start may begin (G-387): ONE decision, made before the
 * start writes anything.
 *
 * A start used to check its stop conditions in five or more places (the
 * runner's pre-checks, the runtime's refusals before the run began, the tree
 * hold, and the refusals after the child's records were saved), and to write
 * durable state between them. A start refused late had already re-bound its
 * child and could revive an approval nobody could answer. Now the runner
 * reads every fact first, without writing, asks this gate once, and only a
 * start it lets through commits anything. Once committed, a later stop only
 * stops model steps; it never refuses the start.
 *
 * The facts (each read, never written):
 *
 *  - `task`: the raw value the model sent;
 *  - `too_deep`: the child would be past the maximum nesting depth;
 *  - `cancelled` / `time_spent`: of the context the child would run under
 *    and of the context the subagent tool was wired with;
 *  - `steps_left`: what this start's own allowance would give it
 *    ({@see RunBudgets::allowance()} for kind `runner`);
 *  - `cost`: `ok`, `spent` (a cost limit that would stop the child is spent:
 *    the context's cap or the agent's spend ceiling) or `unenforceable` (a
 *    cost cap that cannot be metered);
 *  - `tool_calls_spent`: PHP only, the context's tool-call limit is spent
 *    (the ports have no such limit; absent is false);
 *  - `child_busy`: a live hold on the child's delegation count;
 *  - `child_pending`: `none`, `answerable` (a person's decision on it would
 *    be accepted) or `unanswerable`;
 *  - `tree_left` / `plan_left`: what the run tree's and the plan's records
 *    would grant this start now, null when none applies.
 *
 * Asked in ONE fixed order, so every language refuses with the same reason.
 * Pure, and pinned across the three languages by the harness-plans corpus op
 * `start_gate`.
 */
final class RunStarts
{
    /** @var list<string> */
    public const COSTS = ['ok', 'spent', 'unenforceable'];

    /** @var list<string> */
    public const PENDING = ['none', 'answerable', 'unanswerable'];

    /**
     * The outcome each refusal is reported with.
     *
     * @var array<string, 'denied'|'cancelled'|'exhausted'>
     */
    public const OUTCOMES = [
        'task_invalid' => 'denied',
        'task_blank' => 'denied',
        'too_deep' => 'denied',
        'cancelled' => 'cancelled',
        'time' => 'exhausted',
        'steps' => 'exhausted',
        'cost' => 'exhausted',
        'cost_unenforceable' => 'exhausted',
        'tool_calls' => 'exhausted',
        'child_busy' => 'denied',
        'child_waiting' => 'denied',
        'tree' => 'exhausted',
        'plan' => 'exhausted',
    ];

    /**
     * @param  array<string, mixed>  $facts
     * @return array{invalid: true}|array{gate: array{refused: string, outcome: string}}|array{gate: array{start: array{steps: int}}}
     */
    public static function gate(array $facts): array
    {
        if (! in_array($facts['cost'] ?? null, self::COSTS, true) || ! in_array($facts['child_pending'] ?? null, self::PENDING, true)) {
            return ['invalid' => true];
        }
        $refuse = static fn (string $code): array => ['gate' => ['refused' => $code, 'outcome' => self::OUTCOMES[$code]]];
        $task = $facts['task'] ?? null;
        if (! is_string($task)) {
            return $refuse('task_invalid');
        }
        if (self::blank($task)) {
            return $refuse('task_blank');
        }
        if (($facts['too_deep'] ?? null) === true) {
            return $refuse('too_deep');
        }
        if (($facts['cancelled'] ?? null) === true) {
            return $refuse('cancelled');
        }
        if (($facts['time_spent'] ?? null) === true) {
            return $refuse('time');
        }
        if (self::natural($facts['steps_left'] ?? null) < 1) {
            return $refuse('steps');
        }
        if ($facts['cost'] === 'spent') {
            return $refuse('cost');
        }
        if ($facts['cost'] === 'unenforceable') {
            return $refuse('cost_unenforceable');
        }
        // PHP only: the ports have no tool-call limit, and no corpus row sets it.
        if (($facts['tool_calls_spent'] ?? null) === true) {
            return $refuse('tool_calls');
        }
        if (($facts['child_busy'] ?? null) === true) {
            return $refuse('child_busy');
        }
        if ($facts['child_pending'] === 'answerable') {
            return $refuse('child_waiting');
        }
        // Absent is not "none applies": only an explicit null is.
        $tree = array_key_exists('tree_left', $facts) ? $facts['tree_left'] : 0;
        $plan = array_key_exists('plan_left', $facts) ? $facts['plan_left'] : 0;
        if ($tree !== null && self::natural($tree) < 1) {
            return $refuse('tree');
        }
        if ($plan !== null && self::natural($plan) < 1) {
            return $refuse('plan');
        }
        $steps = self::natural($facts['steps_left'] ?? null);
        if ($tree !== null) {
            $steps = min($steps, self::natural($tree));
        }
        if ($plan !== null) {
            $steps = min($steps, self::natural($plan));
        }

        return ['gate' => ['start' => ['steps' => $steps]]];
    }

    /**
     * Whether a task holds only blank codepoints: U+0009-U+000D, U+0020,
     * U+0085, U+00A0, U+1680, U+2000-U+200D, U+2028, U+2029, U+202F, U+205F,
     * U+2060, U+3000 and U+FEFF. One explicit set, spelled the same in all
     * three languages (never the language's own trim, which differs between
     * them). Text that is not valid UTF-8 is not blank.
     */
    public static function blank(string $task): bool
    {
        return preg_match('/\A[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200D}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]*\z/u', $task) === 1;
    }

    /** A positive safe integer, or 0 for anything else. */
    private static function natural(mixed $value): int
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            $value = abs($value) <= 9007199254740991 ? (int) $value : null;
        }

        return is_int($value) && $value > 0 && $value <= 9007199254740991 ? $value : 0;
    }
}
