<?php

declare(strict_types=1);

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\AgentTaskSource;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Enums\TaskOutcome;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Exceptions\InvalidTaskIdentifier;
use Prism\Harness\Exceptions\LeaseNotExtendable;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\TaskNotReleasable;
use Prism\Harness\Exceptions\UnmappableTask;
use Prism\Harness\Exceptions\UnsafeStateConfiguration;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Stores\DatabaseSessionStore;
use Prism\Harness\Stores\RedisSessionStore;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunLedger;
use Prism\Harness\Tasks\StoreTaskSource;
use Prism\Harness\Tasks\TaskRecord;
use Tests\Fixtures\InterleavingStore;
use Tests\Fixtures\Participant;

function taskStore(): DatabaseSessionStore
{
    return new DatabaseSessionStore(app(DatabaseManager::class)->connection());
}

function taskList(?SessionStore $store = null, int $lease = 300, int $wait = 5, string $list = 'work'): StoreTaskSource
{
    return new StoreTaskSource(
        store: $store ?? taskStore(),
        list: $list,
        leaseSeconds: $lease,
        lockWaitSeconds: $wait,
    );
}

/** A ledger that has already been running for $elapsed seconds. */
function agedLedger(float $elapsed): RunLedger
{
    return new RunLedger('run-1', microtime(true) - $elapsed);
}

/*
|--------------------------------------------------------------------------
| Ordering — pinned, because nothing errors when it changes
|--------------------------------------------------------------------------
*/

it('hands out tasks in insertion order, not id order', function (): void {
    // The ids are deliberately out of sequence. A source that sorted — by id,
    // by hash, by whatever its storage happened to return — would pass a test
    // whose ids were already in order, and the agent would silently do the work
    // in a different sequence and produce a different result.
    $tasks = taskList();
    $tasks->add('third', 't-3');
    $tasks->add('first', 't-1');
    $tasks->add('second', 't-2');

    $claimed = [
        $tasks->claim('worker-a')?->id,
        $tasks->claim('worker-a')?->id,
        $tasks->claim('worker-a')?->id,
    ];

    expect($claimed)->toBe(['t-3', 't-1', 't-2'])
        ->and($claimed)->not->toBe(['t-1', 't-2', 't-3']);
});

it('returns null when there is nothing left to claim', function (): void {
    $tasks = taskList();
    $tasks->add('only one', 't-1');

    expect($tasks->claim('worker-a')?->id)->toBe('t-1')
        ->and($tasks->claim('worker-b'))->toBeNull();
});

it('refuses a duplicate id rather than queueing a task that can never be found', function (): void {
    $tasks = taskList();
    $tasks->add('first', 't-1');

    try {
        $tasks->add('second', 't-1');
        $this->fail('Expected the duplicate id to be refused.');
    } catch (InvalidTaskIdentifier $e) {
        // Decision 0004: the CODE is the contract and the sentence is not, so
        // a consumer branching on this failure never has to match on English.
        expect($e->code())->toBe('duplicate_task_id');
    }
});

/*
|--------------------------------------------------------------------------
| claim() is ONE atomic call — the race this design exists to prevent
|--------------------------------------------------------------------------
*/

it('never hands the same task to two workers claiming at once', function (): void {
    // The second worker runs INSIDE the first one's read, which is the window
    // a read-then-mark implementation loses a task in. See InterleavingStore.
    $store = new InterleavingStore(taskStore());

    $a = taskList($store);
    $b = taskList($store, wait: 0);

    $a->add('one', 't-1');
    $a->add('two', 't-2');

    $second = 'never ran';
    $store->duringNextRead(function () use ($b, &$second): void {
        try {
            $second = $b->claim('worker-b')?->id ?? 'nothing available';
        } catch (SessionLocked) {
            $second = 'blocked';
        }
    });

    $first = $a->claim('worker-a');

    // Blocked rather than handed the same row: the claim is one call under one
    // lock, so there is no moment where two workers can both see t-1 as free.
    expect($first?->id)->toBe('t-1')
        ->and($second)->toBe('blocked')
        // And the loser is not starved — it takes the next task once the lock
        // is free, which is the other half of the contract.
        ->and($b->claim('worker-b')?->id)->toBe('t-2');
});

