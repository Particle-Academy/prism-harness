<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/** The host policy let the call run without asking. */
final readonly class ToolAutoAllowed extends ToolDecision
{
    public function type(): string
    {
        return 'tool_auto_allowed';
    }
}
