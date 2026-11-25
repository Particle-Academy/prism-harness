<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use InvalidArgumentException;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Plans\PlanBudget;
use Prism\Harness\Sessions\Session;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * The durable half of a run tree's budget, for subagent children resumed
 * outside the runner.
 *
 * A child that stops on an approval is resumed later on its own session, in a
 * run the runner did not start. That run must not get a fresh root budget: it
 * continues the run tree it belonged to. While the tree runs, its budget is
 * the in-memory RunLedger every run in it shares. The first time a root run
 * starts a child, the tree's allowance is written to the durable store as a
 * ledger record (the shape {@see PlanBudget} keeps for a shared plan budget)
 * with the root turn holding all of it; when the root turn ends it settles
 * what the whole tree spent. A resumed child then holds what the tree has
 * left before it runs, settles what it spent when it ends, and is refused
 * once the tree's allowance is spent. A hold whose run never settled is
 * charged in full once the run lock's TTL has passed, so a crash can only
 * over-charge the tree.
 *
 * Bound as a singleton.
 */
final class RunTrees
{
    /**
     * Root runs of this process, by run id, until they end.
     *
     * @var array<string, array{session: string, tree: array{session: string, run: string}, budget: RunBudget, ledger: RunLedger, steps: int, cost: float, unmetered: int, held: bool}>
     */
    private array $runs = [];

    /**
     * Every run of this process, by run id, until it ends: the run tree it
     * belongs to, decided once when it began ({@see self::membership()}), the
     * session it runs on and the ledger it spends from. The one record that
     * childStarted, the kept references, the depth and the budget route read;
     * a child the runner starts takes its parent's from here.
     *
     * @var array<string, array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route: string, session: string, ledger: RunLedger}>
     */
    private array $members = [];

    /**
     * Which run tree a run that is beginning belongs to (G-381), from facts
     * this runtime holds and nothing a host states: for the runner's own
     * context, the recorded membership of the live parent run the runner
     * started it from, accepted only while that run is live on the session
     * the runner named and the context spends from that run's own ledger; for
     * any other turn, the tree and depth the session saved.
     *
     * @internal Called by the runtime once, before the run's binding is chosen.
     *
     * @return array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route: 'open'|'continue'|'none'}
     */
    public function begin(Session $session, string $runId, ?RunContext $context, string $prompt = ''): array
    {
        $kind = $this->contextKind($context);
        $parent = null;
        if ($kind === 'runner' && $context !== null) {
            $mark = $this->childContexts[$context] ?? null;
            $live = is_array($mark) ? ($this->members[$mark['run']] ?? null) : null;
            if ($live !== null && $live['session'] === $mark['session'] && $live['ledger'] === $context->ledger) {
                $parent = ['tree' => $live['tree'], 'spends' => $live['spends'], 'depth' => $live['depth']];
            }
        }

        $bare = $kind === 'none' || $kind === 'metadata';

        return self::membership($kind, $session->key(), $runId, $parent, $session->childTree(), $session->childDepth(),
            $bare || $context === null ? 0 : $context->depth, $bare ? $session->resumedRunTree($prompt) : null);
    }