it('WOULD hand the same task to two workers without the lock', function (): void {
    // THE NEGATIVE CONTROL for the test above. Without it, "two workers never
    // get the same task" is indistinguishable from "the test never managed to
    // interleave them", and a check that has only ever passed cannot be told
    // apart from one that cannot fail.
    //
    // Identical to the test above in every respect except that withLock stops
    // locking.
    $store = new InterleavingStore(taskStore(), locking: false);

    $a = taskList($store);
    $b = taskList($store, wait: 0);

    $a->add('one', 't-1');
    $a->add('two', 't-2');

    $second = 'never ran';
    $store->duringNextRead(function () use ($b, &$second): void {
        $second = $b->claim('worker-b')?->id ?? 'nothing available';
    });

    $first = $a->claim('worker-a');

    expect($first?->id)->toBe('t-1')
        ->and($second)->toBe('t-1');
});

it('writes the claim before the work begins', function (): void {
    // "Started and died" has to be distinguishable from "never started". The
    // observable form of that: another worker, reading the list through its own
    // connection the instant claim() returns, already sees the task as claimed.
    $tasks = taskList();
    $tasks->add('one', 't-1');

    $before = taskList()->find('t-1');

    $tasks->claim('worker-a');

    $after = taskList()->find('t-1');

    expect($before?->state)->toBe(TaskState::Todo)
        ->and($before?->claimedBy)->toBeNull()
        ->and($after?->state)->toBe(TaskState::Claimed)
        ->and($after?->claimedBy)->toBe('worker-a')
        ->and($after?->claimedUntil)->toBeInt();
});

it('refuses an empty worker id and does not trim anything first', function (): void {
    // An empty owner is shared by everything that failed to name itself, and
    // every worker in that position would hold the same claim.
    $tasks = taskList();
    $tasks->add('one', 't-1');
    $tasks->add('two', 't-2');

    try {
        $tasks->claim('');
        $this->fail('Expected the empty worker id to be refused.');
    } catch (InvalidTaskIdentifier $e) {
        expect($e->code())->toBe('task_identifier_blank');
    }

    // NOT TRIMMED, and these two rows are the ones that matter.
    //
    // A single ASCII space is what PHP's trim() strips, so it is what fails if
    // this check ever grows a trim(). U+00A0 is what Python's strip() takes and
    // PHP's trim() leaves — the same input, two answers, which is the G-36
    // shape: a normalisation that differs by language sitting in front of an
    // identity check. Both are accepted here, in every language, because
    // nothing is normalised at all.
    //
    // The cost is that a whitespace id is a valid, distinct worker. That
    // direction is closed: it cannot borrow anyone else's claim.
    expect($tasks->claim(' ')?->claimedBy)->toBe(' ')
        ->and($tasks->claim("\u{00A0}")?->claimedBy)->toBe("\u{00A0}");
});

it('refuses an empty task id', function (): void {
    $tasks = taskList();

    try {
        $tasks->add('one', '');
        $this->fail('Expected the empty task id to be refused.');
    } catch (InvalidTaskIdentifier $e) {
        expect($e->code())->toBe('task_identifier_blank');
    }
});

/*
|--------------------------------------------------------------------------
| The lease — what makes a dead worker recoverable
|--------------------------------------------------------------------------
*/

it('returns an expired claim to todo and lets another worker take it', function (): void {
    $tasks = taskList(lease: 300);
    $tasks->add('one', 't-1');
    $tasks->claim('worker-a');

    // Still held: the control for the assertion below. Without it, a source
    // that expired every claim instantly would pass the reclaim test.
    $this->travel(299)->seconds();
    expect($tasks->find('t-1')?->state)->toBe(TaskState::Claimed)
        ->and($tasks->claim('worker-b'))->toBeNull();

    $this->travel(2)->seconds();

    expect($tasks->find('t-1')?->state)->toBe(TaskState::Todo)
        ->and($tasks->find('t-1')?->claimedBy)->toBeNull()
        ->and($tasks->claim('worker-b')?->claimedBy)->toBe('worker-b');
});

