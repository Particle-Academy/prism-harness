<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Stores\DatabaseSessionStore;

function store(): DatabaseSessionStore
{
    return new DatabaseSessionStore(app(DatabaseManager::class)->connection());
}

it('round-trips a payload', function (): void {
    store()->put('k', ['mode' => 'plan', 'steps' => 3]);

    expect(store()->get('k'))->toBe(['mode' => 'plan', 'steps' => 3]);
});

it('returns null for a key it has never seen', function (): void {
    expect(store()->get('nothing-here'))->toBeNull();
});

it('overwrites rather than appending on a second put', function (): void {
    store()->put('k', ['mode' => 'plan']);
    store()->put('k', ['mode' => 'build']);

    expect(store()->get('k'))->toBe(['mode' => 'build']);
});

it('forgets a key', function (): void {
    store()->put('k', ['mode' => 'plan']);
    store()->forget('k');

    expect(store()->get('k'))->toBeNull();
});

it('keeps a payload with no ttl', function (): void {
    store()->put('k', ['mode' => 'plan']);

    $this->travel(400)->days();

    expect(store()->get('k'))->toBe(['mode' => 'plan']);
});

it('stops serving an expired payload', function (): void {
    store()->put('k', ['mode' => 'plan'], ttlSeconds: 60);

    $this->travel(61)->seconds();

    // Expiry is enforced on read rather than by a sweeper, so a stale row can
    // never be served just because nothing has pruned it yet.
    expect(store()->get('k'))->toBeNull();
});

it('runs a callback under the lock and releases it after', function (): void {
    $result = store()->withLock('k', fn (): string => 'ran');

    expect($result)->toBe('ran')
        ->and(store()->withLock('k', fn (): string => 'ran again'))->toBe('ran again');
});

it('releases the lock even when the callback throws', function (): void {
    // A run that fails must not leave the session wedged for everyone after it.
    expect(fn (): mixed => store()->withLock('k', fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect(store()->withLock('k', fn (): bool => true))->toBeTrue();
});

it('refuses a second holder while the first is inside', function (): void {
    $outcome = store()->withLock('k', function (): string {
        try {
            store()->withLock('k', fn (): string => 'both', waitSeconds: 0);

            return 'both';
        } catch (SessionLocked) {
            return 'refused';
        }
    });

    expect($outcome)->toBe('refused');
});

it('reclaims a lock whose holder died', function (): void {
    $store = store();

    // Simulate a worker that acquired the lock and was killed before releasing:
    // the row is left behind with an expiry in the past.
    $store->withLock('k', function (): void {
        app(DatabaseManager::class)->connection()
            ->table('harness_session_state')
            ->where('key', 'lock:k')
            ->update(['expires_at' => now()->subMinute()]);
    });

    app(DatabaseManager::class)->connection()->table('harness_session_state')->insert([
        'key' => 'lock:k',
        'payload' => json_encode(['locked' => true]),
        'expires_at' => now()->subMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Without expiry-based reclamation, one dead worker would hold this
    // session shut permanently.
    expect($store->withLock('k', fn (): bool => true, ttlSeconds: 10, waitSeconds: 0))->toBeTrue();
});
