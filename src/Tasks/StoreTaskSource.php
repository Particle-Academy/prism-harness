<?php

declare(strict_types=1);

namespace Prism\Harness\Tasks;

use Closure;
use Illuminate\Support\Carbon;
use Prism\Harness\Contracts\AgentTask;
use Prism\Harness\Contracts\AgentTaskSource;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Enums\TaskOutcome;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Exceptions\InvalidTaskIdentifier;
use Prism\Harness\Exceptions\LeaseNotExtendable;
use Prism\Harness\Exceptions\TaskNotReleasable;
use Prism\Harness\Exceptions\UnsafeStateConfiguration;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Stores\DatabaseSessionStore;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunLedger;

/**
 * A task list in the harness's own durable store. The default, and the one that
 * works with no schema and no migration.
 *
 * WHY THE WHOLE LIST IS ONE STORED VALUE. Every state transition here has to be
 * atomic against every other one, and the store contract offers exactly one
 * atomicity primitive: {@see SessionStore::withLock()} on a key. Holding the
 * list under a single key means one lock covers claim, release and extension —
 * the alternative, a key per task, would need a lock per task plus a second
 * one over the ordering, which is a distributed transaction hand-rolled on top
 * of a key-value store. The cost is that this source is sized for the list an
 * agent works through in a run, not for a work queue with a million rows. That
 * is the right size: a queue is what this must not become.
 *
 * WHAT MAKES A DEAD WORKER RECOVERABLE. A claim carries an owner AND an expiry.
 * Expiry is applied ON READ, the same way {@see DatabaseSessionStore}
 * expires a payload, so an abandoned claim cannot be served as live merely
 * because nothing has swept it yet — there is no sweeper, and a design that
 * needed one would lose the list the first time the sweeper was not deployed.
 */
final class StoreTaskSource implements AgentTaskSource
{
    /**
     * @param  SessionStore  $store  Must be durable. Checked here, not later.
     * @param  string  $list  Names this list; two lists in one store never mix.
     * @param  int  $leaseSeconds  Default lease. See DEFAULT_LEASE_SECONDS.
     * @param  int  $lockWaitSeconds  How long to wait for another worker to
     *                                finish its own claim before giving up.
     */
    public function __construct(
        private readonly SessionStore $store,
        private readonly string $list = 'default',
        private readonly int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
        private readonly int $lockWaitSeconds = 5,
    ) {
        // Refused at construction rather than at the first claim. A task list
        // is durable state by definition — see the exception — and finding out
        // when a deploy has already taken the list is finding out too late.
        if (! $store->durability()->isDurable()) {
            throw UnsafeStateConfiguration::volatileTaskList($list, $store::class);
        }

        if ($leaseSeconds < 1) {
            throw UnsafeStateConfiguration::unusableLease($leaseSeconds);
        }
    }

    /**
     * Put a task on the list. Appended, because ORDER IS INSERTION ORDER.
     *
     * @param  string|null  $id  Generated when absent. Supply one when the
     *                           application already has a stable identifier for
     *                           this unit of work, so a retry can be recognised.
     */
    public function add(string $instruction, ?string $id = null): TaskRecord
    {
        if ($id === '') {
            throw InvalidTaskIdentifier::blank('id');
        }

        return $this->transact(function (array $records) use ($instruction, $id): array {
            $record = new TaskRecord(
                id: $id ?? 'task_'.bin2hex(random_bytes(8)),
                instruction: $instruction,
            );

            if ($this->indexOf($records, $record->id) !== null) {
                throw InvalidTaskIdentifier::duplicate($this->list, $record->id);
            }

            $records[] = $record;

            return [$records, $record];
        });
    }

    #[\Override]
    public function claim(string $worker, ?int $leaseSeconds = null): ?TaskRecord
    {
        $worker = $this->requireWorker($worker);
        $lease = $this->requireLease($leaseSeconds);

        // ONE CALL, UNDER ONE LOCK. Reading the next task and marking it taken
        // as two calls is the race this class exists to prevent: both workers
        // read the same `todo` row, both write their own name on it, both
        // succeed, and one instruction is executed twice with nothing anywhere
        // reporting a problem.
        return $this->transact(function (array $records) use ($worker, $lease): array {
            foreach ($records as $index => $record) {
                if ($record->state !== TaskState::Todo) {
                    continue;
                }

                $claimed = $record->heldBy($worker, $this->now() + $lease);
                $records[$index] = $claimed;

                // WRITTEN BEFORE THE WORK BEGINS — the caller has not been
                // handed the task yet. Written after, a worker that dies
                // mid-task is indistinguishable from one that never started,
                // and the list cannot tell you which of those happened.
                return [$records, $claimed];
            }

            return [$records, null];
        });
    }

