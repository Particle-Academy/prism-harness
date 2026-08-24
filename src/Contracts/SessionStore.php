<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Closure;
use Prism\Harness\Enums\Durability;
use Prism\Harness\Exceptions\SessionLocked;

/**
 * Where session state lives between requests.
 *
 * A Laravel request boots, serves and dies, so a session cannot be an object
 * held in memory the way Mastra's is — it has to be reconstructed from a store
 * on every request. This is that store.
 *
 * Implementations declare their own {@see Durability} rather than having it
 * inferred. Only the application knows whether its Redis is persistent or a
 * disposable cache, and the difference decides whether losing the contents is
 * a shrug or a lost agent action.
 */
interface SessionStore
{
    /**
     * @return array<string, mixed>|null Null when nothing is stored.
     */
    public function get(string $key): ?array;

    /**
     * @param  array<string, mixed>  $payload
     * @param  int|null  $ttlSeconds  Null keeps it until removed.
     */
    public function put(string $key, array $payload, ?int $ttlSeconds = null): void;

    public function forget(string $key): void;

    /**
     * Run the callback while holding an exclusive lock on the key.
     *
     * Two workers can resolve the same session at the same moment — a queued
     * job finishing a run while the user sends another message is ordinary, not
     * exotic. Whatever must not happen twice goes in here.
     *
     * Returns the callback's value. Throws {@see SessionLocked}
     * if the lock cannot be acquired within $waitSeconds, rather than running
     * the callback anyway.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withLock(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed;

    /**
     * Whether this store's contents survive a deploy.
     *
     * Read by the manager at boot: a store that reports Volatile is refused for
     * durable state instead of silently accepting it.
     */
    public function durability(): Durability;
}
