<?php

declare(strict_types=1);

namespace Prism\Harness\Enums;

/**
 * What `Session::cancel()` did (G-285). The values are the wire strings every
 * port returns.
 */
enum CancelOutcome: string
{
    /** The cancel flag is set: the run stops at its next step or tool, or, if it was parked on an approval, is settled now. */
    case Ok = 'ok';

    /** The run belongs to this session and has already ended (finished, failed or cancelled). Nothing changed. */
    case NotRunning = 'not-running';

    /** This session has no run at all. Nothing changed. */
    case NoTurn = 'no-turn';

    /** This session's stores cannot hold a cancel another process can see. Nothing changed. */
    case Unsupported = 'unsupported';

    /** The run id is not one of this session's runs: malformed, forged, or another session's. Nothing changed. */
    case Refused = 'refused';
}
