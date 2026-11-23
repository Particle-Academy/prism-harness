<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Prism\Prism\ValueObjects\ToolCall;

/**
 * Reads a tool call's arguments for a record without failing on unusable ones.
 *
 * Core gained the method this relies on after the oldest version this package
 * supports, so it is looked up rather than called directly.
 */
final class ToolCallArguments
{
    public static function recordable(ToolCall $call): ToolCall
    {
        $recordable = self::callIfPresent($call, 'withoutMalformedArguments');

        return $recordable instanceof ToolCall ? $recordable : $call;
    }

    private static function callIfPresent(object $target, string $method): mixed
    {
        return method_exists($target, $method) ? $target->{$method}() : null;
    }
}
