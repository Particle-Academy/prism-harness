<?php

declare(strict_types=1);

use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Enums\Durability;
use Prism\Harness\Exceptions\UnsafeStateConfiguration;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Stores\DatabaseSessionStore;
use Prism\Harness\Stores\RedisSessionStore;

function manager(array $config): SessionStoreManager
{
    return new SessionStoreManager(app(), $config);
}

function config_with(array $stores, array $driverOverrides = []): array
{
    return [
        'stores' => $stores,
        'drivers' => array_replace_recursive([
            'redis' => ['driver' => 'redis', 'connection' => 'default', 'prefix' => 'harness:', 'durable' => false],
            'database' => ['driver' => 'database', 'connection' => null, 'table' => 'harness_session_state'],
        ], $driverOverrides),
    ];
}

it('refuses to point durable state at a volatile store', function (): void {
    // The whole reason this class exists. A cache holding pending tool
    // approvals loses a half-executed agent action on the next flush, and
    // nothing errors at the time — so the configuration is refused up front.
    $manager = manager(config_with([
        'ephemeral' => 'redis',
        'durable' => 'redis',
    ]));

    expect(fn (): SessionStore => $manager->durable())
        ->toThrow(UnsafeStateConfiguration::class);
});

it('explains how to fix an unsafe configuration', function (): void {
    $manager = manager(config_with(['ephemeral' => 'redis', 'durable' => 'redis']));

    try {
        $manager->durable();
        $this->fail('Expected the unsafe configuration to be refused.');
    } catch (UnsafeStateConfiguration $e) {
        // An error that only says "no" sends someone hunting. This one has to
        // name both ways out.
        expect($e->getMessage())->toContain('database')
            ->toContain('durable')
            ->toContain('volatile');
    }
});

it('allows redis for durable state when the operator asserts it is persistent', function (): void {
    // Redis with AOF or RDB really is durable. The package cannot detect that,
    // so the operator asserts it — and then it is allowed.
    $manager = manager(config_with(
        ['ephemeral' => 'redis', 'durable' => 'redis'],
        ['redis' => ['durable' => true]],
    ));

    expect($manager->durable())->toBeInstanceOf(RedisSessionStore::class)
        ->and($manager->durable()->durability())->toBe(Durability::Durable);
});

it('allows a volatile store for the ephemeral half', function (): void {
    // Ephemeral state is exactly what a cache is for; losing it degrades to a
    // default. The guard must not over-apply and force everything into the DB.
    $manager = manager(config_with(['ephemeral' => 'redis', 'durable' => 'database']));

    expect($manager->ephemeral())->toBeInstanceOf(RedisSessionStore::class)
        ->and($manager->ephemeral()->durability())->toBe(Durability::Volatile)
        ->and($manager->durable())->toBeInstanceOf(DatabaseSessionStore::class);
});

it('defaults redis to volatile, so the unsafe case is the one you opt into', function (): void {
    $manager = manager(config_with(['ephemeral' => 'redis', 'durable' => 'database']));

    expect($manager->ephemeral()->durability()->isDurable())->toBeFalse();
});

it('refuses a slot naming a driver that does not exist', function (): void {
    $manager = manager(config_with(['ephemeral' => 'memcached', 'durable' => 'database']));

    expect(fn (): SessionStore => $manager->ephemeral())
        ->toThrow(UnsafeStateConfiguration::class, 'memcached');
});