    #[\Override]
    public function release(AgentTask $task, TaskOutcome $outcome): void
    {
        $this->transact(function (array $records) use ($task, $outcome): array {
            $index = $this->indexOf($records, $task->id());

            if ($index === null) {
                throw TaskNotReleasable::unknown($task->id(), $this->list);
            }

            $current = $records[$index];

            // Terminal first, so the message names the real problem. A task
            // already `done` reported as "not claimed" would send someone
            // looking for a lost claim rather than for the first release.
            if ($current->state->isTerminal()) {
                throw TaskNotReleasable::alreadyResolved($current->id, $current->state);
            }

            if ($current->state !== TaskState::Claimed) {
                throw TaskNotReleasable::notClaimed($current->id);
            }

            $records[$index] = $current->resolvedAs($outcome->toState());

            return [$records, null];
        });
    }

    #[\Override]
    public function pending(): int
    {
        // Counts an expired claim as pending, because it IS: the lease lapsed,
        // the task is back in the queue, and a loop that stopped here would
        // stop with work outstanding.
        return count(array_filter(
            $this->read(),
            fn (TaskRecord $record): bool => $record->state === TaskState::Todo,
        ));
    }

    #[\Override]
    public function find(string $id): ?TaskRecord
    {
        $records = $this->read();
        $index = $this->indexOf($records, $id);

        return $index === null ? null : $records[$index];
    }

    /**
     * Push a lease out — only for the worker holding it, and only as far as the
     * RUN'S OWN REMAINING ALLOWANCE.
     *
     * There is no second timeout here and there must never be one. The stop
     * condition is already {@see RunLedger::exhaustion()} against the
     * {@see RunBudget}: cost, steps, wall-clock and cancellation, in one place
     * that is enforced. Asking it means extension stops exactly when the run
     * does, and there is nothing new to remember to enforce.
     *
     * THE NEW EXPIRY IS ALWAYS `now + granted`, EVEN WHEN THAT SHORTENS THE
     * LEASE. That case is only reachable when the existing lease already
     * outruns the run's whole remaining allowance, and pulling it in is the
     * better answer: when the run is stopped, the task returns to the queue at
     * the moment the run ends rather than minutes later. It also leaves one
     * arithmetic rule rather than a rule plus a `max()` whose effect depends on
     * how the lease was granted — and a rule with history in it is a rule three
     * languages will implement two ways.
     *
     * The alternative reading, that a method called "extend" must never
     * shorten, is a statement about the NAME. What matters is the property: a
     * lease never outlives the allowance of the run holding it.
     *
     * @throws LeaseNotExtendable when the run is out of allowance, the task is
     *                            not claimed, or another worker holds it
     */
    public function extendLease(
        AgentTask $task,
        string $worker,
        RunLedger $ledger,
        RunBudget $budget,
        ?int $leaseSeconds = null,
    ): TaskRecord {
        $worker = $this->requireWorker($worker);
        $requested = $this->requireLease($leaseSeconds);

        // Asked BEFORE the lock, so an exhausted run does not take the list
        // from the workers still entitled to it.
        $stop = $ledger->exhaustion($budget);

        if ($stop !== null) {
            throw LeaseNotExtendable::budgetExhausted($task->id(), $stop);
        }

        $remaining = $ledger->remainingSeconds($budget);
        $granted = $remaining === null ? $requested : min($requested, $remaining);

        if ($granted < 1) {
            throw LeaseNotExtendable::budgetExhausted($task->id(), 'no wall-clock budget remains');
        }

        return $this->transact(function (array $records) use ($task, $worker, $granted): array {
            $index = $this->indexOf($records, $task->id());

            if ($index === null) {
                throw LeaseNotExtendable::unknown($task->id(), $this->list);
            }

            $current = $records[$index];

            // An expired claim has already been read back as `todo` above, so
            // this branch is also how "your lease ran out while you worked" is
            // reported. A worker may only extend a lease it still holds.
            if ($current->state !== TaskState::Claimed) {
                throw LeaseNotExtendable::notHeld($current->id, $current->state);
            }

            if ($current->claimedBy !== $worker) {
                throw LeaseNotExtendable::heldByAnother($current->id, $current->claimedBy, $worker);
            }

            $extended = $current->heldBy($worker, $this->now() + $granted);
            $records[$index] = $extended;

            return [$records, $extended];
        });
    }

