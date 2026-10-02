<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Models\Thread;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\Participant;

it('resolves a shared thread by scope alone', function (): void {
    $thread = Thread::shared('admin-review');
    expect(Thread::shared('admin-review')->getKey())->toBe($thread->getKey())
        ->and($thread->fresh()->participant_type)->toBeNull()
        ->and($thread->fresh()->participant_id)->toBeNull();
});

it('keeps shared scopes and participant conversations separate', function (): void {
    $agency = Participant::create(['name' => 'Agency']);
    $admins = Thread::shared('admin-review');
    $moderators = Thread::shared('moderator-review');
    $agencyThread = Thread::forParticipant($agency, 'admin-review');
    expect(array_unique([$admins->getKey(), $moderators->getKey(), $agencyThread->getKey()]))->toHaveCount(3)
        ->and(Thread::shared('admin-review')->getKey())->toBe($admins->getKey());
});

it('keeps shared history while retirement starts a new conversation', function (): void {
    $first = Thread::shared('admin-review');
    $first->record([new UserMessage('Keep this history.')]);
    $first->retire();
    $second = Thread::shared('admin-review');
    expect($second->getKey())->not->toBe($first->getKey())
        ->and($second->storedMessages()->count())->toBe(0)
        ->and($first->storedMessages()->count())->toBe(1)
        ->and($first->fresh()->live_key)->toBeNull();
});

it('omits participant and retirement columns from the shared insert', function (): void {
    $insert = null;
    DB::listen(function (QueryExecuted $query) use (&$insert): void {
        if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'harness_threads')) {
            $insert = $query->sql;
        }
    });
    Thread::shared('admin-review');
    expect($insert)->not->toBeNull()
        ->and(str_contains($insert, 'participant_type'))->toBeFalse()
        ->and(str_contains($insert, 'participant_id'))->toBeFalse()
        ->and(str_contains($insert, 'retired_at'))->toBeFalse();
});

it('makes SQL reject a second live shared address', function (): void {
    $thread = Thread::shared('admin-review');
    $attributes = $thread->getAttributes();
    unset($attributes['id']);
    expect(fn () => DB::table('harness_threads')->insert($attributes))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('returns the winning shared row after a competing insert', function (): void {
    $winner = null;
    $armed = true;
    DB::listen(function (QueryExecuted $query) use (&$armed, &$winner): void {
        if ($armed && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'harness_threads')) {
            $armed = false;
            $winner = Thread::query()->create(['scope' => 'admin-review']);
        }
    });
    $resolved = Thread::shared('admin-review');
    expect($winner)->not->toBeNull()
        ->and($resolved->getKey())->toBe($winner->getKey())
        ->and(Thread::query()->count())->toBe(1);
});
