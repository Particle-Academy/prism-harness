<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Harness\Sessions\DatabaseAddressIndex;
use Prism\Harness\Sessions\IndexedSessionStore;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Stores\DatabaseSessionStore;
use Prism\Harness\Stores\RedisSessionStore;
use Tests\Fixtures\Participant;
use Tests\TestCase;

/** Deterministic Redis command contract; no claim of native Redis execution. */
final class FencedRedisCommands
{
    public int $clock = 10000;

    public array $values = [];

    public array $scripts = [];

    public int $clientReads = 0;

    private function read(string $key): ?string
    {
        $entry = $this->values[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['expires'] !== null && $entry['expires'] <= $this->clock) {
            unset($this->values[$key]);

            return null;
        }

        return $entry['value'];
    }

    public function get(string $key): ?string
    {
        $this->clientReads++;

        return $this->read($key);
    }

    public function set(string $key, string $value, mixed ...$options): bool
    {
        if ($options !== []) {
            TestCase::assertSame(['EX', $options[1], 'NX'], $options);
            if ($this->read($key) !== null) {
                return false;
            }
        }
        $this->values[$key] = ['value' => $value, 'expires' => $options === [] ? null : $this->clock + $options[1]];

        return true;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->values[$key] = ['value' => $value, 'expires' => $this->clock + $ttl];

        return true;
    }

    public function eval(string $script, int $keyCount, string ...$arguments): int
    {
        $this->scripts[] = ['script' => $script, 'keys' => $keyCount, 'arguments' => $arguments];
        if ($keyCount === 1) {
            TestCase::assertSame('if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end', $script);
            if ($this->read($arguments[0]) !== $arguments[1]) {
                return 0;
            }
            unset($this->values[$arguments[0]]);

            return 1;
        }
        TestCase::assertSame(2, $keyCount);
        TestCase::assertCount(5, $arguments);
        // Pin the reviewed Lua command contract as well as observing its keys,
        // arguments, TTL and lack of separate client reads/writes.
        TestCase::assertSame('if redis.call("get", KEYS[1]) ~= ARGV[1] then return 0 end if ARGV[3] == "" then redis.call("set", KEYS[2], ARGV[2]) else redis.call("setex", KEYS[2], ARGV[3], ARGV[2]) end return 1', $script);
        [$lock, $state, $token, $payload, $ttl] = $arguments;
        if ($this->read($lock) !== $token) {
            return 0;
        }
        $this->values[$state] = ['value' => $payload, 'expires' => $ttl === '' ? null : $this->clock + (int) $ttl];

        return 1;
    }
}

final class FencedSessionStoreTest extends TestCase
{
    public function test_non_contention_database_errors_preserve_native_identity(): void
    {
        $store = $this->database();
        $native = new QueryException('testing', 'synthetic_insert', [], new RuntimeException('synthetic-driver-rejection'));
        DB::connection()->beforeExecuting(static function (string $query) use ($native): void {
            if (str_starts_with(strtolower($query), 'insert into "harness_session_state"')) {
                throw $native;
            }
        });
        $called = false;
        $caught = null;
        try {
            $store->withFencedLock('driver-error', static function () use (&$called): void {
                $called = true;
            }, waitSeconds: 0);
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertSame($native, $caught, 'Storage failures are not lock contention');
        self::assertFalse($called);
        self::assertSame(0, DB::table('harness_session_state')->count());
    }

    private function database(): DatabaseSessionStore
    {
        return new DatabaseSessionStore(DB::connection());
    }

    public function test_reclaim_before_the_first_insert_cannot_allocate_old_state(): void
    {
        $store = $this->database();
        $first = null;
        DB::connection()->beforeExecuting(static function (string $query, array $bindings) use (&$first): void {
            if (Fiber::getCurrent() !== $first || ! str_starts_with(strtolower($query), 'insert into "harness_session_state"')) {
                return;
            }
            foreach ($bindings as $value) {
                $payload = is_string($value) ? json_decode($value, true) : null;
                if (is_array($payload) && ($payload['must'] ?? null) === 'not-allocate') {
                    Fiber::suspend('before-first-state-insert');

                    return;
                }
            }
        });
        $first = new Fiber(static fn () => $store->withFencedLock('first-insert', static fn (Closure $owned, string $token): bool => $store->putIfOwned('first-insert', $token, ['must' => 'not-allocate']), 300, 0));
        $second = new Fiber(static fn () => $store->withLock('first-insert', static fn () => Fiber::suspend('replacement-held'), 300, 0));
        try {
            self::assertSame('before-first-state-insert', $first->start());
            DB::table('harness_session_state')->where('key', 'lock:first-insert')->update(['expires_at' => '2000-01-01 00:00:00']);
            self::assertSame('replacement-held', $second->start());
            $replacement = (array) DB::table('harness_session_state')->where('key', 'lock:first-insert')->first();
            $first->resume();
            self::assertFalse($first->getReturn());
            self::assertFalse(DB::table('harness_session_state')->where('key', 'first-insert:ephemeral')->exists());
            self::assertSame($replacement, (array) DB::table('harness_session_state')->where('key', 'lock:first-insert')->first());
        } finally {
            foreach ([$first, $second] as $fiber) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            }
        }
    }

