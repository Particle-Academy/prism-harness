<?php

declare(strict_types=1);

namespace Prism\Harness\Stores;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Enums\Durability;
use Prism\Harness\Exceptions\SessionLocked;

/**
 * Session state in the database.
 *
 * Always Durable: rows survive a deploy, which is the whole reason this driver
 * exists alongside the Redis one. Slower per read, and that is an acceptable
 * trade for the state where losing it is a correctness failure rather than a
 * cache miss.
 */
class DatabaseSessionStore implements SessionStore
{
    public function __construct(
        protected readonly ConnectionInterface $connection,
        protected readonly string $table = 'harness_session_state',
    ) {}

    #[\Override]
    public function get(string $key): ?array
    {
        $row = $this->query()->where('key', $key)->first();

        if ($row === null) {
            return null;
        }

        // Expiry is enforced on read rather than by a sweeper, so a stale row
        // can never be served just because nothing has pruned it yet.
        if ($row->expires_at !== null && Carbon::parse($row->expires_at)->isPast()) {
            $this->forget($key);

            return null;
        }

        $decoded = json_decode((string) $row->payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    #[\Override]
    public function put(string $key, array $payload, ?int $ttlSeconds = null): void
    {
        $this->query()->updateOrInsert(
            ['key' => $key],
            [
                'payload' => json_encode($payload),
                'expires_at' => $ttlSeconds === null ? null : Carbon::now()->addSeconds($ttlSeconds),
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ],
        );
    }

    #[\Override]
    public function forget(string $key): void
    {
        $this->query()->where('key', $key)->delete();
    }

    #[\Override]
    public function withLock(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        $lockKey = 'lock:'.$key;
        $deadline = microtime(true) + $waitSeconds;

        while (! $this->acquire($lockKey, $ttlSeconds)) {
            if (microtime(true) >= $deadline) {
                throw SessionLocked::forKey($key, $waitSeconds);
            }

            usleep(100_000);
        }

        try {
            return $callback();
        } finally {
            $this->query()->where('key', $lockKey)->delete();
        }
    }

    #[\Override]
    public function durability(): Durability
    {
        return Durability::Durable;
    }

    /**
     * Claim the lock row, or fail.
     *
     * The unique index on `key` is what makes this exclusive: two workers
     * inserting the same key at once means one insert fails, rather than both
     * believing they hold it. Checking-then-inserting would leave a gap between
     * the two statements for exactly that race.
     */
    protected function acquire(string $lockKey, int $ttlSeconds): bool
    {
        // Clear an expired holder first, so a worker that died mid-run does not
        // hold the session forever.
        $this->query()
            ->where('key', $lockKey)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->delete();

        try {
            $this->query()->insert([
                'key' => $lockKey,
                'payload' => json_encode(['locked' => true]),
                'expires_at' => Carbon::now()->addSeconds($ttlSeconds),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * @return Builder
     */
    protected function query()
    {
        return $this->connection->table($this->table);
    }
}
