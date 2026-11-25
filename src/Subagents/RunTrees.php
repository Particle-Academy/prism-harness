<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
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
     * For each run the runner's own context is running, the live run it
     * spends from (its key in $runs), or null under a tree the host built.
     * A child it starts is found through its parent run id, never through a
     * ledger another session may share.
     *
     * @var array<string, ?string>
     */
    private array $lineage = [];

    /**
     * A run is starting. A turn on a child session the runner did not start
     * (an answer to its approval, a new message, or a run context the host
     * built) continues the tree its binding names: it holds what that tree
     * has left, and its run is narrowed to the hold, so a host context can
     * only lower what the tree allows. Any other root run opens a tree of its
     * own, kept in memory until it starts a child. A run on the runner's own
     * context already spends from its tree and is left alone.
     *
     * @internal Called by the runtime once a run's budget is known.
     *
     * @throws RunNotPermitted when the tree the child belongs to is spent
     */
    public function rootRun(Session $session, string $runId, RunContext $run, ?RunContext $context = null): RunContext
    {
        $route = PlanBudget::route($this->contextKind($context), '', false, false, $session->childTree() !== null, false)['tree'];
        if ($route === 'none') {
            if ($context !== null && $this->contextKind($context) === 'runner') {
                $this->lineage[$runId] = $this->entryKey($context);
            }

            return $run;
        }
        $tree = $route === 'continue' ? $session->childTree() : null;
        // A turn on a child of a cancelled tree never runs (G-285): the
        // person stopped the whole tree, this child included.
        if ($tree !== null && $this->treeCancelled($session, $tree)) {
            throw RunNotPermitted::cancelled();
        }
        $entry = [
            'session' => $session->key(), 'tree' => $tree ?? ['session' => $session->key(), 'run' => $runId],
            'budget' => $run->budget, 'ledger' => $run->ledger, 'steps' => $run->ledger->steps(),
            'cost' => $run->ledger->costUsd(), 'unmetered' => $run->ledger->unmeteredRuns(), 'held' => false,
        ];
        if ($tree === null) {
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
     * A child is starting under a run tree: the tree it belongs to, opened
     * in the durable store the first time its root starts a child. Null when
     * the tree's root is not a run of this runtime (a host-made context).
     *
     * @internal Saved with the child's binding.
     *
     * @return array{session: string, run: string}|null
     */
    public function childStarted(Session $child, RunContext $context): ?array
    {
        $rootRunId = $context->rootRunId();
        $key = $this->entryKey($context);
        $entry = $key === null ? null : $this->runs[$key];
        if ($entry === null) {
            return null;
        }
        if (! $entry['held']) {
            $budget = $entry['budget'];
            $settings = [
                'mode' => 'shared',
                'max_steps' => max(0, $budget->maxSteps - $entry['steps']),
                'max_cost_micros' => $budget->maxCostUsd === null ? null : PlanBudget::micros(max(0.0, $budget->maxCostUsd - $entry['cost'])),
            ];
            $now = time();
            $ttl = self::holdTtl();
            $this->write($child->planStore(), $entry['tree'], static function (?array $state) use ($rootRunId, $settings, $now, $ttl): array {
                $state ??= PlanBudget::open($rootRunId, $settings);

                return PlanBudget::reserve($state, $rootRunId, max(1, (int) $settings['max_steps']), $settings['max_cost_micros'], $now, $ttl);
            });
            $this->runs[$key]['held'] = true;
        }

        return $entry['tree'];
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
        $tree = $session->childTree();
        if ($tree === null || PlanBudget::route($this->contextKind($context), '', false, false, true, false)['tree'] !== 'continue') {
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
        unset($this->lineage[$runId]);
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
     * The child contexts the subagent runner built, held weakly. Only these
     * (and a context of a live tree here) may save a child's binding.
     *
     * @var WeakMap<RunContext, true>|null
     */
    private ?WeakMap $childContexts = null;

    /**
     * @internal Called by the subagent runner for the context it starts a child under.
     */
    public function startingChild(RunContext $context): RunContext
    {
        $this->childContexts ??= new WeakMap;
        $this->childContexts[$context] = true;

        return $context;
    }

    /**
     * Whether this is the runner's own context for a child started under a
     * run tree the HOST built (G-285): its run belongs to no durable tree,
     * so a cancel of it marks no tree the child session saved earlier.
     *
     * @internal
     */
    public function underHostTree(?RunContext $context): bool
    {
        return $context !== null && isset($this->childContexts[$context]) && $this->entryKey($context) === null;
    }

    /**
     * The live run of this runtime a context spends from: the root run its
     * ledger names, or else the run it was started from. A child resumed
     * under a context the host built continues its saved tree under its OWN
     * run id while its ledger is the host's, so the children it starts are
     * found through their parent run id (and, deeper down, through the run
     * that parent spends from). A ledger is never enough on its own: a host
     * may hand one context to several sessions.
     */
    private function entryKey(RunContext $context): ?string
    {
        if (isset($this->runs[$context->rootRunId()])) {
            return $context->rootRunId();
        }
        $parent = $context->parentRunId;
        if ($parent === null) {
            return null;
        }
        if (isset($this->runs[$parent]) && $this->runs[$parent]['ledger'] === $context->ledger) {
            return $parent;
        }

        return $this->lineage[$parent] ?? null;
    }

    /**
     * The kind of context a turn was handed, for {@see ChildBinding::route()}:
     * `runner` for the context the subagent runner built to start a child,
     * even under a run tree the host built. The same kind as {@see self::contextKind()},
     * so the binding and the budget routes read one context the same way.
     * A context the host builds and binds itself is never the runner's.
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
     * What kind of run context a turn was handed, for {@see PlanBudget::route()}:
     * `runner` when it is a context of a run this runtime is running (its
     * ledger is that run's), `host` for any other context that states a run
     * tree.
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
        // The context the subagent runner built to start a child is the
        // runner's, even under a run tree the host built: it spends from the
        // tree it was derived from and never from a tree its session saved.
        if (isset($this->childContexts[$context])) {
            return 'runner';
        }
        if ($context->metadataOnly) {
            return 'metadata';
        }
        $entry = $this->runs[$context->rootRunId()] ?? null;

        return $entry !== null && $entry['ledger'] === $context->ledger ? 'runner' : 'host';
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

    private static function holdTtl(): int
    {
        $ttl = config('prism-harness.agent.lock_ttl', 300);

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }
}
