<?php

declare(strict_types=1);

namespace Prism\Harness\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Contracts\AgentTask;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Exceptions\UnmappableTask;
use Prism\Harness\Tasks\TaskRecord;

/**
 * Makes a consumer's OWN Eloquent model an {@see AgentTask}.
 *
 *     class Chore extends Model implements AgentTask
 *     {
 *         use IsAgentTask;
 *     }
 *
 * THIS PACKAGE SHIPS NO TASK MODEL, NO SCHEMA AND NO MIGRATION, and this trait
 * is how that promise is kept for an application that already has a `tasks`
 * table with six months of rows in it. What a task IS belongs to the consumer;
 * only the four states and the claim protocol belong here.
 *
 * CONVENTIONAL COLUMNS, WITH PER-METHOD OVERRIDES. The defaults below are what
 * an application that has not thought about it will have written anyway; a
 * column called something else is one small method, not a migration:
 *
 *     protected function taskInstructionColumn(): string
 *     {
 *         return 'body';
 *     }
 *
 * A missing or unreadable value FAILS rather than defaulting. An absent
 * instruction defaulted to `''` is handed to a model, which answers it; an
 * unrecognised state defaulted to `todo` hands finished work back to a worker.
 * Neither reports anything at the time, and both are found much later in the
 * output rather than in a log.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements AgentTask
 */
trait IsAgentTask
{
    public function id(): string
    {
        $value = $this->taskAttribute($this->taskIdColumn());

        if (is_int($value) || (is_string($value) && $value !== '')) {
            return (string) $value;
        }

        throw UnmappableTask::missingColumn(static::class, $this->taskIdColumn(), 'taskIdColumn');
    }

    public function instruction(): string
    {
        $value = $this->taskAttribute($this->taskInstructionColumn());

        if (! is_string($value)) {
            throw UnmappableTask::missingColumn(static::class, $this->taskInstructionColumn(), 'taskInstructionColumn');
        }

        return $value;
    }

    public function state(): TaskState
    {
        $value = $this->taskAttribute($this->taskStateColumn());

        // An Eloquent `enum` cast hands back the case itself. Accepted, because
        // a consumer that casts the column has done the more careful thing and
        // should not be punished for it by being told the column is missing.
        if ($value instanceof TaskState) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value) || $value === '') {
            throw UnmappableTask::missingColumn(static::class, $this->taskStateColumn(), 'taskStateColumn');
        }

        $state = TaskState::tryFrom($value);

        if (! $state instanceof TaskState) {
            throw UnmappableTask::unknownState(static::class, $this->taskStateColumn(), $value);
        }

        return $state;
    }

    /**
     * The canonical five-key record for this model.
     *
     * The claim columns are read HERE and not exposed as contract methods,
     * because {@see AgentTask} is three methods and a model that has never been
     * claimed by anything has no such columns. Absent reads as null, which is
     * the value the canonical record carries — present and null, never absent.
     */
    public function taskRecord(): TaskRecord
    {
        return new TaskRecord(
            id: $this->id(),
            instruction: $this->instruction(),
            state: $this->state(),
            claimedBy: $this->taskClaimedBy(),
            claimedUntil: $this->taskClaimedUntil(),
        );
    }

    public function taskClaimedBy(): ?string
    {
        $value = $this->taskAttribute($this->taskClaimedByColumn());

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The lease expiry as an INTEGER UNIX TIMESTAMP.
     *
     * A `datetime` cast, a raw integer and a null are all ordinary things to
     * find in a consumer's column, so all three are read. A string date is not
     * parsed: parsing an unformatted date is where three languages produce
     * three instants from one column, and a consumer whose column is a string
     * should cast it on the model where the format is known.
     */
    public function taskClaimedUntil(): ?int
    {
        $value = $this->taskAttribute($this->taskClaimedUntilColumn());

        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        throw UnmappableTask::unreadableTimestamp(static::class, $this->taskClaimedUntilColumn());
    }

    /**
     * Read one column, AS AN ATTRIBUTE AND NEVER AS A RELATION.
     *
     * `getAttribute()` would be the obvious call and it recurses to death here.
     * The contract's methods are named after the conventional columns —
     * `instruction()` for `instruction` — and when the attribute is ABSENT,
     * Eloquent falls through to `getRelationValue()`, which resolves a relation
     * by calling the method of the same name. That method is this trait's, and
     * it asks for the attribute again. The stack ends in an exhausted memory
     * limit rather than a message naming the missing column, and it only
     * happens on models where the column is missing — which is exactly the case
     * this trait exists to report clearly.
     *
     * `getAttributeValue()` reads attributes, casts and accessors and stops
     * there.
     */
    protected function taskAttribute(string $column): mixed
    {
        return $this->getAttributeValue($column);
    }

    protected function taskIdColumn(): string
    {
        return $this->getKeyName();
    }

    protected function taskInstructionColumn(): string
    {
        return 'instruction';
    }

    protected function taskStateColumn(): string
    {
        return 'state';
    }

    protected function taskClaimedByColumn(): string
    {
        return 'claimed_by';
    }

    protected function taskClaimedUntilColumn(): string
    {
        return 'claimed_until';
    }
}
