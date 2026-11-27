<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\Tools\DelegatingTool;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;

/**
 * A tool that tells a {@see ResolutionRecorder} what each of its calls returned.
 *
 * It stands in for the tool exactly (see DelegatingTool), and its handle()
 * reports the wrapped tool's return value after the call, unchanged. A call
 * that throws reports nothing.
 *
 * @internal
 */
final class ObservedTool extends DelegatingTool
{
    private ResolutionRecorder $recorder;

    /** The tool, watched; null when it cannot be stood in for exactly. */
    public static function of(Tool $tool, ResolutionRecorder $recorder): ?Tool
    {
        if (! self::canWrap($tool)) {
            return null;
        }
        $observed = new self;
        $observed->adopt($tool);
        $observed->recorder = $recorder;

        return $observed;
    }

    public function handle(...$args): string|ToolOutput|ToolError
    {
        // The write-ahead started row, before the call runs (G-410).
        if (! $this->recorder->starting($this->inner->name(), $args)) {
            $output = ApprovedTools::NOT_OPEN;
            $this->recorder->completed($this->inner->name(), $args, $output);

            return $output;
        }
        $output = $this->inner->handle(...$args);
        $this->recorder->completed($this->inner->name(), $args, $output);

        return $output;
    }
}
