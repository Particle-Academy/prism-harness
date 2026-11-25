<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

use InvalidArgumentException;
use Prism\Harness\Exceptions\PlanRefused;

/**
 * How a plan's steps are budgeted, and the plan's shared ledger (G-366).
 *
 * The app's developer chooses, in `prism-harness.plans.budget` or per plan
 * through host code ({@see PlanHandle::useBudget()}); never the model, never a
 * per-call option:
 *
 * - `per_step` (default): each step's turn gets the per-turn budget, as it
 *   always has.
 * - `shared`: every turn that serves the plan also spends from ONE ledger
 *   with `max_steps` model steps and, optionally, `max_cost_usd`. Each turn
 *   still keeps its own per-turn limits; the ledger bounds the total.
 * - `none`: no plan-level budget. Per-turn limits, the plan's own step and
 *   advance limits, workflow budgets and agent spend ceilings still apply.
 *
 * Pure state in, state out, the same in PHP, TypeScript and Python (pinned by
 * the `harness-plans` corpus). {@see Plans} keeps the state in the plan store
 * and changes it in one locked write per change.
 *
 * The ledger is a hold-and-settle account. Before a turn, the steps and cost
 * it may spend are HELD; after it, the hold is replaced by what it spent. Two
 * turns can therefore never both be granted the same remainder. A hold that
 * outlives `hold_ttl` (a turn that died without settling) is charged in full:
 * an unknown spend counts as spent, never as free.
 *
 * Costs are kept in whole micro-USD so every language adds them exactly.
 */
final class PlanBudget
{
    public const MODES = ['per_step', 'shared', 'none'];

    /** The code a turn and a plan stop with when the shared ledger is spent. */
    public const EXHAUSTED = 'plan_budget_exhausted';

    public const STEPS_CEILING = 10000;

    public const COST_CEILING_USD = 1000000;

    private const KEYS = ['mode', 'max_steps', 'max_cost_usd'];

