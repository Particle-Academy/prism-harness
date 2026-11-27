<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use InvalidArgumentException;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Plans\PlanBudget;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Support\ClassLabel;
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
     * @var array<string, array{session: string, tree: array{session: string, run: string}, budget: RunBudget, ledger: RunLedger, steps: int, cost: float, unmetered: int, held: bool, held_by?: string}>
     */
    private array $runs = [];

    /**
     * Every run of this process, by run id, until it ends: the run tree it
     * belongs to, decided once when it began ({@see self::membership()}), the
     * session it runs on and the ledger it spends from. The one record that
     * the runner start's tree hold, the kept references, the depth and the budget route read;
     * a child the runner starts takes its parent's from here.
     *
     * The allowance the run was given when it began (G-387) is kept here
     * too, beside the ledger count it was given at, once it is known: what
     * the run writes on the row it parks on, and what a runner start's tree hold takes.
     *
     * @var array<string, array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route: string, session: string, ledger: RunLedger, allowance?: array{ceiling: int, used: int, own_left: int, tree_left: ?int, plan_left: ?int, allowance: int, acts_without_step: bool, used_at_park: int, left_at_park: int}, base?: int}>
     */
    private array $members = [];

    /**
     * The steps each run's tree record granted it when it began, by run id;
     * null when no durable tree record bounds the run.
     *
     * @var array<string, ?int>
     */
    private array $grants = [];

    /**
     * Holds on a child's delegation count, by run id, until the run ends
     * (G-387): the child session, the delegation, and the ledger with its
     * count when the hold was taken.
     *
     * @var array<string, array{session: string, delegation: string, ledger: RunLedger, steps: int}>
     */
    private array $delegationHolds = [];

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
     * `$steps` is what the run itself has left (its own allowance): the hold
     * asks for that and no more, in one locked write. A tree with no steps
     * left grants 0, not a refusal: the run acts on the decisions it resumes
     * with no model step (G-387), and so does a tree whose cost is spent:
     * acting on an approved call makes no provider call. Never a refusal.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route: string}  $membership
     *
     * @throws RunNotPermitted when the tree the child belongs to is cancelled
     */
    public function rootRun(Session $session, string $runId, RunContext $run, array $membership, ?int $steps = null): RunContext
    {
        $this->members[$runId] = [...$membership, 'session' => $session->key(), 'ledger' => $run->ledger];
        $this->grants[$runId] = null;
        $route = $membership['route'];
        if ($route !== 'open' && $route !== 'continue') {
            return $run;
        }
        $tree = $membership['tree'];
        if (! is_array($tree)) {
            return $run;
        }
        // A root resuming its parked run is in the tree that run began,
        // under a new run id or (on the stream path) the parked run's own.
        // When that run delegated, the tree has a record, and the resumed
        // root holds what it has left and is narrowed to it, as any other
        // turn continuing the tree: the tree's allowance holds across the
        // pause. A new root never finds one: its tree is named by its fresh
        // run id, and is written only once it delegates.
        // Two resumes of one parked root never run at once: a run starts
        // under the session lock, so the second sees the first's record.
        $resumedRoot = false;
        if ($route === 'open' && $this->recordOf($session, $tree) !== null) {
            $route = 'continue';
            $resumedRoot = true;
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
        $steps = max(0, $steps ?? ($budget->maxSteps - $entry['steps']));
        $grant = $this->write($session->planStore(), $tree, static function (?array $state) use ($runId, $steps, $turnCost, $now, $ttl): array {
            if ($state === null) {
                // The tree's record is gone: closed, never a fresh budget.
                return [null, ['steps' => 0, 'cost_micros' => null]];
            }

            return PlanBudget::grantSteps($state, $runId, $steps, $turnCost, $now, $ttl);
        });
        $this->grants[$runId] = (int) $grant['steps'];
        $narrowed = $run->withBudget(new RunBudget(
            maxSteps: $entry['steps'] + (int) $grant['steps'],
            maxCostUsd: $grant['cost_micros'] === null ? $budget->maxCostUsd : $entry['cost'] + $grant['cost_micros'] / 1_000_000,
            maxSeconds: $budget->maxSeconds,
            maxOutputTokensPerCall: $budget->maxOutputTokensPerCall,
            maxToolCalls: $budget->maxToolCalls,
        ));
        // A grant of 0 holds nothing, so there is nothing to settle.
        $this->runs[$runId] = [...$entry, 'budget' => $narrowed->budget, 'ledger' => $narrowed->ledger, 'held' => (int) $grant['steps'] > 0];

        return $narrowed;
    }

    /**
     * What the run's tree record granted it when it began; null when no
     * durable tree record bounds it (an open root with no record yet, the
     * host's tree, none).
     *
     * @internal Read by the runtime once, to compute the run's allowance.
     */
    public function treeGrant(string $runId): ?int
    {
        return $this->grants[$runId] ?? null;
    }

    /**
     * The run's allowance, computed once when it began (G-387), recorded
     * beside its membership with the ledger count it was given at. The run
     * was narrowed to exactly it; an open root's in-memory entry takes the
     * narrowed budget, so the hold it opens its tree's record with is it.
     *
     * @internal Called by the runtime once per run.
     *
     * @param  array{ceiling: int, used: int, own_left: int, tree_left: ?int, plan_left: ?int, allowance: int, acts_without_step: bool, used_at_park: int, left_at_park: int}  $allowance
     */
    public function recordAllowance(string $runId, RunContext $run, array $allowance, int $base): void
    {
        if (isset($this->members[$runId])) {
            $this->members[$runId]['allowance'] = $allowance;
            $this->members[$runId]['base'] = $base;
        }
        if (isset($this->runs[$runId])) {
            $this->runs[$runId]['budget'] = $run->budget;
            $this->runs[$runId]['ledger'] = $run->ledger;
        }
    }

    /**
     * What a run writes on the row it parks on (G-387): the steps it has
     * used, its children included (what its ledger gained since it began,
     * added to what its parked predecessor used), and what its allowance
     * has left. Null for a run with no recorded allowance.
     *
     * @internal
     *
     * @return array{harness_steps_used: int, harness_steps_left: int}|null
     */
    public function parkFacts(string $runId): ?array
    {
        $member = $this->members[$runId] ?? null;
        if ($member === null || ! isset($member['allowance'], $member['base'])) {
            return null;
        }
        $took = max(0, $member['ledger']->steps() - $member['base']);
        $taken = min($took, $member['allowance']['allowance']);

        return [
            'harness_steps_used' => $member['allowance']['used'] + $took,
            'harness_steps_left' => $member['allowance']['allowance'] - $taken,
        ];
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
     * The hold a runner start would take on its run tree (G-387): the run
     * it spends from (a root of this runtime) opens the tree's record the
     * first time it starts a child, holding its own allowance. Null when no
     * hold applies (a child in the host's tree or in none, or a run that
     * already holds).
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route?: string}  $membership
     * @return array{key: string, tree: array{session: string, run: string}, settings: array{mode: string, max_steps: int, max_cost_micros: ?int}, steps: int}|null
     */
    private function startHold(array $membership): ?array
    {
        $tree = $membership['tree'];
        if (! is_array($tree)) {
            return null;
        }
        $key = $membership['spends'];
        $entry = $key === null ? null : ($this->runs[$key] ?? null);
        if ($entry === null || $entry['held']) {
            return null;
        }
        $budget = $entry['budget'];
        // The spending run's allowance, recorded when it began (G-387).
        $allowance = $this->members[$key]['allowance']['allowance'] ?? max(0, $budget->maxSteps - $entry['steps']);

        return [
            'key' => (string) $key,
            // Named by the tree's root run; held under the run that spends
            // (a root resuming a parked run that never delegated opens its
            // tree's record here).
            'tree' => $entry['tree'],
            'settings' => [
                'mode' => 'shared',
                'max_steps' => (int) $allowance,
                'max_cost_micros' => $budget->maxCostUsd === null ? null : PlanBudget::micros(max(0.0, $budget->maxCostUsd - $entry['cost'])),
            ],
            'steps' => max(1, (int) $allowance),
        ];
    }

    /**
     * What the run tree's record WOULD grant a runner start now, read without
     * a write (G-387): a fact of the start gate. Null when no tree hold
     * applies; 0 when the record would refuse it.
     *
     * @internal Read by the runtime for the start gate.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route?: string}  $membership
     */
    public function startGrant(Session $child, array $membership): ?int
    {
        $hold = $this->startHold($membership);
        if ($hold === null) {
            return null;
        }
        $tree = $hold['tree'];
        $state = $this->recordOf($child, $tree) ?? PlanBudget::open($tree['run'], $hold['settings']);
        [, $grant] = PlanBudget::reserve($state, $hold['key'], $hold['steps'], $hold['settings']['max_cost_micros'], time(), self::holdTtl());

        return isset($grant['refused']) ? 0 : (int) $grant['steps'];
    }

    /**
     * Commit step (a) of a runner start (G-387): the tree hold the gate read,
     * in one conditional locked write. Refused (a sibling or another process
     * spent the tree since the read, so it would now grant less than the
     * gate saw), nothing is written and the start is refused. Returns the
     * release, called when a later commit step fails: it returns the hold
     * (and the record, when this hold opened it) exactly once, and only
     * while the hold is still the one this start took: a release by any
     * other start (a re-entrant one, say) is a no-op.
     *
     * @internal Called by the runtime once the start gate let a start through.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route?: string}  $membership
     * @return Closure(): void
     *
     * @throws RunNotPermitted when the tree no longer grants what the gate read
     */
    public function holdForStart(Session $child, array $membership, ?int $expected, string $by): Closure
    {
        $hold = $this->startHold($membership);
        if ($hold === null) {
            if ($expected !== null) {
                // The gate saw a hold to take; there is none now.
                throw RunNotPermitted::exhausted('steps');
            }

            return static function (): void {};
        }
        $now = time();
        $ttl = self::holdTtl();
        $opened = false;
        $grant = $this->write($child->planStore(), $hold['tree'], static function (?array $state) use ($hold, $expected, $now, $ttl, &$opened): array {
            $opened = $state === null;
            [$next, $grant] = PlanBudget::reserve($state ?? PlanBudget::open($hold['tree']['run'], $hold['settings']),
                $hold['key'], $hold['steps'], $hold['settings']['max_cost_micros'], $now, $ttl);
            // Less than the gate read is a race lost: nothing is written.
            if (isset($grant['refused']) || ($expected !== null && (int) $grant['steps'] < $expected)) {
                return [null, ['refused' => true, 'limit' => (string) ($grant['limit'] ?? 'steps')]];
            }

            return [$next, $grant];
        });
        if (is_array($grant) && isset($grant['refused'])) {
            throw RunNotPermitted::exhausted((string) $grant['limit']);
        }
        $key = $hold['key'];
        $this->runs[$key]['held'] = true;
        // Which start took it: only that start may release it.
        $this->runs[$key]['held_by'] = $by;
        $released = false;

        return function () use ($child, $hold, $key, $opened, $by, &$released): void {
            if ($released || ($this->runs[$key]['held_by'] ?? null) !== $by) {
                return;
            }
            $released = true;
            $this->runs[$key]['held'] = false;
            unset($this->runs[$key]['held_by']);
            $store = $child->planStore();
            $recordKey = self::key($hold['tree']);
            try {
                $store->withLock($recordKey, function () use ($store, $recordKey, $hold, $key, $opened): void {
                    $state = $store->get($recordKey);
                    if (! is_array($state) || ($state['planId'] ?? null) !== $hold['tree']['run'] || ! isset($state['holds'][$key])) {
                        return;
                    }
                    unset($state['holds'][$key]);
                    if ($opened && $state['holds'] === [] && (int) $state['steps'] === 0 && (int) $state['unmetered'] === 0) {
                        $store->forget($recordKey);

                        return;
                    }
                    $store->put($recordKey, $state);
                }, 10, 5);
            } catch (Throwable $failure) {
                // The hold stays and is charged in full once it expires.
                report(new RuntimeException('A run tree hold release failed: '.ClassLabel::of($failure).'.'));
            }
        };
    }

    /**
     * Refuse, before anything is recorded, a decision the run it resumes
     * could not act on at all: recorded, it would be consumed by a resume
     * the tree then refuses, and the approved call would never run.
     *
     * A spent tree, its steps or its cost, never refuses a decision
     * (G-387): acting on it makes no provider call, and the resume acts on
     * it with an allowance of 0. Only a cancelled tree refuses.
     *
     * @internal Called by the session before it records decisions.
     *
     * @throws RunNotPermitted
     */
    public function assertDecisionsFunded(Session $session, ?RunContext $context): void
    {
        // The turn the decision resumes, as it would begin.
        $membership = $this->begin($session, 'decision', $context, '');
        $tree = $membership['tree'];
        if (! is_array($tree)) {
            return;
        }
        $state = $this->recordOf($session, $tree);
        if ($membership['route'] === 'open') {
            // A root resuming its parked run is held to its tree's record
            // only when the parked run delegated and opened one.
            if ($tree['run'] === 'decision' || $state === null) {
                return;
            }
            // A root acts on its decisions even when the tree is spent:
            // resolving them takes no model step (see rootRun()). A decision
            // is refused only when the run cannot act on it at all.
            if ($this->treeCancelled($session, $tree)) {
                throw RunNotPermitted::cancelled();
            }

            return;
        } elseif ($membership['route'] !== 'continue') {
            return;
        }
        if ($this->treeCancelled($session, $tree)) {
            throw RunNotPermitted::cancelled();
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
        unset($this->members[$runId], $this->grants[$runId]);
        $this->settleDelegation($session, $runId);
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
            report(new RuntimeException('A run tree budget update failed: '.ClassLabel::of($failure).'.'));
        }
    }

    /**
     * Commit step (b) of a runner start (G-387): the child's records in ONE
     * locked write. A new delegation, whose count of the steps the child
     * uses starts at 0 under the ceiling the runner gave it, and `$save`
     * writes the binding, depth, refs, ceiling and delegation id beside it.
     * Every turn on the child the runner does not start spends from that
     * count, so the ceiling is the delegation's lifetime budget.
     *
     * The busy check is made again under the lock: a turn on the child that
     * claimed its count since the gate read it (live, not past the lock TTL)
     * keeps it, and the start is refused with nothing written. So is the
     * pending check (`$recheck`, which throws to refuse): an approval a
     * person can answer that was parked since the gate read it is never
     * dismissed by the start. When `$save` fails, the count is put back as it
     * was, so the binding and the count never name two delegations.
     *
     * `$save` writes the child's records and returns their restore. Returns
     * null when refused as busy; otherwise the undo, for a start that fails
     * before its task row is recorded: in one locked write, the child's
     * records and its count go back to what they were before this commit,
     * each only while it is still what this commit wrote (a later writer is
     * never clobbered). Called once; a failure to undo is logged by class.
     *
     * @internal Called by the runtime once the tree hold is taken.
     *
     * @param  Closure(): (Closure(): void)  $save
     * @param  (Closure(): void)|null  $recheck
     * @return (Closure(): void)|null
     */
    public function commitChild(Session $child, string $delegation, int $ceiling, Closure $save, ?Closure $recheck = null): ?Closure
    {
        $key = self::delegationKey($child);
        $store = $child->planStore();

        $state = null;
        $restore = $store->withLock($key, function () use ($child, $store, $key, $delegation, $ceiling, $save, $recheck, &$state): ?Closure {
            // A record this runtime could not have written is replaced.
            $state = $store->get($key);
            $current = $child->childDelegation();
            if ($current !== null && self::validCount($state, $current)
                && PlanBudget::expire($state, time(), self::holdTtl())['holds'] !== []) {
                return null;
            }
            if ($recheck !== null) {
                $recheck();
            }
            $store->put($key, PlanBudget::open($delegation, ['mode' => 'shared', 'max_steps' => max(0, $ceiling), 'max_cost_micros' => null]));
            try {
                return $save();
            } catch (Throwable $failure) {
                if ($state === null) {
                    $store->forget($key);
                } else {
                    $store->put($key, $state);
                }

                throw $failure;
            }
        }, 10, 5);
        if ($restore === null) {
            return null;
        }
        $prior = $state;
        $undone = false;

        return static function () use ($child, $store, $key, $delegation, $restore, $prior, &$undone): void {
            if ($undone) {
                return;
            }
            $undone = true;
            try {
                $store->withLock($key, static function () use ($child, $store, $key, $delegation, $restore, $prior): void {
                    // Read as stored: this commit's record is provisional,
                    // and a reader sees it as absent.
                    $record = $child->childRecord()['child_binding'] ?? null;
                    if (is_array($record) && ($record['delegation'] ?? null) === $delegation) {
                        $restore();
                    }
                    $count = $store->get($key);
                    if (is_array($count) && ($count['planId'] ?? null) === $delegation) {
                        if ($prior === null) {
                            $store->forget($key);
                        } else {
                            $store->put($key, $prior);
                        }
                    }
                }, 10, 5);
            } catch (Throwable $failure) {
                report(new RuntimeException('A subagent start could not be undone: '.ClassLabel::of($failure).'.'));
            }
        };
    }

    /**
     * Claim this run's steps from its child's delegation count, in ONE locked
     * write (G-387). `$allow` is given the count the delegation has already
     * used, live holds of other runs included, and returns the steps this run
     * may take; that many are held under the run id, and settled with what
     * the run took when it ends. A hold whose run never settles is charged
     * in full once the run lock's TTL has passed. Returns the count used;
     * null when the session was started before delegations were counted;
     * -1 when it names a delegation whose record is gone or is another's,
     * which the caller fails closed on.
     *
     * @internal
     *
     * A runner start names the delegation its commit opened (`$started`):
     * its record is provisional until the start's task row exists, and the
     * start's own run claims from it before that row is written (G-387).
     *
     * @param  Closure(int): int  $allow
     */
    public function claimDelegation(Session $child, string $runId, RunLedger $ledger, Closure $allow, ?string $started = null): ?int
    {
        $delegation = $started ?? $child->childDelegation();
        if ($delegation === null) {
            // A ceiling and its delegation id are always saved together: a
            // ceiling without a valid id was not written by this runtime.
            return $child->childStepCeiling() === null ? null : -1;
        }
        $key = self::delegationKey($child);
        $store = $child->planStore();
        $now = time();
        $ttl = self::holdTtl();
        $used = $store->withLock($key, function () use ($store, $key, $delegation, $runId, $allow, $now, $ttl): int {
            $state = $store->get($key);
            // Anything this runtime could not have written, and a second
            // claim by a run that already holds, FAIL CLOSED.
            if (! self::validCount($state, $delegation) || isset($state['holds'][$runId]) || isset($state['expired'][$runId])) {
                return -1;
            }
            $state = PlanBudget::expire($state, $now, $ttl, remember: true);
            $used = (int) $state['steps'];
            foreach ($state['holds'] as $hold) {
                $used += (int) $hold['steps'];
            }
            $steps = max(0, $allow($used));
            if ($steps > 0) {
                $state['holds'][$runId] = ['steps' => $steps, 'cost_micros' => 0, 'at' => $now];
                ksort($state['holds'], SORT_STRING);
            }
            $store->put($key, $state);

            return $used;
        }, 10, 5);
        if ($used >= 0) {
            $this->delegationHolds[$runId] = ['session' => $child->key(), 'delegation' => $delegation, 'ledger' => $ledger, 'steps' => $ledger->steps()];
        }

        return $used;
    }

    /** Replace a run's hold on its child's delegation count with what it took. */
    private function settleDelegation(Session $session, string $runId): void
    {
        $hold = $this->delegationHolds[$runId] ?? null;
        if ($hold === null || $hold['session'] !== $session->key()) {
            return;
        }
        unset($this->delegationHolds[$runId]);
        $steps = max(0, $hold['ledger']->steps() - $hold['steps']);
        $key = self::delegationKey($session);
        $store = $session->planStore();
        try {
            $store->withLock($key, function () use ($store, $key, $hold, $runId, $steps): void {
                $state = $store->get($key);
                // A record this runtime could not have written is left as
                // it is: never repaired into a permissive one.
                if (! self::validCount($state, $hold['delegation'])) {
                    return;
                }
                // The run's own charge, live or already charged in full on
                // expiry, is replaced with what it took: counted once. A run
                // that held nothing is still counted.
                if (isset($state['holds'][$runId]) || isset($state['expired'][$runId])) {
                    $state = PlanBudget::settleRun($state, $runId, $steps, 0);
                } else {
                    $state['steps'] += $steps;
                }
                $store->put($key, $state);
            }, 10, 5);
        } catch (Throwable $failure) {
            // The hold stays and is charged in full once it expires.
            report(new RuntimeException('A delegation count update failed: '.ClassLabel::of($failure).'.'));
        }
    }

    /**
     * Whether a turn on this child holds its delegation count right now (a
     * live hold, not yet past the lock TTL). The runner does not start the
     * child again over it: the reset would wipe the running turn's hold.
     *
     * @internal
     */
    public function delegationBusy(Session $child): bool
    {
        $state = $child->planStore()->get(self::delegationKey($child));
        $delegation = $child->childDelegation();
        if ($delegation === null || ! self::validCount($state, $delegation)) {
            return false;
        }

        return PlanBudget::expire($state, time(), self::holdTtl())['holds'] !== [];
    }

    /**
     * A delegation count as this runtime writes it: its own id, and every
     * count a safe non-negative integer.
     *
     * @phpstan-assert-if-true array<string, mixed> $state
     */
    private static function validCount(mixed $state, string $delegation): bool
    {
        if (! is_array($state) || ($state['planId'] ?? null) !== $delegation
            || ! RunBudgets::validUsed($state['steps'] ?? null) || ! RunBudgets::validUsed($state['max_steps'] ?? null)
            || ! RunBudgets::validUsed($state['cost_micros'] ?? null) || ! is_array($state['holds'] ?? null)) {
            return false;
        }
        foreach ($state['holds'] as $hold) {
            if (! is_array($hold) || ! RunBudgets::validUsed($hold['steps'] ?? null) || ! RunBudgets::validUsed($hold['at'] ?? null)
                || ! RunBudgets::validUsed($hold['cost_micros'] ?? null)) {
                return false;
            }
        }
        foreach (is_array($state['expired'] ?? []) ? ($state['expired'] ?? []) : [null] as $charged) {
            if (! is_array($charged) || ! RunBudgets::validUsed($charged['steps'] ?? null) || ! RunBudgets::validUsed($charged['cost_micros'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function delegationKey(Session $child): string
    {
        return $child->key().':delegation-steps';
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

    /**
     * The tree's budget record, or null when it has none.
     *
     * @param  array{session: string, run: string}  $tree
     * @return array<string, mixed>|null
     */
    private function recordOf(Session $session, array $tree): ?array
    {
        $state = $session->planStore()->get(self::key($tree));

        return is_array($state) && ($state['planId'] ?? null) === $tree['run'] ? $state : null;
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
