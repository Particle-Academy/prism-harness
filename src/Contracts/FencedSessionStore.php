<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Closure;

/** Optional runtime capability; ordinary SessionStore::withLock is unchanged. */
interface FencedSessionStore extends SessionStore
{
    /**
     * @template TReturn
     *
     * @param  Closure(Closure(): void, string): TReturn  $callback
     * @return TReturn
     */
    public function withFencedLock(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed;

    /**
     * Atomically write this session's ephemeral state only while its token owns
     * an unexpired lease. Expiry uses the backend clock. False writes nothing;
     * storage/encoding errors remain exceptions rather than ownership failures.
     *
     * @param  array<string, mixed>  $payload
     */
    public function putIfOwned(string $sessionKey, string $token, array $payload, ?int $ttlSeconds = null): bool;
}
