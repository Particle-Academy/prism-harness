<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Enums\Durability;

/**
 * A store that lets a test run a second worker AT THE ONE MOMENT THAT MATTERS —
 * after the list has been read and before it is written back.
 *
 * PHP tests have no threads, so "two workers claim at once" cannot be produced
 * by running two claims and hoping. This produces the interleaving
 * deterministically instead: the hook fires inside the first worker's read, so
 * the second worker runs while the first is mid-claim, which is exactly the
 * window a read-then-mark implementation loses a task in.
 *
 * `locking: false` removes the lock and NOTHING ELSE. That is the negative
 * control the concurrency test needs: without it, a test asserting "two workers
 * never get the same task" is indistinguishable from a test that never managed
 * to interleave them at all.
 */
final class InterleavingStore implements SessionStore
{
    private ?Closure $onRead = null;

    public function __construct(
        private readonly SessionStore $inner,
        private readonly bool $locking = true,
    ) {}

    /**
     * Run $callback inside the next read, once.
     */
    public function duringNextRead(Closure $callback): void
    {
        $this->onRead = $callback;
    }

    #[\Override]
    public function get(string $key): ?array
    {
        $value = $this->inner->get($key);

        if ($this->onRead instanceof Closure) {
            // Cleared BEFORE calling, so the interloper's own reads do not
            // re-enter the hook.
            $callback = $this->onRead;
            $this->onRead = null;
            $callback();
        }

        return $value;
    }

    #[\Override]
    public function put(string $key, array $payload, ?int $ttlSeconds = null): void
    {
        $this->inner->put($key, $payload, $ttlSeconds);
    }

    #[\Override]
    public function forget(string $key): void
    {
        $this->inner->forget($key);
    }

    #[\Override]
    public function withLock(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        if (! $this->locking) {
            return $callback();
        }

        return $this->inner->withLock($key, $callback, $ttlSeconds, $waitSeconds);
    }

    #[\Override]
    public function durability(): Durability
    {
        return $this->inner->durability();
    }
}
