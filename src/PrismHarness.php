<?php

declare(strict_types=1);

namespace Prism\Harness;

use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Contracts\AgentTaskSource;
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
            taskLeaseSeconds: $this->taskSetting('lease_seconds', AgentTaskSource::DEFAULT_LEASE_SECONDS),
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
            leaseSeconds: $this->taskSetting('lease_seconds', AgentTaskSource::DEFAULT_LEASE_SECONDS),
            lockWaitSeconds: $this->taskSetting('lock_wait', 5),
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

    protected function taskSetting(string $key, int $default): int
    {
        $tasks = $this->config['tasks'] ?? [];
        $value = is_array($tasks) ? ($tasks[$key] ?? null) : null;

        return is_numeric($value) ? (int) $value : $default;
    }
}