    public function test_indexed_rejected_fence_does_not_mutate_the_resource_index(): void
    {
        $participant = Participant::create(['name' => 'Indexed fence']);
        $address = SessionAddress::forParticipant($participant, 'indexed-fence', 'indexed-agent');
        $index = DatabaseAddressIndex::configured(DB::connection());
        $store = new IndexedSessionStore($this->database(), $index, $address, 'ephemeral');
        $store->withFencedLock($address->sessionKey(), function (Closure $owned, string $token) use ($store, $address): void {
            $before = DB::table('harness_address_resources')->get()->toArray();
            self::assertFalse($store->putIfOwned($address->sessionKey(), 'foreign-token', ['must' => 'not-index']));
            self::assertEquals($before, DB::table('harness_address_resources')->get()->toArray());
            self::assertTrue($store->putIfOwned($address->sessionKey(), $token, ['sentinel' => 'indexed']));
            self::assertSame(['sentinel' => 'indexed'], $store->get($address->sessionKey().':ephemeral'));
            self::assertSame(1, DB::table('harness_address_resources')->where('kind', 'state')->count());
        }, waitSeconds: 0);
    }

    public static function databaseGrammars(): iterable
    {
        foreach ([
            'mysql' => [MySqlConnection::class, 'for update', 'current_timestamp'],
            'postgres' => [PostgresConnection::class, 'for update', 'statement_timestamp'],
            'sqlserver' => [SqlServerConnection::class, 'updlock', 'sysutcdatetime'],
        ] as $name => $driver) {
            yield $name.'-changed' => [...$driver, 1, false, true];
            yield $name.'-rejected' => [...$driver, 0, false, false];
            if ($name === 'mysql') {
                yield $name.'-owned-noop' => [...$driver, 0, true, true];
            }
        }
    }

