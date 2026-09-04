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

        while (($heldUntil = $this->acquire($lockKey, $ttlSeconds)) === null) {
            if (microtime(true) >= $deadline) {
                throw SessionLocked::forKey($key, $waitSeconds);
            }

            usleep(100_000);
        }

        try {
            return $callback();
        } finally {
            $this->release($lockKey, $heldUntil);
        }
    }

    #[\Override]
    public function durability(): Durability
    {
        return Durability::Durable;
    }

    /**
     * Claim the lock row, and return the expiry that proves it is OURS.
     *
     * The unique index on `key` is what makes this exclusive: two workers
     * inserting the same key at once means one insert fails, rather than both
     * believing they hold it. Checking-then-inserting would leave a gap between
     * the two statements for exactly that race — and note the insert writes the
     * key and its expiry in ONE statement, so there is never a moment where the
     * row exists without an expiry for another worker to read as "expired in
     * 1970" and sweep away.
     *
     * The returned expiry is the ownership proof; see {@see self::release()}.
     * Null means the lock was not taken.
     */
    protected function acquire(string $lockKey, int $ttlSeconds): ?Carbon
    {
        // Clear an expired holder first, so a worker that died mid-run does not
        // hold the session forever. NULL is deliberately not swept: a lock row
        // with no expiry cannot be shown to be abandoned, and taking one on the
        // assumption that it is would hand the key to a second caller.
        $this->query()
            ->where('key', $lockKey)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::now())
            ->delete();

        $expiresAt = Carbon::now()->addSeconds($ttlSeconds);

        try {
            $this->query()->insert([
                'key' => $lockKey,
                'payload' => json_encode(['locked' => true]),
                'expires_at' => $expiresAt,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return $expiresAt;
        } catch (QueryException) {
            return null;
        }
    }

    /**
     * Release the lock ONLY IF IT IS STILL THE ONE WE TOOK.
     *
     * Deleting by key alone is the bug this method exists to prevent, and it is
     * not theoretical: a slow worker whose TTL lapsed mid-callback would delete
     * the lock a DIFFERENT worker had legitimately reclaimed, and the next
     * caller would walk in while that worker was still inside. One key, two
     * holders — which is the only failure of this class that matters, because
     * everything above it is exclusive only because this is.
     *
     * {@see RedisSessionStore::release()} has guarded
     * against exactly this since it was written, with a random token and a
     * compare-and-delete. This is the same guard, using the expiry as the
     * token: a row that is still ours has the expiry WE inserted, and a
     * reclaimer's expiry is necessarily later — it could only have taken the
     * key after ours had passed, and it stamped its own from the time it did.
     * So equality here means "nobody has taken this since", which is the whole
     * question. No extra column, and it works on every driver, where comparing
     * a JSON payload column for equality does not.
     */
    protected function release(string $lockKey, Carbon $heldUntil): void
    {
        $this->query()
            ->where('key', $lockKey)
            ->where('expires_at', $heldUntil)
            ->delete();
    }

    /**
     * @return Builder
     */
    protected function query()
    {
        return $this->connection->table($this->table);
    }
}