    /**
     * A run is starting, under the membership it began with: a root opens a
     * tree of its own, kept in memory until it starts a child; a turn that
     * continues a saved tree (an answer to a child's approval, a new message,
     * or a run context the host built) holds what that tree has left, and its
     * run is narrowed to the hold, so a host context can only lower what the
     * tree allows. Any other run spends from the context it was given.
     *
     * @internal Called by the runtime once a run's budget is known.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route: string}  $membership
     *
     * @throws RunNotPermitted when the tree the child belongs to is spent or cancelled
     */
    public function rootRun(Session $session, string $runId, RunContext $run, array $membership): RunContext
    {
        $this->members[$runId] = [...$membership, 'session' => $session->key(), 'ledger' => $run->ledger];
        $route = $membership['route'];
        if ($route !== 'open' && $route !== 'continue') {
            return $run;
        }
        $tree = $membership['tree'];
        if (! is_array($tree)) {
            return $run;
        }
        // A turn on a child of a cancelled tree never runs (G-285): the
        // person stopped the whole tree, this child included.
        if ($route === 'continue' && $this->treeCancelled($session, $tree)) {
            throw RunNotPermitted::cancelled();
        }
        $entry = [
            'session' => $session->key(), 'tree' => $tree,
            'budget' => $run->budget, 'ledger' => $run->ledger, 'steps' => $run->ledger->steps(),
            'cost' => $run->ledger->costUsd(), 'unmetered' => $run->ledger->unmeteredRuns(), 'held' => false,
        ];
        if ($route === 'open') {
            $this->runs[$runId] = $entry;

            return $run;
        }
        $budget = $run->budget;
        $turnCost = $budget->maxCostUsd === null ? null : PlanBudget::micros(max(0.0, $budget->maxCostUsd - $entry['cost']));
        $now = time();
        $ttl = self::holdTtl();
        $grant = $this->write($session->planStore(), $tree, static function (?array $state) use ($runId, $budget, $entry, $turnCost, $now, $ttl): array {
            if ($state === null) {
                // The tree's record is gone: closed, never a fresh budget.
                return [null, ['refused' => PlanBudget::EXHAUSTED, 'limit' => 'steps']];
            }

            return PlanBudget::reserve($state, $runId, max(0, $budget->maxSteps - $entry['steps']), $turnCost, $now, $ttl);
        });
        if (isset($grant['refused'])) {
            throw RunNotPermitted::exhausted((string) $grant['limit']);
        }

        $narrowed = $run->withBudget(new RunBudget(
            maxSteps: $entry['steps'] + (int) $grant['steps'],
            maxCostUsd: $grant['cost_micros'] === null ? $budget->maxCostUsd : $entry['cost'] + $grant['cost_micros'] / 1_000_000,
            maxSeconds: $budget->maxSeconds,
            maxOutputTokensPerCall: $budget->maxOutputTokensPerCall,
            maxToolCalls: $budget->maxToolCalls,
        ));
        $this->runs[$runId] = [...$entry, 'budget' => $narrowed->budget, 'ledger' => $narrowed->ledger, 'held' => true];

        return $narrowed;
    }

    /**
     * Whether this run spends from a durable run tree record, so its steps
     * must be counted as they are taken.
     *
     * @internal
     */
    public function holds(string $runId): bool
    {
        return ($this->runs[$runId]['held'] ?? false) === true;
    }

    /**
     * A child is starting under a run tree: the tree its membership names,
     * opened in the durable store the first time the run it spends from (a
     * root of this runtime) starts a child. Null for a child in the host's
     * tree or in none.
     *
     * @internal Saved with the child's binding.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route?: string}  $membership
     * @return array{session: string, run: string}|null
     */
    public function childStarted(Session $child, array $membership): ?array
    {
        $tree = $membership['tree'];
        if (! is_array($tree)) {
            return null;
        }
        $key = $membership['spends'];
        $entry = $key === null ? null : ($this->runs[$key] ?? null);
        if ($entry !== null && ! $entry['held']) {
            $budget = $entry['budget'];
            $settings = [
                'mode' => 'shared',
                'max_steps' => max(0, $budget->maxSteps - $entry['steps']),
                'max_cost_micros' => $budget->maxCostUsd === null ? null : PlanBudget::micros(max(0.0, $budget->maxCostUsd - $entry['cost'])),
            ];
            $now = time();
            $ttl = self::holdTtl();
            $this->write($child->planStore(), $entry['tree'], static function (?array $state) use ($tree, $key, $settings, $now, $ttl): array {
                // Named by the tree's root run: a root resuming its parked run
                // holds under its own run id in the tree that run began.
                $state ??= PlanBudget::open($tree['run'], $settings);

                return PlanBudget::reserve($state, $key, max(1, (int) $settings['max_steps']), $settings['max_cost_micros'], $now, $ttl);
            });
            $this->runs[$key]['held'] = true;
        }

        return $tree;
    }

    /**
     * Refuse, before anything is recorded, a decision on a child session
     * whose run tree has nothing left: recorded, it would be consumed by a
     * resume the tree then refuses, and the approved call would never run.
     *
     * @internal Called by the session before it records decisions.
     *
     * @throws RunNotPermitted
     */
    public function assertDecisionsFunded(Session $session, ?RunContext $context): void
    {
        // The turn the decision resumes, as it would begin.
        $membership = $this->begin($session, 'decision', $context);
        $tree = $membership['tree'];
        if ($membership['route'] !== 'continue' || ! is_array($tree)) {
            return;
        }
        $state = $session->planStore()->get(self::key($tree));
        if (! is_array($state) || ($state['planId'] ?? null) !== $tree['run']) {
            throw RunNotPermitted::exhausted('steps');
        }
        [, $grant] = PlanBudget::reserve($state, 'decision', 1, null, time(), self::holdTtl());
        if (isset($grant['refused'])) {
            throw RunNotPermitted::exhausted((string) $grant['limit']);
        }
    }

