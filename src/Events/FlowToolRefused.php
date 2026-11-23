<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A workflow node was refused a tool call.
 *
 * Carries the tool and a short reason, and never the arguments.
 */
final readonly class FlowToolRefused extends HarnessEvent
{
    public function __construct(
        string $sessionKey,
        string $runId,
        public string $tool,
        public string $reason,
    ) {
        parent::__construct($sessionKey, $runId);
    }
}
