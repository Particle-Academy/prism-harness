<?php

declare(strict_types=1);

namespace Prism\Harness\Sessions;

use Closure;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Enums\Durability;

/** Records logical resources before their first write; reads never allocate an index entry. */
final readonly class IndexedSessionStore implements SessionStore
{
    public function __construct(
        private SessionStore $inner,
        private DatabaseAddressIndex $index,
        private SessionAddress $address,
        private string $slot,
        private string $kind = 'state',
    ) {}

    public function forTasks(): self
    {
        return new self($this->inner, $this->index, $this->address, $this->slot, 'tasks');
    }

    public function get(string $key): ?array
    {
        return $this->inner->get($key);
    }

    public function put(string $key, array $payload, ?int $ttlSeconds = null): void
    {
        $this->index->resource($this->address, $this->slot, $this->kind, $key);
        $this->inner->put($key, $payload, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        $this->inner->forget($key);
    }

    public function withLock(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        $this->index->resource($this->address, $this->slot, 'lock', $key);

        return $this->inner->withLock($key, $callback, $ttlSeconds, $waitSeconds);
    }

    public function durability(): Durability
    {
        return $this->inner->durability();
    }
}
