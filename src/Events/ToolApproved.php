<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/** A person approved the call. */
final readonly class ToolApproved extends ToolDecision
{
    public function type(): string
    {
        return 'tool_approved';
    }
}