it('returns an expired claim to todo and NEVER to failed', function (): void {
    // A worker dying is not the task failing. Conflating them burns a retry
    // that never ran — the work is recorded as attempted-and-failed when it was
    // never attempted at all.
    $tasks = taskList(lease: 60);
    $tasks->add('one', 't-1');
    $tasks->claim('worker-a');

    $this->travel(61)->seconds();

    expect($tasks->find('t-1')?->state)->toBe(TaskState::Todo)
        ->and($tasks->find('t-1')?->state)->not->toBe(TaskState::Failed);
});

it('ends the lease AT its expiry second, not after it', function (): void {
    // Pinned because it is observable and three languages guessing would give
    // two answers: a worker extending at exactly its expiry either succeeds or
    // is told it no longer holds the task.
    $this->freezeTime();

    $tasks = taskList(lease: 60);
    $tasks->add('one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $this->travel(59)->seconds();
    expect($tasks->find('t-1')?->state)->toBe(TaskState::Claimed);

    $this->travel(1)->seconds();
    expect(Carbon::now()->getTimestamp())->toBe($claimed?->claimedUntil)
        ->and($tasks->find('t-1')?->state)->toBe(TaskState::Todo);
});

it('counts an expired claim as pending', function (): void {
    $tasks = taskList(lease: 60);
    $tasks->add('one', 't-1');
    $tasks->add('two', 't-2');

    expect($tasks->pending())->toBe(2);

    $tasks->claim('worker-a');
    expect($tasks->pending())->toBe(1);

    $this->travel(61)->seconds();

    // A loop that stopped at "pending() === 1" here would stop with work
    // outstanding: the lease lapsed and the task is claimable again.
    expect($tasks->pending())->toBe(2);
});

it('counts nothing as pending once every task is terminal', function (): void {
    $tasks = taskList();
    $tasks->add('one', 't-1');

    $claimed = $tasks->claim('worker-a');
    $tasks->release($claimed, TaskOutcome::Done);

    expect($tasks->pending())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| done and failed are TERMINAL
|--------------------------------------------------------------------------
*/

it('records the outcome of a claimed task', function (): void {
    $tasks = taskList();
    $tasks->add('one', 't-1');
    $tasks->add('two', 't-2');

    $tasks->release($tasks->claim('worker-a'), TaskOutcome::Done);
    $tasks->release($tasks->claim('worker-a'), TaskOutcome::Failed);

    expect($tasks->find('t-1')?->state)->toBe(TaskState::Done)
        ->and($tasks->find('t-2')?->state)->toBe(TaskState::Failed)
        // The claim is cleared, because `claimed_by` means WHO HOLDS IT NOW and
        // a terminal task is held by nobody.
        ->and($tasks->find('t-1')?->claimedBy)->toBeNull()
        ->and($tasks->find('t-1')?->claimedUntil)->toBeNull();
});

it('errors when a terminal task is released again', function (): void {
    $tasks = taskList();
    $tasks->add('one', 't-1');
    $claimed = $tasks->claim('worker-a');

    // The positive control: the FIRST release must succeed, or "the second one
    // throws" proves nothing about the second one.
    $tasks->release($claimed, TaskOutcome::Done);
    expect($tasks->find('t-1')?->state)->toBe(TaskState::Done);

    expect(fn () => $tasks->release($claimed, TaskOutcome::Done))
        ->toThrow(TaskNotReleasable::class, 'already [done]');

    // And a different outcome is refused too — a silent no-op here would let a
    // second worker quietly overwrite the first one's answer.
    expect(fn () => $tasks->release($claimed, TaskOutcome::Failed))
        ->toThrow(TaskNotReleasable::class);

    expect($tasks->find('t-1')?->state)->toBe(TaskState::Done);
});

it('errors when a failed task is released again', function (): void {
    $tasks = taskList();
    $tasks->add('one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $tasks->release($claimed, TaskOutcome::Failed);

    expect(fn () => $tasks->release($claimed, TaskOutcome::Failed))
        ->toThrow(TaskNotReleasable::class, 'already [failed]');
});

it('errors when a task that was never claimed is released', function (): void {
    $tasks = taskList();
    $unclaimed = $tasks->add('one', 't-1');

    expect(fn () => $tasks->release($unclaimed, TaskOutcome::Done))
        ->toThrow(TaskNotReleasable::class, 'is [todo]');
});

it('errors when a task from another list is released', function (): void {
    $work = taskList(list: 'work');
    $chores = taskList(list: 'chores');
    $stranger = $chores->add('not yours', 't-1');

    expect(fn () => $work->release($stranger, TaskOutcome::Done))
        ->toThrow(TaskNotReleasable::class, 'not in this [work]');
});

it('leaves a failed task failed rather than requeueing it', function (): void {
    // A settled decision: automatic retry is a policy, policy needs backoff and
    // attempt counts, and that is the scheduler this must not become.
    $tasks = taskList(lease: 60);
    $tasks->add('fails', 't-1');
    $tasks->add('crashes', 't-2');

    $tasks->release($tasks->claim('worker-a'), TaskOutcome::Failed);
    $tasks->claim('worker-a');

    $this->travel(3600)->seconds();

    // The control sitting next to it: t-2 was CLAIMED and travelled exactly as
    // far, and it did come back. So the failed task staying put is the rule
    // being tested, not the clock failing to move.
    expect($tasks->find('t-1')?->state)->toBe(TaskState::Failed)
        ->and($tasks->find('t-2')?->state)->toBe(TaskState::Todo)
        ->and($tasks->pending())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Lease extension — bounded by the run's budget, not by a new timeout
|--------------------------------------------------------------------------
*/

it('extends a lease while the run still has wall-clock left', function (): void {
    $this->freezeTime();

    $tasks = taskList(lease: 60);
    $tasks->add('long one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $ledger = agedLedger(10.0);
    $budget = new RunBudget(maxSteps: 8, maxSeconds: 600);

    $extended = $tasks->extendLease($claimed, 'worker-a', $ledger, $budget, 300);

    expect($extended->claimedUntil)->toBe(Carbon::now()->getTimestamp() + 300)
        ->and($extended->claimedUntil)->toBeGreaterThan($claimed?->claimedUntil)
        ->and($tasks->find('t-1')?->claimedUntil)->toBe($extended->claimedUntil);
});

it('refuses to extend once the budget wall-clock is exhausted', function (): void {
    $tasks = taskList(lease: 60);
    $tasks->add('long one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $budget = new RunBudget(maxSteps: 8, maxSeconds: 60);

    // The positive control first: the SAME call, on a run that still has time,
    // succeeds. Without it this test would pass against an extendLease() that
    // refused unconditionally.
    $tasks->extendLease($claimed, 'worker-a', agedLedger(10.0), $budget, 120);

    expect(fn (): TaskRecord => $tasks->extendLease($claimed, 'worker-a', agedLedger(120.0), $budget, 120))
        ->toThrow(LeaseNotExtendable::class, 'time budget exhausted');
});

it('clamps an extension to the wall-clock the run has left', function (): void {
    $this->freezeTime();

    $tasks = taskList(lease: 10);
    $tasks->add('long one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $ledger = agedLedger(30.0);
    $budget = new RunBudget(maxSteps: 8, maxSeconds: 60);

    // Asks for five minutes with thirty seconds of run left.
    $extended = $tasks->extendLease($claimed, 'worker-a', $ledger, $budget, 300);

    $granted = $extended->claimedUntil - Carbon::now()->getTimestamp();

    expect($granted)->toBeGreaterThan(25)
        ->and($granted)->toBeLessThanOrEqual(30)
        // The point of the clamp: an unbounded self-extension is how a wedged
        // worker holds a task forever.
        ->and($granted)->toBeLessThan(300);
});

it('refuses to extend when the run has been cancelled or is out of steps', function (): void {
    // Extension stops when the run's own allowance does, in EVERY dimension the
    // budget has — not only wall-clock. That falls out of asking the existing
    // exhaustion() rather than inventing a second timeout here.
    $tasks = taskList(lease: 60);
    $tasks->add('one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $budget = new RunBudget(maxSteps: 2, maxSeconds: 600);

    $spent = agedLedger(1.0);
    $spent->recordSteps(2);

    expect(fn (): TaskRecord => $tasks->extendLease($claimed, 'worker-a', $spent, $budget, 60))
        ->toThrow(LeaseNotExtendable::class, 'step budget exhausted');

    $cancelled = agedLedger(1.0);
    $cancelled->cancel('the operator stopped this run');

    expect(fn (): TaskRecord => $tasks->extendLease($claimed, 'worker-a', $cancelled, $budget, 60))
        ->toThrow(LeaseNotExtendable::class, 'the operator stopped this run');
});

it('extends a lease when the run declares no wall-clock budget', function (): void {
    // maxSeconds is nullable, and a run with no time limit has no remaining
    // wall-clock to bound the lease with. It is bounded by everything else the
    // budget has; inventing a number here would be the second timeout this
    // design refuses.
    $this->freezeTime();

    $tasks = taskList(lease: 10);
    $tasks->add('one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $extended = $tasks->extendLease($claimed, 'worker-a', agedLedger(5.0), new RunBudget(maxSteps: 8), 120);

    expect($extended->claimedUntil)->toBe(Carbon::now()->getTimestamp() + 120);
});

it('pulls a lease IN when it outruns the whole remaining budget', function (): void {
    // The deliberate half of the rule: the new expiry is `now + granted` even
    // when that SHORTENS the lease. Only reachable when the existing lease
    // already outlives the run's entire remaining allowance — and then pulling
    // it in is what returns the task to the queue when the run stops, rather
    // than four minutes later.
    //
    // The alternative, never shortening, would make the result depend on how
    // the lease was granted rather than on one arithmetic rule. A rule with
    // history in it is a rule three languages implement two ways.
    $this->freezeTime();

    $tasks = taskList(lease: 300);
    $tasks->add('one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $extended = $tasks->extendLease(
        $claimed,
        'worker-a',
        agedLedger(30.0),
        new RunBudget(maxSteps: 8, maxSeconds: 60),
        300,
    );

    expect($extended->claimedUntil)->toBeLessThan($claimed?->claimedUntil)
        ->and($extended->claimedUntil - Carbon::now()->getTimestamp())->toBeLessThanOrEqual(30)
        // The property that matters, stated directly: a lease never outlives
        // the allowance of the run holding it.
        ->and($extended->claimedUntil - Carbon::now()->getTimestamp())->toBeGreaterThan(25);
});

it('refuses to extend a lease held by another worker', function (): void {
    $tasks = taskList(lease: 60);
    $tasks->add('one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $ledger = agedLedger(1.0);
    $budget = new RunBudget(maxSteps: 8, maxSeconds: 600);

    expect(fn (): TaskRecord => $tasks->extendLease($claimed, 'worker-b', $ledger, $budget, 60))
        ->toThrow(LeaseNotExtendable::class, 'held by [worker-a]');

    // A PADDED id is a different worker, and that is deliberate. A tool name
    // with a trailing space defeated a reservation in all three languages once
    // (G-36); the lesson taken was that normalising an identity to be forgiving
    // fails OPEN. Here the strict comparison fails closed instead.
    expect(fn (): TaskRecord => $tasks->extendLease($claimed, 'worker-a ', $ledger, $budget, 60))
        ->toThrow(LeaseNotExtendable::class);

    // The control: the actual holder can still extend, so the guard is not
    // simply refusing everyone.
    expect($tasks->extendLease($claimed, 'worker-a', $ledger, $budget, 60))->toBeInstanceOf(TaskRecord::class);
});

it('refuses to extend a lease that has already expired', function (): void {
    $tasks = taskList(lease: 60);
    $tasks->add('one', 't-1');
    $claimed = $tasks->claim('worker-a');

    $this->travel(61)->seconds();

    // The task is back in the queue and may already be held by someone else.
    // Extending now would take it from them.
    expect(fn (): TaskRecord => $tasks->extendLease($claimed, 'worker-a', agedLedger(1.0), new RunBudget(maxSteps: 8), 60))
        ->toThrow(LeaseNotExtendable::class, 'is [todo]');
});

/*
|--------------------------------------------------------------------------
| Durability — a task list is not a cache
|--------------------------------------------------------------------------
*/

it('refuses to start on a volatile store', function (): void {
    // A half-finished task list that vanishes on a deploy is indistinguishable
    // from a finished one: the run resolves the same session, finds nothing to
    // do, and reports success.
    $volatile = new RedisSessionStore(app(RedisFactory::class));

    expect(fn (): StoreTaskSource => taskList($volatile))
        ->toThrow(UnsafeStateConfiguration::class, 'volatile');
});

it('starts on a durable store', function (): void {
    // The control. Without it, "refuses a volatile store" is satisfied by a
    // class that refuses every store.
    expect(taskList(taskStore()))->toBeInstanceOf(StoreTaskSource::class);
});

it('accepts a redis the operator has asserted is persistent', function (): void {
    // Redis with AOF or RDB really is durable, and the package cannot detect
    // that — so the operator asserts it, exactly as the session slots already
    // work. The guard must follow durability, not the driver's name.
    $asserted = new RedisSessionStore(app(RedisFactory::class), durable: true);

    expect(taskList($asserted))->toBeInstanceOf(StoreTaskSource::class);
});

it('refuses a lease shorter than a second rather than clamping it', function (): void {
    // REFUSED, not clamped to 1. Clamping is the friendlier choice and it is
    // the one this repository has already been bitten by: a shipped
    // configuration that silently becomes a different configuration is how a
    // Redis default broke every fresh install while the suite stayed green.
    try {
        taskList(lease: 0);
        $this->fail('Expected the unusable lease to be refused.');
    } catch (UnsafeStateConfiguration $e) {
        expect($e->code())->toBe('unsafe_state_configuration')
            // It has to name the setting, or the reader has to guess.
            ->and($e->getMessage())->toContain('lease_seconds');
    }

    // Also refused per call, not only per source — the same rule wherever a
    // lease comes from.
    $tasks = taskList();
    $tasks->add('one', 't-1');

    expect(fn (): ?TaskRecord => $tasks->claim('worker-a', leaseSeconds: -5))
        ->toThrow(UnsafeStateConfiguration::class);

    // The control: one second is the smallest lease that can hold anything, and
    // it is accepted.
    expect(taskList(lease: 1))->toBeInstanceOf(StoreTaskSource::class);
});

it('puts task lists on the durable slot in the SHIPPED configuration', function (): void {
    // Decision 0012: the config every other test overrides is the one a
    // consumer actually receives, so something has to read the shipped file.
    $shipped = require __DIR__.'/../config/prism-harness.php';

    expect($shipped['stores']['durable'])->toBe('database')
        // Written once on the contract and read from there, so the number
        // cannot drift between the config file and the three languages.
        ->and($shipped['tasks']['lease_seconds'])->toBe(AgentTaskSource::DEFAULT_LEASE_SECONDS)
        ->and($shipped['tasks']['lease_seconds'])->toBe(300);

    // And end to end on those defaults, with nothing set up.
    $tasks = app(PrismHarness::class)->tasks('shipped');
    $tasks->add('smoke', 't-1');

    expect($tasks->claim('worker-a')?->instruction)->toBe('smoke');
});

it('refuses a task list when the harness durable slot is volatile', function (): void {
    // The outer guard: SessionStoreManager refuses the slot before the source
    // is ever constructed. Both checks exist because an application can build a
    // source by hand, and the inner one is what protects that case.
    config()->set('prism-harness.stores.durable', 'redis');

    $harness = new PrismHarness(
        stores: new SessionStoreManager(app(), config('prism-harness')),
        runtime: app(AgentRuntime::class),
        config: config('prism-harness'),
    );

    expect(fn (): StoreTaskSource => $harness->tasks('doomed'))
        ->toThrow(UnsafeStateConfiguration::class);
});

/*
|--------------------------------------------------------------------------
| The canonical record — bytes, not shape
|--------------------------------------------------------------------------
*/

it('emits the canonical JSON the spec pins', function (): void {
    $record = new TaskRecord(id: 't-1', instruction: 'Summarise the report');

    expect($record->toCanonicalJson())
        ->toBe('{"claimed_by":null,"claimed_until":null,"id":"t-1","instruction":"Summarise the report","state":"todo"}');
});

it('keeps claimed_by and claimed_until present and null when unclaimed', function (): void {
    // Decision 0002 makes absent-versus-null observable: a port modelling unset
    // as `undefined` drops the keys entirely, and the record no longer round
    // trips. Asserted on the KEYS, not only on the rendered string.
    $record = new TaskRecord(id: 't-1', instruction: 'x');

    expect(array_keys($record->toArray()))->toBe(['claimed_by', 'claimed_until', 'id', 'instruction', 'state'])
        ->and($record->toCanonicalJson())->toContain('"claimed_by":null')
        ->and($record->toCanonicalJson())->toContain('"claimed_until":null');
});

it('renders claimed_until as an integer timestamp, not a formatted date', function (): void {
    $record = new TaskRecord('t-1', 'x', TaskState::Claimed, 'worker-a', 1_767_225_600);

    expect($record->toCanonicalJson())
        ->toBe('{"claimed_by":"worker-a","claimed_until":1767225600,"id":"t-1","instruction":"x","state":"claimed"}')
        // Date formatting is exactly where three languages produce three
        // strings from one instant.
        ->and($record->toCanonicalJson())->not->toContain('2026-');
});

it('leaves slashes and non-ASCII unescaped', function (): void {
    $record = new TaskRecord('t-1', 'Read https://example.com/über and 日本語');

    $canonical = $record->toCanonicalJson();

    expect($canonical)->toContain('https://example.com/über')
        ->and($canonical)->toContain('日本語')
        // The negative control, and the reason the flags are there at all: PHP
        // is the only one of the three languages that escapes both by default,
        // so the reference emitting its own defaults would emit different bytes
        // from the corpus while every PHP test still passed.
        ->and(json_encode($record->toArray()))->not->toBe($canonical)
        ->and(json_encode($record->toArray()))->toContain('\\/');
});

it('round-trips a stored record', function (): void {
    $record = new TaskRecord('t-1', 'x', TaskState::Claimed, 'worker-a', 1_767_225_600);

    expect(TaskRecord::fromArray($record->toArray(), 'work'))->toEqual($record);
});

it('refuses to read a stored state it does not recognise', function (): void {
    // Defaulting to `todo` here would hand finished work back to a worker, and
    // nothing would report it.
    expect(fn (): TaskRecord => TaskRecord::fromArray([
        'id' => 't-1', 'instruction' => 'x', 'state' => 'in_progress',
        'claimed_by' => null, 'claimed_until' => null,
    ], 'work'))->toThrow(UnmappableTask::class, 'in_progress');

    // The control: the four it does recognise are read without complaint.
    foreach (TaskState::cases() as $state) {
        expect(TaskRecord::fromArray([
            'id' => 't-1', 'instruction' => 'x', 'state' => $state->value,
            'claimed_by' => null, 'claimed_until' => null,
        ], 'work')->state)->toBe($state);
    }
});

it('treats a claim with no expiry as expired rather than honouring it forever', function (): void {
    // Cannot be produced by this class; a stored value edited by something else
    // can be. Honouring it would wedge the task permanently, which is the one
    // outcome a lease exists to make impossible.
    taskStore()->put('work:tasks', ['tasks' => [
        ['claimed_by' => 'ghost', 'claimed_until' => null, 'id' => 't-1', 'instruction' => 'x', 'state' => 'claimed'],
    ]]);

    $tasks = taskList();

    expect($tasks->find('t-1')?->state)->toBe(TaskState::Todo)
        ->and($tasks->claim('worker-a')?->id)->toBe('t-1');
});

it('carries a stable code on every failure the state machine can raise', function (): void {
    // Decision 0004: codes are the contract, prose is not. Gathered in one
    // place so the taxonomy is visible as a taxonomy — a code is only useful if
    // three languages spell it identically, and a table is easier to compare
    // against another port than five assertions scattered through a file.
    $tasks = taskList(lease: 60);
    $tasks->add('one', 't-1');
    $tasks->add('two', 't-2');
    $claimed = $tasks->claim('worker-a');
    $unclaimed = $tasks->find('t-2');

    $codes = [];

    try {
        $tasks->add('one again', 't-1');
    } catch (InvalidTaskIdentifier $e) {
        $codes['duplicate id'] = $e->code();
    }

    try {
        $tasks->claim('');
    } catch (InvalidTaskIdentifier $e) {
        $codes['blank id'] = $e->code();
    }

    try {
        $tasks->release(new TaskRecord('t-99', 'ghost', TaskState::Claimed, 'worker-a', 1), TaskOutcome::Done);
    } catch (TaskNotReleasable $e) {
        $codes['unknown task'] = $e->code();
    }

    try {
        $tasks->release($unclaimed, TaskOutcome::Done);
    } catch (TaskNotReleasable $e) {
        $codes['never claimed'] = $e->code();
    }

    $tasks->release($claimed, TaskOutcome::Done);

    try {
        $tasks->release($claimed, TaskOutcome::Done);
    } catch (TaskNotReleasable $e) {
        $codes['already terminal'] = $e->code();
    }

    try {
        $tasks->extendLease($claimed, 'worker-a', agedLedger(1.0), new RunBudget(maxSteps: 8), 60);
    } catch (LeaseNotExtendable $e) {
        $codes['lease not held'] = $e->code();
    }

    try {
        $tasks->extendLease(new TaskRecord('t-99', 'ghost'), 'worker-a', agedLedger(1.0), new RunBudget(maxSteps: 8), 60);
    } catch (LeaseNotExtendable $e) {
        // ONE catch type per method, one code per fact: an extension refused
        // for a task that is not here reports the same code the release path
        // does, through the type an extension caller is already catching.
        $codes['extending an unknown task'] = $e->code();
    }

    $spent = agedLedger(1.0);
    $spent->cancel('stopped');

    try {
        $tasks->extendLease($claimed, 'worker-a', $spent, new RunBudget(maxSteps: 8), 60);
    } catch (LeaseNotExtendable $e) {
        $codes['budget spent'] = $e->code();
    }

    expect($codes)->toBe([
        'duplicate id' => 'duplicate_task_id',
        'blank id' => 'task_identifier_blank',
        'unknown task' => 'task_not_found',
        'never claimed' => 'task_lease_not_held',
        'already terminal' => 'task_already_terminal',
        'lease not held' => 'task_lease_not_held',
        'extending an unknown task' => 'task_not_found',
        // NOT a task code. The run's budget refused, which is the refusal
        // RunNotPermitted already carries everywhere else in this package.
        'budget spent' => 'run_not_permitted',
    ]);
});

it('codes a volatile store the same way every other unsafe state slot is coded', function (): void {
    try {
        taskList(new RedisSessionStore(app(RedisFactory::class)));
        $this->fail('Expected the volatile store to be refused.');
    } catch (UnsafeStateConfiguration $e) {
        expect($e->code())->toBe('unsafe_state_configuration');
    }
});

it('addresses a session task list beside the session itself', function (): void {
    // `<session key>:tasks`, mirroring how the thread is addressed. A restarted
    // worker resolving the same session has to find the same list, and the
    // address is what makes that true — so it is derived from the session
    // rather than chosen by the caller.
    $session = app(PrismHarness::class)->session(Participant::create(['name' => 'Ada']), 'support');

    expect($session->tasks()->key())->toBe($session->key().':tasks');

    $session->tasks()->add('write the report', 't-1');

    // And the list is really there, at that address, in the durable store.
    $stored = app(PrismHarness::class)->stores()->durable()->get($session->key().':tasks');

    expect($stored['tasks'][0]['id'])->toBe('t-1')
        ->and($session->tasks()->pending())->toBe(1);
});

it('stores claimed_until as an INTEGER, not a float', function (): void {
    // The type, not only the value. `1767225600.0 == 1767225600` is true in
    // every language here, so an equality assertion cannot tell a float from an
    // int — and a float round-trips through JSON as `1767225600.0`, which this
    // source reads back as "no expiry" and treats as an expired lease. The
    // failure is silent and the lease quietly stops holding.
    $tasks = taskList(lease: 60);
    $tasks->add('one', 't-1');
    $tasks->claim('worker-a');

    $stored = taskStore()->get('work:tasks');
    $claimedUntil = $stored['tasks'][0]['claimed_until'];

    expect($claimedUntil)->toBeInt()
        ->and($claimedUntil)->not->toBeFloat()
        // And on the wire: an integer renders with no decimal point in all
        // three languages, where a float renders three ways.
        ->and(json_encode($stored['tasks'][0]))->toContain('"claimed_until":'.$claimedUntil.',');
});

it('survives the process that wrote it', function (): void {
    // The point of all of the above: a restarted worker resolves the same list
    // and sees the same state. Two entirely separate source objects, one store.
    $first = taskList();
    $first->add('one', 't-1');
    $first->add('two', 't-2');
    $first->claim('worker-a');

    $restarted = taskList();

    expect($restarted->find('t-1')?->state)->toBe(TaskState::Claimed)
        ->and($restarted->find('t-1')?->claimedBy)->toBe('worker-a')
        ->and($restarted->pending())->toBe(1);
});
