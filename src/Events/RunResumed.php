<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A run parked for approval continued under its own id (G-284).
 *
 * Dispatched INSTEAD of a second {@see RunStarted}: the run started once, and
 * an application counting starts would otherwise see two runs where there is
 * one. The run still ends with exactly one RunFinished or RunFailed.
 */
final readonly class RunResumed extends HarnessEvent
{
    public function __construct(
        string $sessionKey,
        string $runId,
        public string $mode,
        public string $provider,
        public string $model,
        ?string $parentRunId = null,
        ?string $rootRunId = null,
    ) {
        parent::__construct($sessionKey, $runId, $parentRunId, $rootRunId);
    }
}
