<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Prism\Harness\Models\Thread;
use Tests\Fixtures\Participant;

it('rejects a second live address at the database boundary', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $first = Thread::forParticipant($participant, 'support');
    $attributes = $first->getAttributes();
    unset($attributes['id']);

    expect(fn () => DB::table('harness_threads')->insert($attributes))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('releases the live key atomically and allows repeated retired addresses', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $first = Thread::forParticipant($participant, 'support');
    $key = $first->live_address;
    $first->retire();
    expect($first->fresh()->live_address)->toBeNull()
        ->and($first->fresh()->retired_at)->not->toBeNull();
    $second = Thread::forParticipant($participant, 'support');
    expect($second->live_address)->toBe($key);
    $second->retire();
    $third = Thread::forParticipant($participant, 'support');
    expect(Thread::query()->whereNull('live_address')->count())->toBe(2)
        ->and($third->live_address)->toBe($key);
});

it('does not let an unrelated stale save reclaim a retired live key', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $first = Thread::forParticipant($participant, 'support');
    $stale = $first->fresh();
    $first->retire();
    $replacement = Thread::forParticipant($participant, 'support');
    $stale->title = 'Updated history title';
    $stale->save();
    expect($stale->fresh()->live_address)->toBeNull()
        ->and(Thread::forParticipant($participant, 'support')->getKey())->toBe($replacement->getKey());
});

it('derives the key rather than accepting a caller supplied key', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $thread = Thread::query()->create([
        'participant_type' => $participant->getMorphClass(),
        'participant_id' => $participant->getKey(),
        'scope' => 'support',
        'live_address' => 'caller supplied',
    ]);
    expect($thread->live_address)->not->toBe('caller supplied');
    $key = $thread->live_address;
    $thread->live_address = 'another caller key';
    $thread->save();
    expect($thread->fresh()->live_address)->toBe($key);
});

it('backfills existing live rows without changing retired history', function (): void {
    $migration = require __DIR__.'/../database/migrations/0001_01_01_000005_add_unique_live_thread_address.php';
    $migration->down();
    $live = DB::table('harness_threads')->insertGetId(['scope' => 'legacy']);
    $retired = DB::table('harness_threads')->insertGetId(['scope' => 'legacy', 'retired_at' => now()]);
    $migration->up();
    expect(Thread::findOrFail($live)->live_address)->not->toBeNull()
        ->and(Thread::findOrFail($retired)->live_address)->toBeNull()
        ->and(Thread::findOrFail($retired)->isRetired())->toBeTrue();
});

it('refuses ambiguous legacy duplicates before changing the schema or rows', function (): void {
    $migration = require __DIR__.'/../database/migrations/0001_01_01_000005_add_unique_live_thread_address.php';
    $migration->down();
    DB::table('harness_threads')->insert([['scope' => 'legacy'], ['scope' => 'legacy']]);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Duplicate live harness thread addresses');
    expect(Schema::hasColumn('harness_threads', 'live_address'))->toBeFalse()
        ->and(DB::table('harness_threads')->whereNull('retired_at')->count())->toBe(2);
});

it('returns the winning row when an insert wins after the initial read', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $winner = null;
    $armed = true;
    // QueryExecuted fires after the SELECT has returned its empty result.
    // Insert the competitor before the resolver receives that result: no sleep,
    // mocked exception, or scheduler timing. The losing INSERT really hits SQL.
    DB::listen(function (QueryExecuted $query) use (&$armed, &$winner, $participant): void {
        if ($armed && str_starts_with(strtolower($query->sql), 'select')
            && str_contains($query->sql, 'harness_threads')) {
            $armed = false;
            $winner = Thread::query()->create([
                'participant_type' => $participant->getMorphClass(),
                'participant_id' => $participant->getKey(),
                'scope' => 'support',
            ]);
        }
    });

    $resolved = Thread::forParticipant($participant, 'support');
    expect($winner)->not->toBeNull()
        ->and($resolved->getKey())->toBe($winner->getKey())
        ->and(Thread::query()->count())->toBe(1);
});

it('does not swallow a unique violation unrelated to this live address', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $other = Thread::forParticipant($participant, 'other');
    Thread::creating(function (Thread $thread) use ($other): void {
        $thread->id = $other->id;
    });
    expect(fn () => Thread::forParticipant($participant, 'support'))
        ->toThrow(UniqueConstraintViolationException::class);
});
