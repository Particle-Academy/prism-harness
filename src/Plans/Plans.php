<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

use Closure;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Events\PlanUpdated;
use Prism\Harness\Exceptions\PlanRefused;
use Prism\Harness\Exceptions\PlansDisabled;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Tools\CanonicalJson;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use RuntimeException;
use Throwable;

/**
 * Agent plans (G-360): the store, the agent's tools, and the hooks the
 * runtime calls at the moments a plan has to look at the thread.
 *
 * OFF UNLESS BOTH SWITCHES ARE ON: `prism-harness.plans.enabled` and the
 * mode's `plans` flag. Off, no plan tool is offered, no context is added, no
 * plan step is emitted and Session::plan() throws PlansDisabled.
 *
 * A person approves a plan before it runs. That is on by default, and only
 * the host's config (`plans.require_approval`) turns it off: never a per-call
 * option, never anything the model can send.
 *
 * Bound as a singleton; config is read when it is used, so a host that
 * changes config at runtime is read correctly.
 */
final class Plans
{
    public const INDEX_KEY = 'prism-harness:plans:live';

    public const HEARTBEAT_KEY = 'prism-harness:plans:heartbeat';

    /** @var array<string, array<int, Closure(array<string, mixed>): void>> */
    private array $watchers = [];

    private int $watcherSeq = 0;

    /** @var (Closure(string, string): void)|null */
    private ?Closure $reapDispatcher = null;

    /** @var (Closure(): int)|null */
    private ?Closure $clock = null;

    public function __construct(private readonly ?PlanIds $ids = null) {}

    public function enabled(): bool
    {
        return config('prism-harness.plans.enabled', false) === true;
    }

    public function limits(): PlanLimits
    {
        $config = config('prism-harness.plans', []);

        return PlanLimits::fromConfig(is_array($config) ? $config : []);
    }

    /** Whether this session's current mode runs with plans. */
    public function enabledFor(Session $session, ?string $mode = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        try {
            return app(ModeRegistry::class)->forAgent($session->agent())->resolve($mode ?? $session->mode())->plans;
        } catch (Throwable) {
            return false;
        }
    }

    public function handle(Session $session): PlanHandle
    {
        if (! $this->enabledFor($session)) {
            throw PlansDisabled::forSession();
        }

        return new PlanHandle($session, $this);
    }

    public function ids(): PlanIds
    {
        return $this->ids ?? new PlanIds;
    }

    /** @internal Tests pin the clock. */
    public function useClock(?Closure $clock): void
    {
        $this->clock = $clock;
    }

    public function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    // ------------------------------------------------------------------
    // Store
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function load(Session $session): ?array
    {
        $payload = $session->planStore()->get(self::key($session->key()));
        $plan = $payload['plan'] ?? null;

        return is_array($plan) ? $plan : null;
    }

    /**
     * Write a plan, compare-and-set on its revision. Refused with
     * `plan.conflict` when the stored revision is not the one read.
     *
     * @param  array<string, mixed>  $plan
     */
    public function write(Session $session, array $plan, ?int $expectedRevision): void
    {
        $store = $session->planStore();
        $key = self::key($session->key());
        $store->withLock($key, function () use ($store, $key, $plan, $expectedRevision): void {
            $current = $store->get($key)['plan'] ?? null;
            $revision = is_array($current) ? ($current['revision'] ?? null) : null;
            if ($revision !== $expectedRevision && ! ($expectedRevision === null && is_array($current) && ! PlanMachine::isLive($current))) {
                throw PlanRefused::because('plan.conflict');
            }
            $store->put($key, ['plan' => $plan]);
        }, 10, 5);

        $this->indexed($session->key(), $plan);
        $this->announce($session, $plan);
    }

    /**
     * Load, transform, and write when the transform changed anything.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>|null
     */
    public function mutate(Session $session, Closure $change): ?array
    {
        $plan = $this->load($session);
        if ($plan === null) {
            return null;
        }
        $next = $change($plan);
        if ($next !== $plan) {
            $this->write($session, $next, $plan['revision']);
        }

        return $next;
    }