    /**
     * A run is over: a root run that holds part of a tree settles what the
     * tree spent under it.
     *
     * @internal Called when a session finishes a run.
     */
    public function finished(Session $session, string $runId): void
    {
        unset($this->members[$runId]);
        $entry = $this->runs[$runId] ?? null;
        if ($entry === null || $entry['session'] !== $session->key()) {
            return;
        }
        unset($this->runs[$runId]);
        if (! $entry['held']) {
            return;
        }
        $ledger = $entry['ledger'];
        $steps = $ledger->steps() - $entry['steps'];
        $cost = $ledger->unmeteredRuns() > $entry['unmetered'] ? null : PlanBudget::micros(max(0.0, $ledger->costUsd() - $entry['cost']));
        try {
            $this->write($session->planStore(), $entry['tree'], static fn (?array $state): array => $state === null
                ? [null, null] : [PlanBudget::settle($state, $runId, $steps, $cost), null]);
        } catch (Throwable $failure) {
            // The hold stays and is charged in full once it expires.
            report(new RuntimeException('A run tree budget update failed: '.$failure::class.'.'));
        }
    }

    /**
     * The child contexts the subagent runner built, held weakly, each with
     * the run it was started from and that run's session: the parent the
     * runner took from the turn calling the subagent tool.
     *
     * @var WeakMap<RunContext, array{run: string, session: string}>|null
     */
    private ?WeakMap $childContexts = null;

    /**
     * @internal Called by the subagent runner for the context it starts a
     * child under, with the run whose turn is starting it.
     */
    public function startingChild(RunContext $context, string $parentRunId, string $parentSession): RunContext
    {
        $this->childContexts ??= new WeakMap;
        $this->childContexts[$context] = ['run' => $parentRunId, 'session' => $parentSession];

        return $context;
    }

    /**
     * The kind of context a turn was handed, for {@see ChildBinding::route()}:
     * the same kind as {@see self::contextKind()}, so the binding and the
     * budget routes read one context the same way.
     *
     * @internal
     *
     * @return 'none'|'metadata'|'runner'|'host'
     */
    public function bindingContextKind(?RunContext $context): string
    {
        return $this->contextKind($context);
    }

    /**
     * What kind of run context a turn was handed: `runner` only for the
     * context the subagent runner built and marked to start a child, even
     * under a run tree the host built. Any other context that states a run
     * tree is the host's, whatever ledger or run ids it carries.
     *
     * @internal
     *
     * @return 'none'|'metadata'|'runner'|'host'
     */
    public function contextKind(?RunContext $context): string
    {
        if ($context === null) {
            return 'none';
        }
        if (isset($this->childContexts[$context])) {
            return 'runner';
        }

        return $context->metadataOnly ? 'metadata' : 'host';
    }

    /**
     * One locked change to a tree's record.
     *
     * @param  array{session: string, run: string}  $tree
     * @param  Closure(array<string, mixed>|null): array{0: array<string, mixed>|null, 1: mixed}  $change
     */
    private function write(SessionStore $store, array $tree, Closure $change): mixed
    {
        $key = self::key($tree);

        return $store->withLock($key, function () use ($store, $key, $tree, $change): mixed {
            $state = $store->get($key);
            $state = is_array($state) && ($state['planId'] ?? null) === $tree['run'] ? $state : null;
            [$next, $result] = $change($state);
            if ($next !== null) {
                $store->put($key, $next);
            }

            return $result;
        }, 10, 5);
    }

    /** @param array{session: string, run: string} $tree */
    private static function key(array $tree): string
    {
        return $tree['session'].':run-tree:'.$tree['run'];
    }

    /**
     * A person cancelled a run of this tree (G-285): every run in it stops,
     * the ones parked on a person in other sessions included. Kept apart from
     * the tree's budget record, durable, and never evicted, so a child
     * answered long after the cancel still reads it.
     *
     * @internal Written by {@see Session::cancel()}.
     *
     * @param  array{session: string, run: string}  $tree
     */
    public function cancelTree(Session $session, array $tree): void
    {
        $session->planStore()->put(self::key($tree).':cancelled', ['at' => now()->toIso8601String()]);
    }

