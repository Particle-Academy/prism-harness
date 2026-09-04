<?php

declare(strict_types=1);

namespace Prism\Harness\Tasks;

use Prism\Harness\Contracts\AgentTask;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Exceptions\UnmappableTask;

/**
 * One stored task, and the canonical form every language must agree on.
 *
 * THE CANONICAL RECORD IS FIVE KEYS, SORTED:
 *
 *     {"claimed_by":null,"claimed_until":null,"id":"t-1","instruction":"…","state":"todo"}
 *
 * Three things about that shape are decisions rather than taste, and each is
 * one a port would get wrong by accident:
 *
 *  - `claimed_by` and `claimed_until` are PRESENT AND NULL when unclaimed,
 *    never absent. Decision 0002 makes absent-versus-null observable, and a
 *    port modelling "unset" as `undefined` drops the keys entirely.
 *  - `claimed_until` is an INTEGER UNIX TIMESTAMP, not a formatted date. Date
 *    formatting is exactly where three languages produce three strings from one
 *    instant.
 *  - The JSON is encoded with slashes and non-ASCII UNESCAPED, per decision
 *    0005. PHP escapes both by default and is the only one of the three that
 *    does, so the flags below are the difference between the reference emitting
 *    the corpus's bytes and emitting its own.
 */
final readonly class TaskRecord implements AgentTask
{
    public function __construct(
        public string $id,
        public string $instruction,
        public TaskState $state = TaskState::Todo,
        public ?string $claimedBy = null,
        public ?int $claimedUntil = null,
    ) {}

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    #[\Override]
    public function instruction(): string
    {
        return $this->instruction;
    }

    #[\Override]
    public function state(): TaskState
    {
        return $this->state;
    }

    /** Who holds the claim RIGHT NOW — not who did the work. */
    public function claimedBy(): ?string
    {
        return $this->claimedBy;
    }

    /** When the claim lapses, as an integer Unix timestamp. */
    public function claimedUntil(): ?int
    {
        return $this->claimedUntil;
    }

    /**
     * The same task, claimed by this worker until this instant.
     */
    public function heldBy(string $worker, int $until): self
    {
        return new self($this->id, $this->instruction, TaskState::Claimed, $worker, $until);
    }

    /**
     * The same task, back in the queue with no holder.
     *
     * Used for an EXPIRED LEASE, which returns a task to `todo` and never to
     * `failed`. A worker dying is not the task failing.
     */
    public function releasedToQueue(): self
    {
        return new self($this->id, $this->instruction, TaskState::Todo);
    }

    /**
     * The same task in a terminal state, with the claim cleared.
     *
     * The claim is cleared because `claimed_by` means one thing — WHO HOLDS IT
     * NOW — and a terminal task is held by nobody. Leaving the holder in place
     * would give the field a second meaning (who did the work) that no reader
     * could distinguish from the first, which is the state collapse decision
     * 0020 is about. Who did the work is an audit fact and belongs in the
     * application's own record, where it can be kept for longer than a lease.
     */
    public function resolvedAs(TaskState $state): self
    {
        return new self($this->id, $this->instruction, $state);
    }

    /**
     * Read one stored row back.
     *
     * STRICT ABOUT `state`, deliberately. A value this package does not
     * recognise cannot be defaulted to `todo`: a stored `done` misspelled by a
     * hand-edit or a half-finished migration would run finished work a second
     * time, and nothing would report it. Failing here names the row instead.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row, string $source): self
    {
        $id = $row['id'] ?? null;
        $instruction = $row['instruction'] ?? null;
        $rawState = $row['state'] ?? null;
        $claimedBy = $row['claimed_by'] ?? null;
        $claimedUntil = $row['claimed_until'] ?? null;

        if (! is_string($id) || $id === '') {
            throw UnmappableTask::corruptRecord($source, 'a record has no [id]');
        }

        if (! is_string($instruction)) {
            throw UnmappableTask::corruptRecord($source, "the record [{$id}] has no [instruction]");
        }

        $state = is_string($rawState) ? TaskState::tryFrom($rawState) : null;

        if (! $state instanceof TaskState) {
            throw UnmappableTask::corruptRecord(
                $source,
                sprintf('the record [%s] holds [%s] in [state], which is not one of todo, claimed, done, failed', $id, is_string($rawState) ? $rawState : get_debug_type($rawState)),
            );
        }

        return new self(
            id: $id,
            instruction: $instruction,
            state: $state,
            claimedBy: is_string($claimedBy) ? $claimedBy : null,
            claimedUntil: is_int($claimedUntil) ? $claimedUntil : null,
        );
    }

    /**
     * @return array{claimed_by: string|null, claimed_until: int|null, id: string, instruction: string, state: string}
     */
    public function toArray(): array
    {
        // Built in sorted key order, so insertion order and sorted order are
        // the same thing here. Decision 0005 pins insertion order for corpus
        // JSON generally; this record's canonical form is spelled sorted, and
        // constructing it this way satisfies both rather than choosing one.
        return [
            'claimed_by' => $this->claimedBy,
            'claimed_until' => $this->claimedUntil,
            'id' => $this->id,
            'instruction' => $this->instruction,
            'state' => $this->state->value,
        ];
    }

    public function toCanonicalJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
