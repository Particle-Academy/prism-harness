<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A workflow node was refused a tool call.
 *
 * Carries the tool and a short reason (`arguments`, `approval`,
 * `authorization` or `check_failed`), and never the arguments.
 *
 * @deprecated Listen for {@see ToolDenied}, which is emitted for the same
 *             refusal with `decidedBy` `policy` and the same `reason`. This
 *             event is still emitted beside it for the 0.27 line and will be
 *             removed in a later minor release.
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