    /**
     * Validate a budget setting. Throws for anything invalid: an unknown key or
     * mode, a limit out of range, or `shared` without `max_steps`.
     *
     * @return array{mode: string, max_steps: ?int, max_cost_micros: ?int}
     *
     * @throws InvalidArgumentException
     */
    public static function settings(mixed $config, string $source = 'prism-harness.plans.budget', bool $complete = true): array
    {
        if ($config === null) {
            return ['mode' => 'per_step', 'max_steps' => null, 'max_cost_micros' => null];
        }
        if (! is_array($config) || ($config !== [] && array_is_list($config))) {
            throw new InvalidArgumentException("{$source} must be a map.");
        }
        foreach (array_keys($config) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException("{$source} has an unknown setting.");
            }
        }
        $mode = $config['mode'] ?? 'per_step';
        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("{$source}.mode must be per_step, shared or none.");
        }
        $steps = $config['max_steps'] ?? null;
        if ($steps !== null && (! is_int($steps) || $steps < 1 || $steps > self::STEPS_CEILING)) {
            throw new InvalidArgumentException("{$source}.max_steps must be a whole number between 1 and ".self::STEPS_CEILING.', or null.');
        }
        $cost = $config['max_cost_usd'] ?? null;
        if ($cost !== null && ((! is_int($cost) && ! is_float($cost)) || ! is_finite((float) $cost) || $cost < 0 || $cost > self::COST_CEILING_USD)) {
            throw new InvalidArgumentException("{$source}.max_cost_usd must be a number of USD between 0 and ".self::COST_CEILING_USD.', or null.');
        }
        if ($complete && $mode === 'shared' && $steps === null) {
            throw new InvalidArgumentException("{$source}.max_steps is required when the mode is shared.");
        }

        return ['mode' => $mode, 'max_steps' => $steps, 'max_cost_micros' => $cost === null ? null : self::micros((float) $cost)];
    }

    /**
     * The setting for one plan, chosen by host code: its mode, with each limit
     * the lower of the host's and the configured one.
     *
     * @param  array{mode: string, max_steps: ?int, max_cost_micros: ?int}  $configured
     * @return array{mode: string, max_steps: ?int, max_cost_micros: ?int}
     */
    public static function forPlan(array $configured, mixed $requested): array
    {
        $asked = self::settings($requested, 'the plan budget', false);
        $chosen = [
            'mode' => $asked['mode'],
            'max_steps' => self::lower($configured['max_steps'], $asked['max_steps']),
            'max_cost_micros' => self::lower($configured['max_cost_micros'], $asked['max_cost_micros']),
        ];
        if ($chosen['mode'] === 'shared' && $chosen['max_steps'] === null) {
            throw new InvalidArgumentException('the plan budget.max_steps is required when the mode is shared.');
        }

        return $chosen;
    }

    /**
     * A plan's budget record, fixed from a setting.
     *
     * @param  array{mode: string, max_steps: ?int, max_cost_micros: ?int}  $settings
     * @return array<string, mixed>
     */
    public static function open(string $planId, array $settings): array
    {
        return [
            'planId' => $planId,
            'mode' => $settings['mode'],
            'max_steps' => $settings['max_steps'],
            'max_cost_micros' => $settings['max_cost_micros'],
            'steps' => 0,
            'cost_micros' => 0,
            'unmetered' => 0,
            'holds' => [],
        ];
    }

    /**
     * A later host choice on a record that is already fixed: the mode cannot
     * change, and a limit can only be lowered.
     *
     * @param  array<string, mixed>  $state
     * @param  array{mode: string, max_steps: ?int, max_cost_micros: ?int}  $settings
     * @return array<string, mixed>
     *
     * @throws PlanRefused plan.budget_fixed
     */
    public static function narrow(array $state, array $settings): array
    {
        if ($settings['mode'] !== $state['mode']) {
            throw PlanRefused::because('plan.budget_fixed');
        }
        $state['max_steps'] = self::lower($state['max_steps'], $settings['max_steps']);
        $state['max_cost_micros'] = self::lower($state['max_cost_micros'], $settings['max_cost_micros']);

        return $state;
    }

    /**
     * Which limit is spent, or null. Holds do not count: they are spend that
     * may yet be returned.
     *
     * @param  array<string, mixed>  $state
     */
    public static function exhaustion(array $state): ?string
    {
        if ($state['mode'] !== 'shared') {
            return null;
        }
        if ($state['steps'] >= $state['max_steps']) {
            return 'steps';
        }
        if ($state['max_cost_micros'] !== null) {
            // A cost a provider did not report cannot be held to a cap: closed.
            if ($state['unmetered'] > 0) {
                return 'cost_unmetered';
            }
            if ($state['cost_micros'] >= $state['max_cost_micros']) {
                return 'cost';
            }
        }

        return null;
    }

    /**
     * Hold what one turn may spend: at most `$steps` steps and `$costMicros`
     * (null: no per-turn cost cap), never more than the ledger has left after
     * every other hold. Expired holds are charged in full first.
     *
     * Returns the state and either the grant (`steps`, `cost_micros`, null
     * when the plan has no cost cap) or the refusal (`refused` with the
     * EXHAUSTED code and the `limit`).
     *
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function reserve(array $state, string $holdId, int $steps, ?int $costMicros, int $now, int $holdTtl): array
    {
        $state = self::expire($state, $now, $holdTtl);
        $heldSteps = 0;
        $heldCost = 0;
        foreach ($state['holds'] as $hold) {
            $heldSteps += $hold['steps'];
            $heldCost += $hold['cost_micros'];
        }
        $limit = self::exhaustion($state);
        $leftSteps = $state['max_steps'] - $state['steps'] - $heldSteps;
        $leftCost = $state['max_cost_micros'] === null ? null : $state['max_cost_micros'] - $state['cost_micros'] - $heldCost;
        if ($limit === null && $leftSteps <= 0) {
            $limit = 'steps';
        }
        if ($limit === null && $leftCost !== null && $leftCost <= 0) {
            $limit = 'cost';
        }
        if ($limit !== null || $steps < 1) {
            return [$state, ['refused' => self::EXHAUSTED, 'limit' => $limit ?? 'steps']];
        }
        $grantSteps = min($steps, $leftSteps);
        $grantCost = $leftCost === null ? null : ($costMicros === null ? $leftCost : min($costMicros, $leftCost));
        $state['holds'][$holdId] = ['steps' => $grantSteps, 'cost_micros' => $grantCost ?? 0, 'at' => $now];
        ksort($state['holds'], SORT_STRING);

        return [$state, ['steps' => $grantSteps, 'cost_micros' => $grantCost]];
    }

    /**
     * Replace a turn's hold with what it spent. `$costMicros` null means the
     * provider reported no cost. A hold already gone (settled, or expired and
     * charged in full) changes nothing.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function settle(array $state, string $holdId, int $steps, ?int $costMicros): array
    {
        if (! isset($state['holds'][$holdId])) {
            return $state;
        }
        unset($state['holds'][$holdId]);
        $state['steps'] += max(0, $steps);
        if ($costMicros === null) {
            $state['unmetered']++;
        } else {
            $state['cost_micros'] += max(0, $costMicros);
        }

        return $state;
    }

    /**
     * Charge every hold older than the TTL in full.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function expire(array $state, int $now, int $holdTtl): array
    {
        foreach ($state['holds'] as $id => $hold) {
            if ($now - $hold['at'] >= $holdTtl) {
                unset($state['holds'][$id]);
                $state['steps'] += $hold['steps'];
                $state['cost_micros'] += $hold['cost_micros'];
            }
        }

        return $state;
    }

    /**
     * Which budgets a turn spends from. Pure, so every language answers it
     * the same way (prism-parity harness-plans).
     *
     * `$context` is the run context the turn was handed: `none`, `metadata`
     * (a turnId/actor only, the same as none), `runner` (a context of a run
     * this runtime is running, such as the subagent runner's child) or
     * `host` (any other context the host built). `$advancing` is true for
     * the turn advance() runs for a claimed agent step, and `$stepApproval`
     * when the approval the turn would resume is one that step asked for.
     * `$savedTree` and `$savedPlanLedger` say whether the session is a
     * subagent child saved with a run tree and a shared plan budget.
     *
     * The answer:
     * - `tree`: `open` (a root run that opens a tree of its own), `continue`
     *   (hold what the saved tree has left; a host context is narrowed to
     *   it) or `none` (the runner's own context already spends from it).
     * - `plan`: `own` (the plan's own turn spends from its budget), `child`
     *   (the saved plan budget; a host context is narrowed to it), `refused`
     *   (`plan.budget_context`: the plan's own turn handed a run tree of its
     *   own) or `none`.
     *
     * The prompt matters only to whether a turn is the plan's own: a new
     * message on a child session continues its tree and plan budget exactly
     * as an answer to its approval does. No route gives a child a fresh
     * budget, and a host context can only narrow one.
     *
     * @return array{tree: 'open'|'continue'|'none', plan: 'own'|'child'|'refused'|'none'}
     */
    public static function route(string $context, string $prompt, bool $advancing, bool $stepApproval, bool $savedTree, bool $savedPlanLedger): array
    {
        if (! in_array($context, ['none', 'metadata', 'runner', 'host'], true)) {
            throw new InvalidArgumentException('Unknown run context kind ['.$context.'].');
        }
        $bare = $context === 'none' || $context === 'metadata';
        $tree = $context === 'runner' ? 'none' : ($savedTree ? 'continue' : ($bare ? 'open' : 'none'));
        if ($advancing || ($prompt === '' && $stepApproval)) {
            $plan = $bare ? 'own' : 'refused';
        } else {
            $plan = $context !== 'runner' && $savedPlanLedger ? 'child' : 'none';
        }

        return ['tree' => $tree, 'plan' => $plan];
    }

    public static function micros(?float $usd): ?int
    {
        return $usd === null ? null : (int) round($usd * 1_000_000);
    }

    private static function lower(?int $a, ?int $b): ?int
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }
}
