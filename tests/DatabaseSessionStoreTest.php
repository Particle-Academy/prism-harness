<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
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

it('does not delete a lock a DIFFERENT worker now holds', function (): void {
    // ONE KEY HANDED TO TWO CALLERS, which is the only way this store can fail
    // that matters: everything built on withLock — a run advancing a session, a
    // task claim — is exclusive only because this is.
    //
    // The sequence: A takes the lock; A is slow and its TTL lapses; B
    // legitimately reclaims the expired key; A finally returns and releases.
    // A release scoped only to the KEY deletes B's lock, and the next worker
    // walks straight in while B is still inside.
    //
    // The sibling Redis store has guarded against exactly this since it was
    // written — a random token, checked on release — and this one did not.
    $store = store();

    $store->withLock('k', function (): void {
        // A's lease lapses while A is still working.
        $this->travel(11)->seconds();

        // B reclaims it. Written directly against the table because PHP has no
        // second thread to run B in; this is byte for byte what B's own
        // acquire() would have inserted.
        DB::table('harness_session_state')->where('key', 'lock:k')->delete();
        DB::table('harness_session_state')->insert([
            'key' => 'lock:k',
            'payload' => json_encode(['locked' => true]),
            'expires_at' => now()->addSeconds(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, ttlSeconds: 10, waitSeconds: 0);

    // A has returned and run its release. B must still hold the key.
    expect(DB::table('harness_session_state')->where('key', 'lock:k')->exists())->toBeTrue();
});

it('still releases a lock it does hold', function (): void {
    // The control for the test above. A release that never deletes anything
    // would satisfy it perfectly and wedge every session in the application.
    $store = store();

    $store->withLock('k', fn (): bool => true, ttlSeconds: 10, waitSeconds: 0);

    expect(DB::table('harness_session_state')->where('key', 'lock:k')->exists())->toBeFalse()
        ->and($store->withLock('k', fn (): string => 'again', waitSeconds: 0))->toBe('again');
});

it('waits rather than stealing a lock whose expiry cannot be read', function (): void {
    // THE SECOND VARIANT OF THE STOLEN-LOCK BUG, found by a sibling port. The
    // first is releasing by key; this one is deciding that an unreadable expiry
    // means "expired".
    //
    // It is not a parse failure that saves you. Every PREFIX of a timestamp is
    // a smaller timestamp: a torn write leaves a value that parses perfectly
    // and sits in the past, so the sweep deletes a lock somebody is actively
    // holding. Under SQL it is worse than under a file, because the comparison
    // is a string comparison — '1735689' sorts before '2026-…' just as surely
    // as it parses before it.
    //
    // Unreadable must mean WAIT: a loud, recoverable SessionLocked, which
    // resolves itself the moment a human or a deploy fixes the row. Deleting is
    // the one answer with no way back, because the worker on the other side is
    // still running.
    $torn = [
        '' => 'an empty expiry, from a write that had not started',
        '2026-09-04 12:0' => 'a truncated expiry, from a write that did not finish',
        '1735689' => 'a prefix of a unix timestamp, which parses as 1970',
    ];

    foreach ($torn as $value => $why) {
        DB::table('harness_session_state')->where('key', 'lock:k')->delete();
        DB::table('harness_session_state')->insert([
            'key' => 'lock:k',
            'payload' => json_encode(['locked' => true]),
            'expires_at' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(fn (): mixed => store()->withLock('k', fn (): string => 'stolen', waitSeconds: 0))
            ->toThrow(SessionLocked::class, 'k')
            ->and(DB::table('harness_session_state')->where('key', 'lock:k')->exists())
            // Still there: refused, not swept. ($why)
            ->toBeTrue();
    }
});

it('reads its own written expiry back, with nothing planted by hand', function (): void {
    // THE ROUND TRIP, and it earns its place: every other test of the sweep
    // writes the expiry itself, so all of them would stay green if the writer
    // and the reader stopped agreeing. A sibling port's mutation run found
    // exactly that hole — dropping the terminator from its lockfile format went
    // green, because no test ever fed the real writer's output to the real
    // reader.
    //
    // Here the store writes the expiry, the store parses it, and the store
    // decides it has passed. If the completeness check ever refuses the format
    // one of the drivers actually emits, the sweep silently stops reclaiming
    // anything and every dead worker wedges a session — and this goes red.
    $store = store();
    $reclaimed = null;

    $store->withLock('k', function () use ($store, &$reclaimed): void {
        // The holder's own lease lapses while it is still inside.
        $this->travel(11)->seconds();

        $reclaimed = $store->withLock('k', fn (): string => 'reclaimed', ttlSeconds: 10, waitSeconds: 0);
    }, ttlSeconds: 10, waitSeconds: 0);

    expect($reclaimed)->toBe('reclaimed');
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

    // The abandoned row really is still there. Release no longer deletes a row
    // that is not the one it inserted — which is what makes this an honest
    // stand-in for a process that died, rather than a tidy exit.
    expect(DB::table('harness_session_state')->where('key', 'lock:k')->exists())->toBeTrue();

    // Without expiry-based reclamation, one dead worker would hold this
    // session shut permanently.
    expect($store->withLock('k', fn (): bool => true, ttlSeconds: 10, waitSeconds: 0))->toBeTrue();
});
