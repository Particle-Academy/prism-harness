<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A run ended because a person cancelled it (G-285). Terminal: the run never
 * resumes, and any approval it was waiting on reads as settled-cancelled.
 *
 * Fired INSTEAD of {@see RunFinished} or {@see RunFailed}, never beside them,
 * and always after its {@see RunCancelRequested}. A run inside a cancelled run
 * tree (a subagent's child, a workflow node) ends with this event too.
 */
final readonly class RunCancelled extends HarnessEvent
{
    public function __construct(
        string $sessionKey,
        string $runId,
        ?string $parentRunId = null,
        ?string $rootRunId = null,
        ?string $harnessAgent = null,
        public ?string $turnId = null,
    ) {
        parent::__construct($sessionKey, $runId, $parentRunId, $rootRunId, $harnessAgent);
    }
}
