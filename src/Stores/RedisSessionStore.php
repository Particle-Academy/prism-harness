<?php

declare(strict_types=1);

namespace Prism\Harness\Stores;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Enums\Durability;
use Prism\Harness\Exceptions\SessionLocked;

/**
 * Session state in Redis.
 *
 * Reports itself Volatile unless the application asserts otherwise. Redis can
 * absolutely be durable — with AOF or RDB it is — but the `redis` connection in
 * a typical Laravel app is a cache that something is entitled to flush. The
 * package cannot tell the two apart from inside, and the cost of assuming wrong
 * is a dropped tool approval, so the safe answer is the default and the
 * operator opts out of it.
 */
class RedisSessionStore implements SessionStore
{
    public function __construct(
        protected readonly RedisFactory $redis,
        protected readonly string $connection = 'default',
        protected readonly string $prefix = 'harness:',
        protected readonly bool $durable = false,
    ) {}

    #[\Override]
    public function get(string $key): ?array
    {
        $raw = $this->connection()->get($this->prefix.$key);

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    #[\Override]
    public function put(string $key, array $payload, ?int $ttlSeconds = null): void
    {
        $encoded = json_encode($payload);

        if ($ttlSeconds === null) {
            $this->connection()->set($this->prefix.$key, $encoded);

            return;
        }

        $this->connection()->setex($this->prefix.$key, $ttlSeconds, $encoded);
    }

    #[\Override]
    public function forget(string $key): void
    {
        $this->connection()->del($this->prefix.$key);
    }

    #[\Override]
    public function withLock(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        $lockKey = $this->prefix.'lock:'.$key;

        // A random owner token, checked on release: without it a slow worker
        // whose lock had already expired would delete the lock a *different*
        // worker now holds.
        $token = (string) Str::uuid();

        $deadline = microtime(true) + $waitSeconds;

        while (! $this->acquire($lockKey, $token, $ttlSeconds)) {
            if (microtime(true) >= $deadline) {
                throw SessionLocked::forKey($key, $waitSeconds);
            }

            usleep(100_000);
        }

        try {
            return $callback();
        } finally {
            $this->release($lockKey, $token);
        }
    }

    #[\Override]
    public function durability(): Durability
    {
        return $this->durable ? Durability::Durable : Durability::Volatile;
    }

    protected function acquire(string $lockKey, string $token, int $ttlSeconds): bool
    {
        // SET NX EX is atomic; a separate exists-then-set would race.
        return (bool) $this->connection()->set($lockKey, $token, 'EX', $ttlSeconds, 'NX');
    }

    protected function release(string $lockKey, string $token): void
    {
        // Compare-and-delete in one script, so the check and the delete cannot
        // be interleaved by another worker acquiring between them.
        $this->connection()->eval(
            'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end',
            1,
            $lockKey,
            $token,
        );
    }

    protected function connection(): mixed
    {
        return $this->redis->connection($this->connection);
    }
}
