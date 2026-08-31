<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A run ended by throwing.
 *
 * Only the exception CLASS travels, for the reason SubagentRunner gives: a
 * provider message can carry a request URL or a key embedded in one, and an
 * event is broadcast to listeners that may put it on a screen.
 */
final readonly class RunFailed extends HarnessEvent
{
    public function __construct(
        string $sessionKey,
        string $runId,
        public string $exception,
        ?string $parentRunId = null,
        ?string $rootRunId = null,
    ) {
        parent::__construct($sessionKey, $runId, $parentRunId, $rootRunId);
    }
}