    #[DataProvider('databaseGrammars')]
    public function test_driver_contract_locks_lease_and_compiles_an_atomic_owned_update(string $connectionClass, string $lockClause, string $clock, int $affected, bool $fallback, bool $expected): void
    {
        // Actual Laravel driver/grammar compilation, with deterministic query
        // results. The PDO supplies only transaction bookkeeping: no MySQL,
        // Postgres or SQL Server execution is claimed by this contract test.
        $driver = $connectionClass === SqlServerConnection::class ? 'sqlsrv'
            : ($connectionClass === PostgresConnection::class ? 'pgsql' : 'mysql');
        $connection = Mockery::mock($connectionClass.'[select,update]', [new PDO('sqlite::memory:'), 'grammar-fixture', '', ['driver' => $driver]]);
        $selects = [];
        $updates = [];
        $connection->shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings) use (&$selects, $connection, $fallback): array {
            self::assertGreaterThan(0, $connection->transactionLevel());
            $selects[] = ['sql' => strtolower($sql), 'bindings' => $bindings];

            if (str_starts_with(strtolower($sql), 'select exists(')) {
                return [(object) ['exists' => $fallback]];
            }

            return [(object) (in_array('lock:grammar', $bindings, true)
                ? ['key' => 'lock:grammar', 'payload' => '{"locked":true,"owner":"owner-token"}', 'expires_at' => '2099-01-01 00:00:00']
                : ['key' => 'grammar:ephemeral', 'payload' => '{"sentinel":"new"}', 'expires_at' => null])];
        });
        $connection->shouldReceive('update')->andReturnUsing(function (string $sql, array $bindings) use (&$updates, $connection, $affected): int {
            self::assertGreaterThan(0, $connection->transactionLevel());
            $updates[] = ['sql' => strtolower($sql), 'bindings' => $bindings];

            return $affected;
        });
        $store = new DatabaseSessionStore($connection);
        self::assertSame($expected, $store->putIfOwned('grammar', 'owner-token', ['sentinel' => 'new']));
        $hasFallback = $driver === 'mysql' && $affected === 0;
        self::assertCount($hasFallback ? 3 : 2, $selects);
        if ($hasFallback) {
            self::assertStringContainsString('cast(? as json)', $selects[2]['sql']);
            self::assertContains('owner-token', $selects[2]['bindings']);
        }
        self::assertStringContainsString($lockClause, $selects[0]['sql']);
        self::assertStringContainsString($clock, $selects[0]['sql']);
        self::assertContains('owner-token', $selects[0]['bindings']);
        self::assertCount(1, $updates);
        self::assertStringContainsString('owner', $updates[0]['sql']);
        self::assertStringContainsString($clock, $updates[0]['sql']);
        self::assertContains('owner-token', $updates[0]['bindings']);
        self::assertContains('lock:grammar', $updates[0]['bindings']);
        self::assertContains('grammar:ephemeral', $updates[0]['bindings']);
        self::assertSame(0, $connection->transactionLevel());
    }

    public function test_database_initial_insert_idempotent_update_and_foreign_token(): void
    {
        $store = $this->database();
        $store->withFencedLock('native', function (Closure $owned, string $token) use ($store): void {
            self::assertSame(1, DB::table('harness_session_state')->count(), 'Acquisition must not allocate state');
            self::assertFalse($store->putIfOwned('native', 'foreign-owner', ['must' => 'not-allocate']));
            self::assertSame(1, DB::table('harness_session_state')->count());
            self::assertTrue($store->putIfOwned('native', $token, ['run' => ['id' => 'one', 'status' => 'running']]));
            self::assertTrue($store->putIfOwned('native', $token, ['run' => ['id' => 'one', 'status' => 'running']]));
            self::assertTrue($store->putIfOwned('native', $token, ['run' => ['id' => 'one', 'status' => 'completed']]));
            self::assertSame('completed', $store->get('native:ephemeral')['run']['status']);
            $snapshot = DB::table('harness_session_state')->get()->toArray();
            self::assertFalse($store->putIfOwned('native', 'foreign-owner', ['private' => 'must-not-write']));
            self::assertFalse($store->putIfOwned('another-session', $token, ['private' => 'must-not-allocate']));
            self::assertEquals($snapshot, DB::table('harness_session_state')->get()->toArray());
            $owned();
        }, waitSeconds: 0);
        self::assertFalse(DB::table('harness_session_state')->where('key', 'lock:native')->exists());
    }

    public static function invalidExpiries(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'truncated' => ['2026-12-03 12:0'];
        yield 'invalid-full' => ['2026-99-99 99:99:99'];
        yield 'expired' => ['2000-01-01 00:00:00'];
    }

    #[DataProvider('invalidExpiries')]
    public function test_database_invalid_or_expired_fence_mutates_nothing(?string $expiry): void
    {
        $store = $this->database();
        $store->withFencedLock('invalid', function (Closure $owned, string $token) use ($store, $expiry): void {
            DB::table('harness_session_state')->where('key', 'lock:invalid')->update(['expires_at' => $expiry]);
            $before = DB::table('harness_session_state')->get()->toArray();
            self::assertFalse($store->putIfOwned('invalid', $token, ['run' => ['status' => 'completed']]));
            self::assertEquals($before, DB::table('harness_session_state')->get()->toArray());
        }, waitSeconds: 0);
    }

    public static function workerClocks(): iterable
    {
        yield 'worker-future' => ['2099-01-01 00:00:00'];
        yield 'worker-past' => ['1900-01-01 00:00:00'];
    }

    #[DataProvider('workerClocks')]
    public function test_database_clock_owns_lease_and_state_expiry(string $workerTime): void
    {
        $store = $this->database();
        Carbon::setTestNow($workerTime);
        try {
            $store->withFencedLock('clock', function (Closure $owned, string $token) use ($store): void {
                $native = DB::selectOne('select CURRENT_TIMESTAMP as stamp')->stamp;
                $expiry = DB::table('harness_session_state')->where('key', 'lock:clock')->value('expires_at');
                $delta = (new DateTimeImmutable($expiry))->getTimestamp() - (new DateTimeImmutable($native))->getTimestamp();
                self::assertGreaterThanOrEqual(58, $delta);
                self::assertLessThanOrEqual(60, $delta);
                self::assertTrue($store->putIfOwned('clock', $token, ['sentinel' => 'live'], 60));
                self::assertSame(['sentinel' => 'live'], $store->get('clock:ephemeral'), 'Worker time must not delete backend-live state');
                $owned();
            }, ttlSeconds: 60, waitSeconds: 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expiry_housekeeping_cannot_delete_a_refreshed_state(): void
    {
        $store = $this->database();
        $store->put('expiry-refresh', ['old' => true], -1);
        $replaced = false;
        DB::connection()->beforeExecuting(static function (string $query) use ($store, &$replaced): void {
            if (! $replaced && str_starts_with(strtolower($query), 'delete from "harness_session_state"')) {
                $replaced = true;
                $store->put('expiry-refresh', ['replacement' => 'live'], 60);
            }
        });
        self::assertNull($store->get('expiry-refresh'));
        self::assertTrue($replaced, 'The native expired-read/delete seam must execute');
        self::assertSame(['replacement' => 'live'], $store->get('expiry-refresh'));
    }

    public function test_actual_expired_holder_release_preserves_an_equal_expiry_new_uuid(): void
    {
        $store = $this->database();
        $first = new Fiber(static fn () => $store->withLock('release', static fn () => Fiber::suspend('old-held'), 300, 0));
        $second = new Fiber(static fn () => $store->withLock('release', static fn () => Fiber::suspend('new-held'), 300, 0));
        try {
            self::assertSame('old-held', $first->start());
            $old = DB::table('harness_session_state')->where('key', 'lock:release')->first();
            DB::table('harness_session_state')->where('key', 'lock:release')->update(['expires_at' => '2000-01-01 00:00:00']);
            self::assertSame('new-held', $second->start());
            DB::table('harness_session_state')->where('key', 'lock:release')->update(['expires_at' => $old->expires_at]);
            $replacement = (array) DB::table('harness_session_state')->where('key', 'lock:release')->first();
            self::assertNotSame($old->payload, $replacement['payload']);
            $first->resume();
            self::assertSame($replacement, (array) DB::table('harness_session_state')->where('key', 'lock:release')->first());
            $second->resume();
            self::assertFalse(DB::table('harness_session_state')->where('key', 'lock:release')->exists());
        } finally {
            foreach ([$first, $second] as $fiber) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            }
        }
    }

    public function test_redis_fence_is_one_atomic_command_and_uses_redis_time(): void
    {
        $commands = new FencedRedisCommands;
        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($commands);
        $store = new RedisSessionStore($factory);
        Carbon::setTestNow('2099-01-01');
        try {
            $store->withFencedLock('redis', function (Closure $owned, string $token) use ($store, $commands): void {
                $reads = $commands->clientReads;
                self::assertTrue($store->putIfOwned('redis', $token, ['run' => ['status' => 'completed']], 30));
                self::assertSame($reads, $commands->clientReads, 'No separate client GET may authorize a write');
                self::assertSame(['run' => ['status' => 'completed']], $store->get('redis:ephemeral'));
                self::assertSame($commands->clock + 30, $commands->values['harness:redis:ephemeral']['expires']);
                self::assertTrue($store->putIfOwned('redis', $token, ['sentinel' => 'no-ttl']));
                self::assertNull($commands->values['harness:redis:ephemeral']['expires']);
                $before = $commands->values;
                self::assertFalse($store->putIfOwned('redis', 'wrong', ['must' => 'not-write']));
                self::assertSame($before, $commands->values);
                $commands->values['harness:lock:redis'] = ['value' => 'replacement-token', 'expires' => $commands->clock + 60];
                $replacement = $commands->values;
                self::assertFalse($store->putIfOwned('redis', $token, ['must' => 'not-overwrite']));
                self::assertSame($replacement, $commands->values);
            }, waitSeconds: 0);
            self::assertSame('replacement-token', $commands->values['harness:lock:redis']['value'], 'Old Lua release preserves a replacement');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expired_redis_owner_cannot_allocate_or_update_state(): void
    {
        $commands = new FencedRedisCommands;
        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('default')->andReturn($commands);
        $store = new RedisSessionStore($factory);
        $store->withFencedLock('expired', function (Closure $owned, string $token) use ($store, $commands): void {
            $commands->clock += 11;
            self::assertFalse($store->putIfOwned('expired', $token, ['must' => 'not-allocate']));
            self::assertArrayNotHasKey('harness:expired:ephemeral', $commands->values);
        }, ttlSeconds: 10, waitSeconds: 0);
    }
}
