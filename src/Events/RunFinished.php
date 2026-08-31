<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A run reached an end state.
 *
 * `$finishReason` is carried verbatim rather than reduced to success/failure,
 * because `pause` — a run waiting on a human — is neither, and an application
 * that cannot tell it apart from `stop` will either lose the prompt to approve
 * or report finished work as stalled.
 */
final readonly class RunFinished extends HarnessEvent
{
    public function __construct(
        string $sessionKey,
        string $runId,
        public string $finishReason,
        public int $steps,
        public bool $awaitingApproval,
        ?string $parentRunId = null,
        ?string $rootRunId = null,
    ) {
        parent::__construct($sessionKey, $runId, $parentRunId, $rootRunId);
    }
}
