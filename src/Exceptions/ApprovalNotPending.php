<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use InvalidArgumentException;
use Prism\Harness\Contracts\HasErrorCode;

/**
 * Thrown when a decision names an approval the session is not waiting on (G-354).
 *
 * A decision is an answer to a question the run asked. An id the run never
 * issued, or one already answered, answers nothing: accepted, it closed a
 * pending call nobody had decided, or started a model turn with no new user
 * message. It is refused before any provider call, any write to the thread
 * and any change to a pending approval.
 *
 * The code is `approval_not_pending`, spelled as in the ports (G-387 round
 * 13): a decision on a step a later row superseded, on an approval already
 * decided, or on one never issued. A batch with one such decision records
 * none of them.
 */
final class ApprovalNotPending extends InvalidArgumentException implements HasErrorCode
{
    public function code(): string
    {
        return 'approval_not_pending';
    }

    public static function forId(string $approvalId): self
    {
        return new self("No pending approval has the id [{$approvalId}]. It was never issued, or it has already been answered.");
    }

    /** The approval belongs to a run a person cancelled: it is settled, and the run never resumes (G-285). */
    public static function cancelled(string $approvalId): self
    {
        return new self("The approval [{$approvalId}] is settled: the run that asked for it was cancelled.");
    }
}