    /**
     * How this list is addressed in the store.
     *
     * `<list>:tasks`, SUFFIXED RATHER THAN PREFIXED, so that a list named after
     * a session lands beside that session's own state exactly the way its
     * thread does — `session:…:support:tasks` next to `session:…:support`.
     * {@see Session::tasks()} is the path that produces
     * it, and this spelling is what makes the address predictable from the
     * session rather than from this class's internals.
     */
    public function key(): string
    {
        return $this->list.':tasks';
    }

    /**
     * Every task, in insertion order, with expiry already applied.
     *
     * @return list<TaskRecord>
     */
    private function read(): array
    {
        $payload = $this->store->get($this->key()) ?? [];
        $rows = $payload['tasks'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $records = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $records[] = $this->expire(TaskRecord::fromArray($row, $this->list));
            }
        }

        return $records;
    }

    /**
     * A claim whose lease has lapsed is a task in the queue again.
     *
     * `<=` and not `<`: the lease is over AT its expiry second, not one second
     * after it. The boundary is pinned because it is observable — a worker
     * extending at exactly its expiry either succeeds or is told it no longer
     * holds the task, and three languages guessing would give two answers.
     *
     * A `claimed` record with NO expiry is treated as expired rather than
     * honoured. That state cannot be produced by this class, so meeting one
     * means the stored value was edited or written by something else; honouring
     * it would wedge the task permanently, which is the one outcome a lease
     * exists to make impossible.
     */
    private function expire(TaskRecord $record): TaskRecord
    {
        if ($record->state !== TaskState::Claimed) {
            return $record;
        }

        if ($record->claimedUntil !== null && $record->claimedUntil > $this->now()) {
            return $record;
        }

        // BACK TO `todo`, NEVER TO `failed`. A worker dying is not the task
        // failing, and recording it as a failure burns a retry that never ran.
        return $record->releasedToQueue();
    }

    /**
     * Read, mutate and write the list under one exclusive lock.
     *
     * The callback receives the expired-and-current records and returns
     * `[$records, $result]`. Everything that must not interleave goes through
     * here, so there is exactly one place where the list is read and written
     * and exactly one lock protecting it.
     *
     * @template TReturn
     *
     * @param  Closure(list<TaskRecord>): array{0: list<TaskRecord>, 1: TReturn}  $callback
     * @return TReturn
     */
    private function transact(Closure $callback): mixed
    {
        return $this->store->withLock(
            $this->key(),
            function () use ($callback): mixed {
                // Read INSIDE the lock. A list read before acquiring it is a
                // stale list, and acting on a stale read is the whole thing the
                // lock is for.
                [$records, $result] = $callback($this->read());

                $this->write($records);

                return $result;
            },
            waitSeconds: $this->lockWaitSeconds,
        );
    }

    /**
     * @param  list<TaskRecord>  $records
     */
    private function write(array $records): void
    {
        // No TTL. A task list that expires on its own is a task list that
        // reports "nothing left to do" for a reason no reader could see.
        $this->store->put($this->key(), [
            'tasks' => array_map(fn (TaskRecord $record): array => $record->toArray(), $records),
        ]);
    }

    /**
     * @param  list<TaskRecord>  $records
     */
    private function indexOf(array $records, string $id): ?int
    {
        foreach ($records as $index => $record) {
            if ($record->id === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A worker id must identify ONE worker.
     *
     * Blank ids are refused because an empty owner is indistinguishable from no
     * owner at a glance, and every worker that failed to identify itself would
     * share one claim. Ids are otherwise taken VERBATIM and never trimmed: a
     * trailing space makes a different worker, which fails closed at extension
     * time, whereas normalising to be forgiving merges two workers into one
     * holder and fails open.
     */
    private function requireWorker(string $worker): string
    {
        // COMPARED AGAINST THE EMPTY STRING EXACTLY, WITH NO TRIM. Trimming
        // first would be the friendlier check and it is the wrong one: each
        // language strips a different codepoint set, so a worker id of U+00A0
        // would be refused in one port and accepted in another. See
        // InvalidTaskIdentifier.
        if ($worker === '') {
            throw InvalidTaskIdentifier::blank('worker id');
        }

        return $worker;
    }

    private function requireLease(?int $leaseSeconds): int
    {
        if ($leaseSeconds === null) {
            return $this->leaseSeconds;
        }

        if ($leaseSeconds < 1) {
            throw UnsafeStateConfiguration::unusableLease($leaseSeconds);
        }

        return $leaseSeconds;
    }

    /**
     * Wall-clock, through Carbon so a test can travel and so the whole package
     * reads one clock.
     */
    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }
}
