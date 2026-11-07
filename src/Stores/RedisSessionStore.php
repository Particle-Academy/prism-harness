<?php

declare(strict_types=1);

namespace Prism\Harness\Stores;

use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;
use Prism\Harness\Contracts\FencedSessionStore;
use Prism\Harness\Enums\Durability;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\SessionLockLost;

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
class RedisSessionStore implements FencedSessionStore
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
    public function withFencedLock(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        $this->assertPositiveLease($ttlSeconds);
        $lockKey = $this->prefix.'lock:'.$key;
        $token = (string) Str::uuid();
        $deadline = microtime(true) + $waitSeconds;
        while (! $this->acquire($lockKey, $token, $ttlSeconds)) {
            if (microtime(true) >= $deadline) {
                throw SessionLocked::forKey($key, $waitSeconds);
            }
            usleep(100_000);
        }
        $owned = function () use ($key, $lockKey, $token): void {
            // GET uses Redis's own expiration clock, as does the write script.
            if ($this->connection()->get($lockKey) !== $token) {
                throw SessionLockLost::forKey($key);
            }
        };
        try {
            $owned();

            return $callback($owned, $token);
        } finally {
            $this->release($lockKey, $token);
        }
    }

    #[\Override]
    public function putIfOwned(string $sessionKey, string $token, array $payload, ?int $ttlSeconds = null): bool
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        // Expired Redis keys disappear on GET under Redis's own clock. Neither
        // checking the owner nor setting state is a separate client operation.
        return (int) $this->connection()->eval(
            'if redis.call("get", KEYS[1]) ~= ARGV[1] then return 0 end '
            .'if ARGV[3] == "" then redis.call("set", KEYS[2], ARGV[2]) '
            .'else redis.call("setex", KEYS[2], ARGV[3], ARGV[2]) end return 1',
            2,
            $this->prefix.'lock:'.$sessionKey,
            $this->prefix.$sessionKey.':ephemeral',
            $token,
            $encoded,
            $ttlSeconds === null ? '' : (string) $ttlSeconds,
        ) === 1;
    }

    private function assertPositiveLease(int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('A fenced lease must have a positive duration.');
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
