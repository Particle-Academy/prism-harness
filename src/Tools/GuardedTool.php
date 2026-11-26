<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Closure;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;

/**
 * A tool whose calls are put to an authorization check first (see
 * AuthorizedTool). It stands in for the tool exactly (see DelegatingTool):
 * an allowed call reaches the tool's own handle() with core's arguments, and
 * its return value, ToolError or exception comes back unchanged.
 *
 * @internal
 */
final class GuardedTool extends DelegatingTool
{
    /** @var Closure(array<string, mixed>): (string|null) */
    private Closure $refusal;

    /**
     * @param  Closure(array<string, mixed>): (string|null)  $refusal  the refusal to return in place of the call, or null to allow it
     */
    public static function of(Tool $tool, Closure $refusal): ?Tool
    {
        if (! self::canWrap($tool)) {
            return null;
        }
        $guarded = new self;
        $guarded->adopt($tool);
        $guarded->refusal = $refusal;

        return $guarded;
    }

    public function handle(...$args): string|ToolOutput|ToolError
    {
        /** @var array<string, mixed> $arguments */
        $arguments = $args;

        return ($this->refusal)($arguments) ?? $this->inner->handle(...$args);
    }
}