    /**
     * The plan brought in line with the thread, written when it moved.
     *
     * @return array<string, mixed>|null
     */
    public function reconciled(Session $session, bool $superseding = false): ?array
    {
        $plan = $this->load($session);
        if (! PlanMachine::isLive($plan)) {
            return $plan;
        }
        $thread = $session->thread();
        $pending = $superseding ? [] : $thread->pendingApprovalCalls();
        $decided = $thread->approvalDecisions();

        return $this->mutate($session, fn (array $plan): array => PlanMachine::reconcile($plan, $pending, $decided, $this->now(), $this->runId($session), $superseding));
    }

    private static function key(string $sessionKey): string
    {
        return $sessionKey.':plan';
    }

    /** @param array<string, mixed> $plan */
    private function indexed(string $sessionKey, array $plan): void
    {
        $store = $this->indexStore();
        if ($store === null) {
            return;
        }
        $store->withLock(self::INDEX_KEY, function () use ($store, $sessionKey, $plan): void {
            $index = $store->get(self::INDEX_KEY) ?? [];
            if (PlanMachine::isLive($plan)) {
                $index[$sessionKey] = $plan['id'];
            } else {
                unset($index[$sessionKey]);
            }
            $store->put(self::INDEX_KEY, $index);
        }, 10, 5);
    }

