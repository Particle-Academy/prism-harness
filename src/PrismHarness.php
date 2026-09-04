<?php

declare(strict_types=1);

namespace Prism\Harness;

use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Contracts\AgentTaskSource;
use Prism\Harness\Exceptions\InvalidTaskLease;
use Prism\Harness\Sessions\Session;
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
    ) {}

    public function for(Model $participant): PendingSession
    {
        return new PendingSession($this, $participant);
    }

    public function session(Model $participant, ?string $scope = null): Session
    {
        return new Session(
            participant: $participant,
            scope: $scope ?? $this->defaultScope(),
            ephemeral: $this->stores->ephemeral(),
            durable: $this->stores->durable(),
            ttlSeconds: $this->ephemeralTtl(),
            runtime: $this->runtime,
            taskLeaseSeconds: $this->taskLease(),
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
    public function tasks(string $list = 'default'): StoreTaskSource
    {
        return new StoreTaskSource(
            store: $this->stores->durable(),
            list: $list,
            leaseSeconds: $this->taskLease(),
            lockWaitSeconds: $this->taskLockWait(),
        );
    }

    public function stores(): SessionStoreManager
    {
        return $this->stores;
    }

    public function defaultScope(): string
    {
        $scope = $this->config['default_scope'] ?? 'default';

        return is_string($scope) ? $scope : 'default';
    }

    protected function ephemeralTtl(): ?int
    {
        $ttl = $this->config['ephemeral_ttl'] ?? null;

        return is_int($ttl) ? $ttl : null;
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
