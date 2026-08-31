<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\Enums\Durability;

/**
 * How a nested run ended.
 *
 * More than two states, reported as more than one bit — deliberately. A parent
 * that can only see "worked / didn't" will retry a run that was refused on
 * purpose, and abandon one that failed transiently. Those are opposite correct
 * responses to the same collapsed answer, which is how {@see Durability}
 * and prism-sandbox's AwardOutcome came to exist. See decision 0020.
 */
enum SubagentOutcome: string
{
    /** The child finished its own work and produced a result. */
    case Completed = 'completed';

    /** The child stopped because the tree ran out of steps, money or time. */
    case Exhausted = 'exhausted';

    /** The tree was cancelled. Not a failure and never retryable on its own. */
    case Cancelled = 'cancelled';

    /** Authorization refused the child before it ran. */
    case Denied = 'denied';

    /** The child threw. Distinct from Exhausted: nobody chose this. */
    case Failed = 'failed';

    /**
     * The child stopped on a pending human approval.
     *
     * Explicitly NOT a failure and explicitly not retryable: retrying discards
     * the half-executed action a person was asked to authorise, which is the
     * exact loss the durable state slot exists to prevent.
     */
    case AwaitingApproval = 'awaiting_approval';

    public function succeeded(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether running it again could plausibly do better.
     *
     * Exhausted is retryable only in the sense that a LARGER budget would help,
     * so it is not retryable as-is; the caller must change something first.
     */
    public function retryable(): bool
    {
        return $this === self::Failed;
    }
}
