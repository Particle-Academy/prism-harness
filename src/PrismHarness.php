<?php

declare(strict_types=1);

namespace Prism\Harness;

use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionStoreManager;

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
}