    private function indexStore(): ?SessionStore
    {
        try {
            return app(SessionStoreManager::class)->durable();
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Events and stream watchers
    // ------------------------------------------------------------------

    /**
     * Watch one session's plan writes, for a streamed run.
     *
     * @param  Closure(array<string, mixed>): void  $watcher
     * @return Closure(): void the unwatch
     */
    public function watch(string $sessionKey, Closure $watcher): Closure
    {
        $id = ++$this->watcherSeq;
        $this->watchers[$sessionKey][$id] = $watcher;

        return function () use ($sessionKey, $id): void {
            unset($this->watchers[$sessionKey][$id]);
        };
    }

    /** @param array<string, mixed> $plan */
    private function announce(Session $session, array $plan): void
    {
        foreach ($this->watchers[$session->key()] ?? [] as $watcher) {
            $watcher($plan);
        }
        try {
            event(new PlanUpdated(
                sessionKey: $session->key(),
                runId: $plan['lastRunId'] ?? '',
                planId: $plan['id'],
                revision: $plan['revision'],
                status: $plan['status'],
                holdingApprovalId: $plan['holdingApprovalId'],
                counts: PlanView::counts($plan),
                harnessAgent: $session->agent(),
            ));
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    // ------------------------------------------------------------------
    // Runtime hooks
    // ------------------------------------------------------------------

    /**
     * After the runtime records a turn: bind the approvals the turn staged,
     * and notice the ones a new message superseded.
     */
    public function afterTurn(Session $session): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $this->reconciled($session);
        } catch (Throwable $failure) {
            // The run is over either way; the next advance reconciles again.
            self::reportQuietly($failure);
        }
    }

    /** After decisions are recorded, before the run resumes. */
    public function decided(Session $session): void
    {
        if (! $this->enabled()) {
            return;
        }
        try {
            $this->reconciled($session);
        } catch (Throwable $failure) {
            // The decision is recorded; the plan reads it again at the end of
            // the run and on the next advance.
            self::reportQuietly($failure);
        }
    }

    /**
     * The plan context for the turn about to start. A turn with a new user
     * message ends every pending approval, so the plan is reconciled as if
     * nothing were pending before it is rendered.
     */
    public function context(Session $session, string $prompt): string
    {
        if (! $this->enabledFor($session)) {
            return '';
        }
        try {
            $plan = $this->reconciled($session, superseding: $prompt !== '');
        } catch (Throwable $failure) {
            self::reportQuietly($failure);

            return '';
        }

        return $plan === null ? '' : PlanView::context($plan, $this->limits());
    }

    /**
     * Report a failure by its class only. A store error's message can carry
     * the SQL and its bindings, which here would be plan text the model wrote,
     * and plan text never goes into a log.
     */
    private static function reportQuietly(Throwable $failure): void
    {
        report(new RuntimeException('A plan update failed: '.$failure::class.'.'));
    }

    /**
     * The plan tools for a run in this session, or none when plans are off.
     *
     * @return array<string, Tool>
     */
    public function tools(Session $session, ?string $mode): array
    {
        if (! $this->enabledFor($session, $mode)) {
            return [];
        }

        return (new PlanTools($this, $session))->all();
    }

    /** @internal */
    public function runId(Session $session): ?string
    {
        $run = $session->run();

        return is_array($run) && is_string($run['id'] ?? null) && ($run['status'] ?? null) === 'running' ? $run['id'] : null;
    }

    // ------------------------------------------------------------------
    // Reaper
    // ------------------------------------------------------------------

    /**
     * The callback the reaper hands a stalled plan to: normally "dispatch the
     * job that calls advance() for this session".
     *
     * @param  Closure(string $sessionKey, string $planId): void  $dispatch
     */
    public function reapUsing(Closure $dispatch): void
    {
        $this->reapDispatcher = $dispatch;
    }

    /** @return (Closure(string, string): void)|null */
    public function reapDispatcher(): ?Closure
    {
        return $this->reapDispatcher;
    }

    /** @return array{at: int}|null */
    public function heartbeat(): ?array
    {
        $beat = $this->indexStore()?->get(self::HEARTBEAT_KEY);

        return is_array($beat) && is_int($beat['at'] ?? null) ? ['at' => $beat['at']] : null;
    }

    /**
     * Find active plans whose head step has no live claim and that have not
     * moved for `reaper.stale_after` seconds, and hand each to $dispatch.
     * Writes the heartbeat the doctor checks. Returns how many it handed on.
     *
     * @param  Closure(string $sessionKey, string $planId): void  $dispatch
     */
    public function sweep(Closure $dispatch): int
    {
        $store = $this->indexStore();
        if ($store === null) {
            return 0;
        }
        $now = $this->now();
        $staleAfter = $this->limits()->staleAfter;
        $index = $store->get(self::INDEX_KEY) ?? [];
        $handed = 0;
        foreach ($index as $sessionKey => $planId) {
            $plan = $store->get(self::key((string) $sessionKey))['plan'] ?? null;
            if (! is_array($plan) || $plan['id'] !== $planId) {
                continue;
            }
            if (PlanMachine::stalled($plan, $now, $staleAfter)) {
                $dispatch((string) $sessionKey, (string) $planId);
                $handed++;
            }
        }
        $store->put(self::HEARTBEAT_KEY, ['at' => $now]);

        return $handed;
    }

    // ------------------------------------------------------------------

    /** @param array<array-key, mixed> $arguments */
    public static function digest(array $arguments): string
    {
        return CanonicalJson::digest($arguments);
    }

    /** @internal Schemas for the plan tools. */
    public static function stepSchema(bool $withId): ObjectSchema
    {
        $properties = [
            new StringSchema('title', 'A short title for the step, at most 160 characters, one line.', nullable: $withId),
            new StringSchema('instruction', 'What to do in this step, at most 600 characters, one line. Required for your own steps.', nullable: true),
            new EnumSchema('owner', 'agent for your own step; human for a question to the person.', ['agent', 'human'], nullable: $withId),
            new EnumSchema('interaction', 'input for a question to the person; omit for your own steps.', ['input'], nullable: true),
            new StringSchema('question', 'The question for the person, for a human step.', nullable: true),
            new ArraySchema('options', 'Suggested answers for a human step; free text is always allowed.', new StringSchema('option', 'An option'), nullable: true),
        ];
        if ($withId) {
            array_unshift($properties, new StringSchema('id', 'To keep an existing step unchanged, its id and nothing else.', nullable: true));
        }

        return new ObjectSchema('step', 'One step.', $properties, $withId ? [] : ['title', 'owner']);
    }
}
