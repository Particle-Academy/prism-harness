<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunLedger;
use RuntimeException;

/**
 * Thrown when a worker may not push its lease out any further.
 *
 * A worker MAY extend its own lease, and only while it STILL HOLDS IT, bounded
 * by the run's remaining wall-clock budget. Both halves of that sentence are
 * load-bearing:
 *
 *  - Unbounded self-extension is how a wedged worker holds a task forever.
 *    A lease that can be renewed indefinitely by the thing being leased is not
 *    a lease; it is a lock with extra steps.
 *  - The bound is the EXISTING {@see RunBudget}, read
 *    through {@see RunLedger::exhaustion()} and
 *    {@see RunLedger::remainingSeconds()}. A second
 *    timeout invented here would be the duplicated limit this ecosystem keeps
 *    warning about — two numbers for one idea, one of them enforced.
 */
final class LeaseNotExtendable extends RuntimeException implements HasErrorCode
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    #[\Override]
    public function code(): string
    {
        return $this->errorCode;
    }

    public static function budgetExhausted(string $id, string $reason): self
    {
        return new self(
            "The lease on task [{$id}] may not be extended: {$reason}. Extension is bounded by the run's own "
            .'allowance rather than by a second timeout, so it stops exactly when the run does. The task '
            .'stays claimed until its current lease expires, and then returns to the queue for another '
            .'worker — which is the recovery this design wants, not a failure.',
            // NOT a task-specific code. The refusal came from the run's budget,
            // which is the same refusal {@see RunNotPermitted} carries
            // everywhere else — and a consumer that handles "this run may not
            // spend again" should handle it here without learning a second
            // spelling. The exception TYPE differs because the caller is
            // extending a lease; the CODE is what is pinned.
            'run_not_permitted',
        );
    }

    /**
     * The same fact {@see TaskNotReleasable::unknown()} reports, and the same
     * CODE — but this type, so that one `catch` around an extension covers
     * every way an extension can be refused. Types are how a caller writes the
     * handler; the code is what the handler branches on. See decision 0004.
     */
    public static function unknown(string $id, string $source): self
    {
        return new self(
            "The task [{$id}] is not in this [{$source}], so there is no lease on it to extend.",
            'task_not_found',
        );
    }

    public static function notHeld(string $id, TaskState $state): self
    {
        return new self(
            "The lease on task [{$id}] may not be extended: the task is [{$state->value}], not [claimed]. "
            .'A worker may only extend a lease it still holds. If the task reads [todo], the lease already '
            .'expired and the work is claimable by anyone — extending it now would take it back from '
            .'whoever holds it.',
            'task_lease_not_held',
        );
    }

    public static function heldByAnother(string $id, ?string $holder, string $worker): self
    {
        // The holder is NAMED, and the comparison that failed is exact. A
        // worker id differing only by a trailing space is a different worker
        // here, deliberately: normalising ids to be forgiving is how two
        // distinct workers end up sharing one claim.
        return new self(
            "The lease on task [{$id}] is held by [".($holder ?? 'nobody')."], not by [{$worker}], so "
            .'this worker may not extend it. Worker ids are compared exactly, byte for byte.',
            'task_lease_not_held',
        );
    }
}
