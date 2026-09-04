<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Enums\TaskState;
use RuntimeException;

/**
 * Thrown when a task cannot be moved to `done` or `failed`.
 *
 * `done` and `failed` are TERMINAL, and re-releasing one is an error rather
 * than a silent no-op. The no-op is the tempting choice — the caller wanted the
 * task finished and it is finished — and it is wrong for the same reason
 * everywhere else in this package: two releases of one task means two things
 * believed they owned it, and swallowing the second hides that permanently.
 * The first release already recorded an outcome; the second is either a
 * duplicate worker or a bug in the caller, and both are worth knowing about.
 *
 * Three separate constructors rather than one message, per decision 0020: a
 * caller that cannot tell "already finished" from "never claimed" from "not in
 * this list" writes one handler for three causes with three different fixes.
 */
final class TaskNotReleasable extends RuntimeException implements HasErrorCode
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

    public static function alreadyResolved(string $id, TaskState $state): self
    {
        return new self(
            "The task [{$id}] is already [{$state->value}], and terminal states do not move again. "
            .'Something released it once already: either a second worker believed it held this task, or '
            .'the same worker released it twice. Both are worth finding, which is why this is an error '
            .'rather than a silent no-op. To run the work again, add a new task — a failed task does not '
            .'return to the queue on its own, because automatic retry needs backoff and attempt counts '
            .'and that is the scheduler this package must not become.',
            'task_already_terminal',
        );
    }

    public static function notClaimed(string $id): self
    {
        return new self(
            "The task [{$id}] is [todo], so there is no claim to release. Either it was never claimed, or "
            .'the claim on it EXPIRED and the task returned to the queue — which is what a lease is for, and '
            .'means another worker may hold it now. Claim it before releasing it.',
            // The same code the lease guard uses, because it is the same fact:
            // nobody holds a lease on this task. A caller branching on failures
            // wants "you do not hold this" as one case, not two.
            'task_lease_not_held',
        );
    }

    /**
     * The same code the lease guard uses, because it is the same fact: this
     * worker does not hold this task.
     *
     * The holder is NAMED here, unlike in the refusal the completion tool hands
     * back. This message is read by a developer in a stack trace; that one is
     * read by the model, which is exactly who should not be told which other
     * worker holds a task it was refused.
     */
    public static function heldByAnother(string $id, ?string $holder, string $worker): self
    {
        return new self(
            "The task [{$id}] is held by [".($holder ?? 'nobody')."], not by [{$worker}], so it may not be "
            .'released by this worker. A release by anyone but the holder overwrites a live claim: the '
            .'usual way to arrive here is a worker whose lease lapsed mid-task, whose task another worker '
            .'has since claimed and is still working on. Worker ids are compared exactly, byte for byte.',
            'task_lease_not_held',
        );
    }

    public static function unknown(string $id, string $source): self
    {
        return new self(
            "The task [{$id}] is not in this [{$source}]. A task may only be released through the source it "
            .'was claimed from: ids are unique within a source, not across them.',
            'task_not_found',
        );
    }
}