    /**
     * Whether a person cancelled a run of this tree (G-285).
     *
     * @internal
     *
     * @param  array{session: string, run: string}|null  $tree
     */
    public function treeCancelled(Session $session, ?array $tree): bool
    {
        return $tree !== null && is_array($session->planStore()->get(self::key($tree).':cancelled'));
    }

    /**
     * Which run tree a run belongs to (G-381): decided ONCE, when the run
     * begins, and the one record every consumer reads.
     *
     *  - The runner's own context (`runner`) takes the recorded membership of
     *    the live parent run it was started from, verified by the runtime:
     *    the same tree, the same run it spends from, one level deeper. With no
     *    verified parent it is in no tree, at the depth the context states.
     *    The tree its session saved is never read: that is for turns the
     *    runner did not start.
     *  - Any other turn on a session that saved a tree continues that tree,
     *    spending from a hold of its own, at least as deep as it was started.
     *  - A host context on a session that saved none is in the host's tree.
     *  - No context, or a metadata-only one, opens a tree of its own, or,
     *    resuming its parked run, stays in the tree that run began in.
     *
     * A host context's own root and parent run ids are deliberately not
     * inputs: they are the host's statement, not a fact this runtime holds.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int}|null  $parent
     * @param  array{session: string, run: string}|null  $savedTree
     * @param  array{session: string, run: string}|null  $resumedTree  the tree the parked run a root turn resumes began in
     * @return array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route: 'open'|'continue'|'none'}
     *
     * @throws InvalidArgumentException for an unknown context kind
     */
    public static function membership(string $kind, string $session, string $run, ?array $parent, ?array $savedTree, int $savedDepth, int $contextDepth, ?array $resumedTree = null): array
    {
        if (! in_array($kind, ['none', 'metadata', 'runner', 'host'], true)) {
            throw new InvalidArgumentException('Unknown run context kind ['.$kind.'].');
        }
        $savedDepth = max(0, $savedDepth);
        $contextDepth = max(0, $contextDepth);
        if ($kind === 'runner') {
            return $parent === null
                ? ['tree' => 'none', 'spends' => null, 'depth' => $contextDepth, 'route' => 'none']
                : ['tree' => $parent['tree'], 'spends' => $parent['spends'], 'depth' => max(0, $parent['depth']) + 1, 'route' => 'none'];
        }
        if ($savedTree !== null) {
            return ['tree' => ['session' => $savedTree['session'], 'run' => $savedTree['run']], 'spends' => $run,
                'depth' => $kind === 'host' ? max($savedDepth, $contextDepth) : $savedDepth, 'route' => 'continue'];
        }
        if ($kind === 'host') {
            return ['tree' => 'host', 'spends' => null, 'depth' => max($savedDepth, $contextDepth), 'route' => 'none'];
        }

        // A root turn that resumes its parked run stays in the tree that run
        // began in: its subagents parked there belong to the same tree, and a
        // cancel of either must reach the other. It still spends from a
        // budget entry of its own.
        $tree = $resumedTree === null ? ['session' => $session, 'run' => $run]
            : ['session' => $resumedTree['session'], 'run' => $resumedTree['run']];

        return ['tree' => $tree, 'spends' => $run, 'depth' => $savedDepth, 'route' => 'open'];
    }

    /**
     * The run tree and plan ledger the runner saves with a child (G-381):
     * the tree it is in, with the plan ledger of the run it spends from. A
     * child in the host's tree, or in none, has no durable tree to name, so
     * what it saved before is kept, never replaced with nothing, unless a
     * person cancelled that tree: starting the child again is then a new
     * delegation, not a run of the cancelled tree.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route?: string}  $membership
     * @param  array{session: string, plan: string}|null  $planLedger
     * @param  array{session: string, run: string}|null  $savedTree
     * @param  array{session: string, plan: string}|null  $savedPlanLedger
     * @return array{tree: array{session: string, run: string}|null, plan_ledger: array{session: string, plan: string}|null}
     */
    public static function keptRefs(array $membership, ?array $planLedger, ?array $savedTree, ?array $savedPlanLedger, bool $savedTreeCancelled): array
    {
        if (is_array($membership['tree'])) {
            return ['tree' => $membership['tree'], 'plan_ledger' => $planLedger];
        }

        return $savedTreeCancelled ? ['tree' => null, 'plan_ledger' => null] : ['tree' => $savedTree, 'plan_ledger' => $savedPlanLedger];
    }

    private static function holdTtl(): int
    {
        $ttl = config('prism-harness.agent.lock_ttl', 300);

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }
}
