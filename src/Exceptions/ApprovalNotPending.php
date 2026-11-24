<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a decision names an approval the session is not waiting on (G-354).
 *
 * A decision is an answer to a question the run asked. An id the run never
 * issued, or one already answered, answers nothing: accepted, it closed a
 * pending call nobody had decided, or started a model turn with no new user
 * message. It is refused before any provider call, any write to the thread
 * and any change to a pending approval.
 */
final class ApprovalNotPending extends InvalidArgumentException
{
    public static function forId(string $approvalId): self
    {
        return new self("No pending approval has the id [{$approvalId}]. It was never issued, or it has already been answered.");
    }
}
