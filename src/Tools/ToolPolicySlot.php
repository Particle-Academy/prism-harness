<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Prism\Harness\AgentRuntime;

/**
 * Where a runtime keeps the host tool policy in force (G-326).
 *
 * A separate object so the runtime can stay readonly while the host replaces
 * the policy live. Only {@see AgentRuntime} writes it.
 *
 * @internal
 */
final class ToolPolicySlot
{
    public ?ToolPolicy $policy = null;
}
