<?php

declare(strict_types=1);

namespace Prism\Harness\Stores;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Prism\Harness\Contracts\FencedSessionStore;
use Prism\Harness\Enums\Durability;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\SessionLockLost;

/**
 * Session state in the database.
 *
 * Always Durable: rows survive a deploy, which is the whole reason this driver
 * exists alongside the Redis one. Slower per read, and that is an acceptable
 * trade for the state where losing it is a correctness failure rather than a
 * cache miss.
 */
class DatabaseSessionStore implements FencedSessionStore
{
    public function __construct(
        protected readonly ConnectionInterface $connection,
        protected readonly string $table = 'harness_session_state',
    ) {}

    #[\Override]
    public function get(string $key): ?array
    {
        $grammar = $this->query()->getGrammar();
        $expiry = $this->leaseExpiry();
        $expirySql = $expiry instanceof ExpressionContract ? (string) $expiry->getValue($grammar) : $grammar->wrap($expiry);
        $nowSql = (string) $this->storeNow()->getValue($grammar);
        $row = $this->query()->where('key', $key)->select('*')
            ->selectRaw('CASE WHEN '.$expirySql.' <= '.$nowSql.' THEN 1 ELSE 0 END as harness_expired')->first();

        if ($row === null) {
            return null;
        }

        // Expiry is enforced on read rather than by a sweeper, so a stale row
        // can never be served just because nothing has pruned it yet.
        if ($row->expires_at !== null && ! $this->leaseExpiryIsValid($row->expires_at)) {
            throw new \RuntimeException('The session state has an invalid expiry.');
        }
        if ((bool) $row->harness_expired) {
            // Expired-read housekeeping is also a mutation boundary. Never
            // delete a replacement that refreshed this key after the read.
            $this->query()->where('key', $key)->where('expires_at', $row->expires_at)
                ->where($this->leaseExpiry(), '<=', $this->storeNow())->delete();

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
                'expires_at' => $ttlSeconds === null ? null : $this->storeDeadline($ttlSeconds),
                'updated_at' => $this->storeNow(),
                'created_at' => $this->storeNow(),
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
        return $this->withFencedLock($key, static fn (): mixed => $callback(), $ttlSeconds, $waitSeconds);
    }

    protected function acquireOwned(string $lockKey, string $token, int $ttlSeconds): bool
    {
        $this->sweepExpiredFencedHolder($lockKey);
        try {
            return $this->query()->insert([
                'key' => $lockKey,
                'payload' => json_encode(['locked' => true, 'owner' => $token]),
                'expires_at' => $this->storeDeadline($ttlSeconds),
                'created_at' => $this->storeNow(),
                'updated_at' => $this->storeNow(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function leaseExpiryIsValid(mixed $expiresAt): bool
    {
        if (! is_string($expiresAt) || ! $this->isCompleteTimestamp($expiresAt)) {
            return false;
        }
        try {
            Carbon::parse($expiresAt);

            return true;
        } catch (\Exception) {
            // A complete-looking but invalid date is not proof of a live lease.
            // Only parsing is caught: database/connection failures remain native.
            return false;
        }
    }

    #[\Override]
    public function withFencedLock(string $key, Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        $this->assertPositiveLease($ttlSeconds);
        $lockKey = 'lock:'.$key;
        $token = (string) Str::uuid();
        $deadline = microtime(true) + $waitSeconds;
        while (! $this->acquireOwned($lockKey, $token, $ttlSeconds)) {
            if (microtime(true) >= $deadline) {
                throw SessionLocked::forKey($key, $waitSeconds);
            }
            usleep(100_000);
        }
        $owned = function () use ($key, $lockKey, $token): void {
            $row = $this->query()->where('key', $lockKey)->where('payload->owner', $token)
                ->where($this->leaseExpiry(), '>', $this->storeNow())->first();
            if ($row === null || ! $this->leaseExpiryIsValid($row->expires_at)) {
                throw SessionLockLost::forKey($key);
            }
        };
        try {
            $owned();

            return $callback($owned, $token);
        } finally {
            $this->query()->where('key', $lockKey)->where('payload->owner', $token)->delete();
        }
    }

    #[\Override]
    public function putIfOwned(string $sessionKey, string $token, array $payload, ?int $ttlSeconds = null): bool
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        // A transaction locks the actual lease row, including the first state
        // creation and MySQL's unchanged-value case. The final mutation ALSO
        // carries the owner/expiry predicate; no stale pre-read authorizes it.
        return $this->connection->transaction(function () use ($sessionKey, $token, $encoded, $ttlSeconds): bool {
            $lease = $this->ownedLeaseQuery($sessionKey, $token)->lockForUpdate()->first();
            if ($lease === null || ! $this->leaseExpiryIsValid($lease->expires_at)) {
                return false;
            }
            $stateKey = $sessionKey.':ephemeral';
            $state = $this->query()->where('key', $stateKey)->lockForUpdate()->first();
            if ($state === null) {
                $now = (string) $this->storeNow()->getValue($this->query()->getGrammar());
                $expiry = $ttlSeconds === null ? 'NULL'
                    : (string) $this->storeDeadline($ttlSeconds)->getValue($this->query()->getGrammar());

                // A pre-run configuration error never reaches this path. A
                // reclaimed first-run lease cannot allocate even an empty row.
                return $this->query()->insertUsing(
                    ['key', 'payload', 'expires_at', 'created_at', 'updated_at'],
                    $this->ownedLeaseQuery($sessionKey, $token)
                        ->selectRaw('?, ?, '.$expiry.', '.$now.', '.$now, [$stateKey, $encoded]),
                ) === 1;
            }
            $values = [
                'payload' => $encoded,
                'expires_at' => $ttlSeconds === null ? null : $this->storeDeadline($ttlSeconds),
                'updated_at' => $this->storeNow(),
            ];
            if ($this->query()->getGrammar() instanceof MySqlGrammar) {
                // A joined MySQL UPDATE requires an unambiguous target column.
                $values = array_combine(array_map(fn (string $column): string => $this->table.'.'.$column, array_keys($values)), array_values($values));
            }
            if ($this->ownedStateQuery($sessionKey, $token)->update($values) === 1) {
                return true;
            }
            if (! $this->query()->getGrammar() instanceof MySqlGrammar) {
                return false;
            }
            // MySQL reports changed rows by default, so an idempotent owned
            // write can report zero. The lease row remains locked throughout
            // this transaction; accept only an identical current state under
            // the same still-live owner, never a separate unfenced retry.
            $current = $this->ownedStateQuery($sessionKey, $token);
            $grammar = $this->query()->getGrammar();
            $current->whereRaw($grammar->wrap($this->table.'.payload').' = '.$grammar->compileJsonValueCast('?'), [$encoded]);

            return $current->exists();
        });
    }

    private function ownedStateQuery(string $sessionKey, string $token): Builder
    {
        return $this->query()
            ->join($this->table.' as harness_lease', function (JoinClause $join) use ($sessionKey): void {
                $join->where('harness_lease.key', '=', 'lock:'.$sessionKey);
            })
            ->where($this->table.'.key', $sessionKey.':ephemeral')
            ->where('harness_lease.payload->owner', $token)
            ->where($this->leaseExpiry('harness_lease.expires_at'), '>', $this->storeNow());
    }

    private function ownedLeaseQuery(string $sessionKey, string $token): Builder
    {
        return $this->query()->where('key', 'lock:'.$sessionKey)->where('payload->owner', $token)
            ->where($this->leaseExpiry(), '>', $this->storeNow());
    }

    private function assertPositiveLease(int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('A fenced lease must have a positive duration.');
        }
    }

    private function storeNow(): ExpressionContract
    {
        $grammar = $this->query()->getGrammar();

        return new Expression(match (true) {
            $grammar instanceof PostgresGrammar => "(statement_timestamp() AT TIME ZONE 'UTC')",
            $grammar instanceof SqlServerGrammar => 'SYSUTCDATETIME()',
            default => 'CURRENT_TIMESTAMP',
        });
    }

    private function storeDeadline(int $seconds): ExpressionContract
    {
        $grammar = $this->query()->getGrammar();

        return new Expression(match (true) {
            $grammar instanceof SQLiteGrammar => "datetime(CURRENT_TIMESTAMP, '$seconds seconds')",
            $grammar instanceof MySqlGrammar => "DATE_ADD(CURRENT_TIMESTAMP, INTERVAL $seconds SECOND)",
            $grammar instanceof PostgresGrammar => "(statement_timestamp() AT TIME ZONE 'UTC') + make_interval(secs => $seconds)",
            $grammar instanceof SqlServerGrammar => "DATEADD(second, $seconds, SYSUTCDATETIME())",
            default => throw new \LogicException('This database grammar does not support fenced session leases.'),
        });
    }

    /** @param literal-string $column */
    private function leaseExpiry(string $column = 'expires_at'): string|ExpressionContract
    {
        // SQLite stores dates as text: malformed complete-looking values must
        // not become live merely because their bytes sort after a valid date.
        return $this->query()->getGrammar() instanceof SQLiteGrammar
            ? new Expression('datetime('.$column.')') : $column;
    }

    private function sweepExpiredFencedHolder(string $lockKey): void
    {
        $row = $this->query()->where('key', $lockKey)->first();
        if ($row === null || ! $this->leaseExpiryIsValid($row->expires_at)) {
            return;
        }
        $this->query()->where('key', $lockKey)->where('expires_at', $row->expires_at)
            ->where($this->leaseExpiry(), '<=', $this->storeNow())->delete();
    }

    #[\Override]
    public function durability(): Durability
    {
        return Durability::Durable;
    }

    /**
     * Whether this is a whole date-time and not the beginning of one.
     *
     * Shape rather than an exact format: the drivers return the same instant
     * with different tails — a fractional second here, an offset there — and a
     * strict format match would refuse the store's OWN writes on some
     * connection and then never sweep anything, wedging every session it was
     * meant to protect. Matching the leading date and time catches a value that
     * stops early, which is the failure this exists for, without pretending to
     * know how each driver renders the rest.
     */
    protected function isCompleteTimestamp(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $value) === 1;
    }

    /**
     * @return Builder
     */
    protected function query()
    {
        return $this->connection->table($this->table);
    }
}
