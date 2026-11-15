<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

use Prism\Harness\Steps\RunStep;

/**
 * A run produced a step (G-282 A).
 *
 * Dispatched for every step of a streamed run, whether the caller iterates
 * `stream()` or `streamSteps()`. OBSERVATION ONLY: a listener that throws is
 * reported and the run carries on, because a broken renderer must never be
 * able to stop, or alter, the work it is rendering.
 */
final readonly class RunStepped extends HarnessEvent
{
    public function __construct(
        string $sessionKey,
        public RunStep $step,
    ) {
        parent::__construct($sessionKey, $step->runId, $step->parentRunId, $step->rootRunId);
    }
}
