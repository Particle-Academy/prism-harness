<?php

declare(strict_types=1);

namespace Prism\Harness\Enums;

use Prism\Harness\Exceptions\TaskNotReleasable;

/**
 * Where one agent task stands. FOUR STATES, AND NO OTHERS.
 *
 *         claim()                    release(done)
 *   todo ---------> claimed -------------------------> done
 *    ^                 |            release(failed)
 *    |                 +-------------------------------> failed
 *    |                 |
 *    +-----------------+
 *       lease expires
 *
 * Every edge above is an OBSERVABLE DECISION and is pinned across all three
 * languages. Two of them are worth saying in words, because both are easy to
 * get subtly wrong and neither errors when you do:
 *
 *  - `claimed` is written BEFORE the work begins, so "started and died" is
 *    distinguishable from "never started". Written after, a crash looks
 *    identical to a task nobody ever picked up.
 *  - AN EXPIRED CLAIM RETURNS TO `todo`, NEVER TO `failed`. A worker dying is
 *    not the task failing, and conflating the two burns a retry that never ran.
 *
 * `done` and `failed` are terminal. Re-releasing one is an error rather than a
 * silent no-op — see {@see TaskNotReleasable}.
 */
enum TaskState: string
{
    /** Claimable by anyone. The state an expired claim returns to. */
    case Todo = 'todo';

    /** Held by exactly one worker, until its lease expires or it is released. */
    case Claimed = 'claimed';

    case Done = 'done';

    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Done || $this === self::Failed;
    }
}
