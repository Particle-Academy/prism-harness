<?php

declare(strict_types=1);

use Prism\Harness\Contracts\AgentTask;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Exceptions\UnmappableTask;
use Tests\Fixtures\Chore;
use Tests\Fixtures\Errand;

/*
|--------------------------------------------------------------------------
| A consumer's OWN model satisfies the contract
|--------------------------------------------------------------------------
|
| This package ships no task model, no schema and no migration. The two fixture
| tables belong to the "application" and neither was designed for this package:
| `chores` happens to use the conventional column names, `errands` uses none of
| them.
|
*/

it('adapts a model that happens to use the conventional column names', function (): void {
    $chore = Chore::create([
        'instruction' => 'Rotate the logs',
        'state' => 'todo',
    ]);

    expect($chore)->toBeInstanceOf(AgentTask::class)
        ->and($chore->id())->toBe((string) $chore->getKey())
        ->and($chore->instruction())->toBe('Rotate the logs')
        ->and($chore->state())->toBe(TaskState::Todo);
});

it('adapts a model whose columns are named nothing like the convention', function (): void {
    // The override is one small method per column, not a migration. If this
    // needed the consumer to rename a column, the package would be shipping a
    // schema after all.
    $errand = Errand::create([
        'ref' => 'ERR-9',
        'body' => 'Post the ledger',
        'status' => 'claimed',
        'holder' => 'worker-a',
        'lease_ends_at' => 1_767_225_600,
    ]);

    expect($errand->id())->toBe('ERR-9')
        ->and($errand->instruction())->toBe('Post the ledger')
        ->and($errand->state())->toBe(TaskState::Claimed)
        ->and($errand->taskClaimedBy())->toBe('worker-a')
        ->and($errand->taskClaimedUntil())->toBe(1_767_225_600);
});

it('reads every one of the four states and nothing else', function (): void {
    $read = 0;

    foreach (TaskState::cases() as $state) {
        $chore = Chore::create(['instruction' => 'x', 'state' => $state->value]);

        expect($chore->state())->toBe($state);

        $read++;
    }

    // FOUR, counted. "Every one of the four" is the claim in the name, and a
    // loop inside one test is invisible to a test-id listing — so the count is
    // asserted rather than assumed, and a fifth state added without a thought
    // for this trait moves it.
    expect($read)->toBe(4);

    // A fifth value is NOT defaulted to todo. Read as todo, a task the
    // application considers finished is handed back to a worker and run again;
    // read as done, unfinished work is stranded. Which of those it silently
    // became would depend on the default, so there is no safe default.
    $unknown = Chore::create(['instruction' => 'x', 'state' => 'in_progress']);

    expect(fn (): TaskState => $unknown->state())
        ->toThrow(UnmappableTask::class, 'in_progress');
});

it('fails on a missing instruction rather than handing the model an empty one', function (): void {
    // An empty instruction is ANSWERED by a model, not rejected by it. The
    // failure would surface as a strange answer rather than as an error.
    $chore = new Chore(['state' => 'todo']);

    expect(fn (): string => $chore->instruction())
        ->toThrow(UnmappableTask::class, 'taskInstructionColumn');

    // The control: with the column populated it reads cleanly.
    $chore->instruction = 'Rotate the logs';
    expect($chore->instruction())->toBe('Rotate the logs');
});

it('names the override that would fix a column it cannot read', function (): void {
    // An error that only says "no" sends someone hunting — and the fix here is
    // a method on their model, which is not guessable from "column missing".
    $chore = new Chore(['instruction' => 'x']);

    try {
        $chore->state();
        $this->fail('Expected the missing state column to be refused.');
    } catch (UnmappableTask $e) {
        expect($e->getMessage())->toContain('taskStateColumn')
            ->toContain(Chore::class)
            ->toContain('state');
    }
});

it('reads a datetime-cast lease column as an integer timestamp', function (): void {
    // A `datetime` cast is the normal thing for a consumer to have, and Carbon
    // is what comes back. Date FORMATTING is where three languages produce
    // three strings from one instant, so the record carries the integer.
    $chore = Chore::create([
        'instruction' => 'x',
        'state' => 'claimed',
        'claimed_by' => 'worker-a',
        'claimed_until' => '2026-01-01 00:00:00',
    ]);

    expect($chore->taskClaimedUntil())->toBeInt()
        ->and($chore->taskClaimedUntil())->toBe($chore->claimed_until->getTimestamp());
});

it('reads an unclaimed model as present-and-null, never absent', function (): void {
    // Decision 0002: absent versus null is observable, and a model that has
    // never been claimed by anything has no claim columns populated.
    $chore = Chore::create(['instruction' => 'x', 'state' => 'todo']);

    expect($chore->taskClaimedBy())->toBeNull()
        ->and($chore->taskClaimedUntil())->toBeNull()
        ->and(array_keys($chore->taskRecord()->toArray()))
        ->toBe(['claimed_by', 'claimed_until', 'id', 'instruction', 'state']);
});

it('produces the same canonical record from a model as from the store', function (): void {
    // The two adapters are two spellings of ONE contract. If a consumer's own
    // model produced a different record, the corpus would be pinning the
    // store-backed source and nothing else.
    $chore = Chore::create(['instruction' => 'Summarise the report', 'state' => 'todo']);

    expect($chore->taskRecord()->toCanonicalJson())->toBe(sprintf(
        '{"claimed_by":null,"claimed_until":null,"id":"%s","instruction":"Summarise the report","state":"todo"}',
        $chore->getKey(),
    ));
});

it('refuses a lease column it cannot read as a time', function (): void {
    $errand = new Errand(['ref' => 'ERR-1', 'body' => 'x', 'status' => 'claimed']);
    $errand->lease_ends_at = 'next tuesday';

    // Not parsed. Parsing an unformatted date string is where one column
    // becomes three instants; a consumer whose column is a string should cast
    // it on the model, where the format is known.
    expect(fn (): ?int => $errand->taskClaimedUntil())
        ->toThrow(UnmappableTask::class, 'lease_ends_at');
});
