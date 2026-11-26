<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Prism\Telemetry\ContextStack;

/**
 * How many telemetry contexts are open on a stack. Core's stack exposes only
 * its top; the depth tells the generation a call starts (pushed directly on
 * the stack as it was before the call) from one something else starts while
 * that generation is starting (pushed above it).
 *
 * @internal
 */
final class ContextDepth extends ContextStack
{
    public static function of(ContextStack $stack): int
    {
        return count($stack->stack);
    }
}
