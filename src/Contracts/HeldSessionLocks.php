<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Exceptions\SessionLocked;

/**
 * A fenced session lock that can be held ACROSS YIELDS.
 *
 * `withFencedLock()` holds its lock for the length of a callback, and a
 * streamed run cannot live inside a callback: a closure cannot yield to the
 * code iterating the stream. This is the same lock, with the same UUID token
 * and the same `putIfOwned()` fencing, split into its two halves so a
 * generator can take it on its first iteration and give it back in a
 * `finally` — which PHP also runs when the generator is destroyed early.
 *
 * Streaming agent runs require it of the ephemeral store; it is separate from
 * {@see FencedSessionStore} so a custom store written against that contract
 * keeps compiling, and is refused for streaming rather than broken.
 */
interface HeldSessionLocks extends FencedSessionStore
{
    /**
     * Acquire the session's fenced lock and return its fence token.
     *
     * @throws SessionLocked when it cannot be acquired within `$waitSeconds`
     */
    public function acquireFencedLock(string $key, int $ttlSeconds, int $waitSeconds): string;

    /**
     * Release the lock, only if `$token` still owns it. A lock that has expired
     * and been taken by another holder is left alone.
     */
    public function releaseFencedLock(string $key, string $token): void;
}
