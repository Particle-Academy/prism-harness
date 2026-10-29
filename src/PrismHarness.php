<?php

declare(strict_types=1);

namespace Prism\Harness;

use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Contracts\AgentTaskSource;
use Prism\Harness\Exceptions\InvalidTaskLease;
use Prism\Harness\Models\Thread;
use Prism\Harness\Participants\ParticipantAdapters;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\Sessions\DatabaseAddressIndex;
use Prism\Harness\Sessions\IndexedSessionStore;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Tasks\StoreTaskSource;

/**
 * The entry point: resolve a session for a participant.
 *
 *     $session = PrismHarness::for($user)->session('support');
 *
 * Bound as a singleton, but the sessions it hands out are not cached — each
 * call reconstructs one from the store. That is the point: a session you can
 * hold across requests is a session that goes stale the moment another worker
 * touches it.
 */
class PrismHarness
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly SessionStoreManager $stores,
        protected readonly AgentRuntime $runtime,
        protected readonly array $config = [],
        protected readonly ?string $agent = null,
        protected readonly ?ParticipantAdapters $participants = null,
    ) {}

    public function forAgent(?string $agent): self
    {
        SessionAddress::validateAgent($agent);
        $agents = $this->config['agents'] ?? [];
        $options = $agent === null ? [] : (is_array($agents) ? ($agents[$agent] ?? []) : null);
        if (! is_array($options)) {
            throw new \InvalidArgumentException('Agent settings must be a map.');
        }
        if (array_key_exists('ephemeral_ttl', $options) && $options['ephemeral_ttl'] !== null && (! is_int($options['ephemeral_ttl']) || $options['ephemeral_ttl'] < 0)) {
            throw new \InvalidArgumentException('Agent ephemeral TTL must be nonnegative whole seconds or null.');
        }
        foreach (['default_scope', 'default_task_list'] as $key) {
            if (array_key_exists($key, $options) && (! is_string($options[$key]) || ($key === 'default_task_list' && $options[$key] === ''))) {
                throw new \InvalidArgumentException('Agent scope/list defaults must be valid strings.');
            }
        }

        return new self($this->stores, $this->runtime, $this->config, $agent, $this->participants);
    }

    public function for(Model|ParticipantRef $participant): PendingSession
    {
        return new PendingSession($this, $participant);
    }

    public function session(Model|ParticipantRef $participant, ?string $scope = null, ?object $actor = null): Session
    {
        return new Session(
            participant: $participant,
            scope: $scope ?? $this->defaultScope(),
            ephemeral: $this->stores->ephemeral(),
            durable: $this->stores->durable(),
            ttlSeconds: $this->ephemeralTtl(),
            runtime: $this->runtime,
            taskLeaseSeconds: $this->taskLease(),
            agent: $this->agent,
            actor: $participant instanceof ParticipantRef
                ? ($this->participants ?? throw new \LogicException('Participant adapters are not configured.'))->actor($participant, $actor)
                : ($actor ?? $participant),
        );
    }

    /**
     * A durable task list that is NOT tied to one session.
     *
     *     $tasks = PrismHarness::tasks('nightly-reconciliation');
     *
     * A list belonging to a conversation is reached through
     * {@see Session::tasks()} instead, which addresses it beside that session's
     * own state. This is the other case: a list several sessions share, or one
     * that outlives all of them.
     *
     * ALWAYS ON THE DURABLE SLOT, never the ephemeral one, and not
     * configurable. The list is the record of what is left to do: losing it is
     * a correctness failure rather than a degradation to a default, because a
     * list that vanished reads exactly like a list that was finished. The
     * durable slot already refuses a volatile store, and
     * {@see StoreTaskSource} refuses one again on its own account — the second
     * check is what protects a source built by hand in an application.
     */
    public function tasks(?string $list = null): StoreTaskSource
    {
        $list ??= $this->defaultTaskList();
        $address = new SessionAddress(null, null, 'task-list:'.$list, $this->agent);
        $store = $this->stores->durable();
        if ($this->agent !== null) {
            $store = new IndexedSessionStore(
                $store, DatabaseAddressIndex::configured((new Thread)->getConnection()), $address, 'durable', 'tasks',
            );
        }

        return new StoreTaskSource(
            store: $store,
            list: $this->agent === null ? $list : 'agent-list:'.$address->indexKey(),
            leaseSeconds: $this->taskLease(),
            lockWaitSeconds: $this->taskLockWait(),
        );
    }

    public function stores(): SessionStoreManager
    {
        return $this->stores;
    }

    /** @return list<array{name: string, owner: string, source: string}> */
    public function effectiveTools(string $mode, Model|ParticipantRef $participant, ?string $scope = null): array
    {
        return $this->runtime->effectiveTools($this->for($participant)->session($scope), $mode);
    }

    public function defaultScope(): string
    {
        $scope = $this->scalarConfig('default_scope');

        return is_string($scope) ? $scope : 'default';
    }

    protected function ephemeralTtl(): ?int
    {
        $ttl = $this->scalarConfig('ephemeral_ttl');

        return is_int($ttl) ? $ttl : null;
    }

    private function defaultTaskList(): string
    {
        $list = $this->scalarConfig('default_task_list') ?? 'default';
        if (! is_string($list) || $list === '') {
            throw new \InvalidArgumentException('The default task list must be a nonempty string.');
        }

        return $list;
    }

    private function scalarConfig(string $key): mixed
    {
        $agents = $this->config['agents'] ?? [];
        $options = $this->agent === null ? [] : (is_array($agents) ? ($agents[$this->agent] ?? []) : []);

        return is_array($options) && array_key_exists($key, $options) ? $options[$key] : ($this->config[$key] ?? null);
    }

    /**
     * The configured lease, refused rather than rounded.
     *
     * PHP's own signature would have caught a float passed to `claim()` — the
     * parameter is `?int` under strict types — but NOT this path: a config
     * value arrives as a string or a float and `(int) '90.4'` is `90`, quietly.
     * That is the same shape as clamping a lease of zero to one, one scale
     * down, and it is refused for the same reason: the lease you get is not the
     * lease you wrote, and nothing says so.
     *
     * The comparison is against the value's own truncation rather than
     * `is_int()`, because `'300'` out of an environment variable is a whole
     * number of seconds written the only way an env file can write one.
     */
    protected function taskLease(): int
    {
        $value = $this->taskConfig('lease_seconds');

        if (! is_numeric($value)) {
            return AgentTaskSource::DEFAULT_LEASE_SECONDS;
        }

        if ((float) $value !== (float) (int) $value) {
            throw InvalidTaskLease::notWholeSeconds('prism-harness.tasks.lease_seconds', (string) $value);
        }

        return (int) $value;
    }

    /**
     * How long to wait for another worker's claim before giving up.
     *
     * Deliberately NOT held to the lease's strict rule. This is a local wait
     * bound, not part of the contract the three languages share, and it never
     * reaches a stored record — a fractional value truncating here changes how
     * long one call blocks and nothing else. The lease decides who holds a
     * task; this decides how patient you are about finding out.
     */
    protected function taskLockWait(): int
    {
        $value = $this->taskConfig('lock_wait');

        return is_numeric($value) ? (int) $value : 5;
    }

    protected function taskConfig(string $key): mixed
    {
        $tasks = $this->config['tasks'] ?? [];

        return is_array($tasks) ? ($tasks[$key] ?? null) : null;
    }
}
