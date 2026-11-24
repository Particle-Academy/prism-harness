<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A workflow run's shared budget refused its first call (G-348).
 *
 * Dispatched once per run, however many nodes are refused after it: the run's
 * ledger is cancelled with this reason, so every later charge point refuses
 * with the same words and nothing further is announced.
 *
 * Not a {@see HarnessEvent}: a workflow run is not a session run and has no
 * session key. `rootRunId` is what every session turn inside the workflow
 * carries on its own events, so the two streams join on it.
 */
final readonly class WorkflowBudgetExhausted
{
    public function __construct(
        /** The engine's run key, or the key the Harness created for a run that had none. */
        public string $runKey,
        public string $rootRunId,
        public string $reason,
        public int $steps,
        public int $toolCalls,
        public float $costUsd,
    ) {}
}
