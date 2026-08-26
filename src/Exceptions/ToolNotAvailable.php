<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when a flow node asks for a tool this invoker was not given, or when
 * the tool it was given failed.
 *
 * Both are failures rather than results. A model handed "unknown tool" as a
 * tool RESULT treats it as information and tries another name, spending the
 * step budget guessing at an allowlist it cannot see — and the workflow author,
 * who made the actual mistake, sees a run that merely produced a poor answer.
 * Failing names the wrong tool to the person who wrote it down.
 */
final class ToolNotAvailable extends RuntimeException
{
    /**
     * @param  list<string>  $available
     */
    public static function named(string $tool, array $available): self
    {
        $offered = $available === []
            // Worth distinguishing: an invoker with no tools at all is almost
            // always a wiring mistake, and reading "available: " with nothing
            // after it sends people looking at the node instead of the binding.
            ? 'this invoker was given no tools at all'
            : 'available: '.implode(', ', $available);

        return new self("The flow asked for the tool [{$tool}], which was not offered to it ({$offered}).");
    }

    public static function reported(string $tool, string $message): self
    {
        // Distinct wording from failed(): this tool did not crash, it returned
        // a failure it chose to describe. Reading "threw" in a trace for a tool
        // that returned cleanly sends people looking for a stack that does not
        // exist.
        return new self("The tool [{$tool}] reported a failure while a flow node was running it: {$message}");
    }

    public static function failed(string $tool, Throwable $previous): self
    {
        return new self(
            "The tool [{$tool}] threw while a flow node was running it: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
