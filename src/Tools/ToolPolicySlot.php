<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Prism\Harness\AgentRuntime;

/**
 * Where the host tool policy in force is kept (G-326).
 *
 * A separate object so the runtime can stay readonly while the host replaces
 * the policy live, and so the policy can be SCOPED to one request or queued
 * job while the runtime is a singleton: the service provider binds it with
 * `scoped()`. Only {@see AgentRuntime} writes it.
 *
 * @internal
 */
final class ToolPolicySlot
{
    public ?ToolPolicy $policy = null;
}
