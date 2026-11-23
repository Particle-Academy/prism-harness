<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/** The call is waiting on a person. */
final readonly class ToolApprovalAsked extends ToolDecision
{
    public function type(): string
    {
        return 'tool_approval_asked';
    }
}
