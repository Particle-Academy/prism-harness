<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/** The call does not run: a person denied it or never answered (`person`), or the host revoked the policy that had let it run before it started (`policy`). */
final readonly class ToolDenied extends ToolDecision
{
    public function type(): string
    {
        return 'tool_denied';
    }
}
