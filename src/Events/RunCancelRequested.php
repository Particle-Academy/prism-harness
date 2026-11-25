<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A person asked for a run to stop, and the flag is now durable (G-285).
 *
 * Fired by `Session::cancel()` the moment the flag is written, in the process
 * that wrote it, and always BEFORE the run's {@see RunCancelled}. The run may
 * be executing in another process: it stops at its next step or tool, and that
 * process fires RunCancelled. A tool call already running is not interrupted.
 *
 * Carries the run's tags as `beginRun()` recorded them, so a consumer can join
 * it to the run's other events without guessing.
 */
final readonly class RunCancelRequested extends HarnessEvent
{
    public function __construct(
        string $sessionKey,
        string $runId,
        ?string $parentRunId = null,
        ?string $rootRunId = null,
        ?string $harnessAgent = null,
        public ?string $turnId = null,
        /** `running`: the run stops at its next check. `parked`: it was waiting on a person and is now settled. */
        public string $state = 'running',
    ) {
        parent::__construct($sessionKey, $runId, $parentRunId, $rootRunId, $harnessAgent);
    }
}
