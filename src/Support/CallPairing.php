<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Which result, and which approval request, belongs to which tool call of
 * one model step: by POSITION, never by a map keyed by the call id (G-387
 * round 13).
 *
 * A provider can give two calls in one step the same id: Gemini names a call
 * by its function, and some servers send empty ids. Keyed by id, the result
 * of an ungated call that ran read as the result of a gated call that shares
 * its id, so the gated call looked finished: the step did not park on it, a
 * runner start never ended it, and its approval was handed to the wrong call.
 *
 * - A result answers ONE call: the first call not yet answered in the same
 *   row that has its id and its tool name, else the first that has its id.
 *   Each row is matched on its own, because a row may repeat the results of
 *   an earlier one (a resume records every result of the step again).
 * - A request is issued for ONE call: the first call not yet paired that has
 *   its id and that no result of the step's own run answered (a call that
 *   ran was never waiting on a person), else the first not yet paired that
 *   has its id. Core issues requests in call order, one per gated call.
 *
 * Calls with distinct ids pair exactly as they did by id. Two calls with the
 * same id AND the same tool name are told apart by order only.
 *
 * @internal
 */
final class CallPairing
{
    /**
     * @param  list<array{0: string, 1: string}>  $calls  [id, tool name], in call order
     * @param  list<list<array{0: string, 1: string}>>  $rows  each result row's [id, tool name], in its order
     * @return list<bool> whether each call has a result of its own
     */
    public static function answered(array $calls, array $rows): array
    {
        $answered = array_fill(0, count($calls), false);
        foreach ($rows as $results) {
            $taken = [];
            foreach ($results as [$id, $name]) {
                $at = self::firstFree($calls, $taken, $id, $name) ?? self::firstFree($calls, $taken, $id, null);
                if ($at !== null) {
                    $taken[$at] = true;
                    $answered[$at] = true;
                }
            }
        }

        return $answered;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $calls  [id, tool name], in call order
     * @param  list<string>  $requestCallIds  each request's tool_call_id, in issue order
     * @param  list<bool>  $ran  which calls a result of the step's own run answered
     * @return list<int|null> the index of the call each request was issued for, or null
     */
    public static function requests(array $calls, array $requestCallIds, array $ran = []): array
    {
        $paired = [];
        $taken = [];
        foreach ($requestCallIds as $want) {
            $at = null;
            foreach ([true, false] as $waitingOnly) {
                foreach ($calls as $i => [$id]) {
                    if (! isset($taken[$i]) && $id === $want && (! $waitingOnly || ! ($ran[$i] ?? false))) {
                        $at = $i;

                        break 2;
                    }
                }
            }
            if ($at !== null) {
                $taken[$at] = true;
            }
            $paired[] = $at;
        }

        return $paired;
    }

    /**
     * Whether every call has a result of its own among `$results`.
     *
     * @param  list<ToolCall>  $calls
     * @param  list<ToolResult>  $results
     */
    public static function allAnswered(array $calls, array $results): bool
    {
        return ! in_array(false, self::answered(self::ofCalls($calls), [self::ofResults($results)]), true);
    }

    /**
     * @param  list<ToolCall>  $calls
     * @return list<array{0: string, 1: string}>
     */
    public static function ofCalls(array $calls): array
    {
        return array_map(static fn (ToolCall $call): array => [$call->id, $call->name], $calls);
    }

    /**
     * @param  list<ToolResult>  $results
     * @return list<array{0: string, 1: string}>
     */
    public static function ofResults(array $results): array
    {
        return array_map(static fn (ToolResult $result): array => [$result->toolCallId, $result->toolName], $results);
    }

    /**
     * Stored `tool_calls` (or `tool_results`, with `$id` = tool_call_id and
     * `$name` = tool_name) as [id, tool name]; a missing or odd value reads
     * as the empty string, which is an id like any other.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function ofStored(mixed $entries, string $id, string $name): array
    {
        $out = [];
        foreach (is_array($entries) ? array_values($entries) : [] as $entry) {
            if (! is_array($entry)) {
                // Kept in place, so an index is the entry's position.
                $out[] = ['', ''];

                continue;
            }
            $out[] = [is_scalar($entry[$id] ?? null) ? (string) $entry[$id] : '', is_string($entry[$name] ?? null) ? $entry[$name] : ''];
        }

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $calls
     * @param  array<int, true>  $taken
     */
    private static function firstFree(array $calls, array $taken, string $id, ?string $name): ?int
    {
        foreach ($calls as $i => [$callId, $callName]) {
            if (! isset($taken[$i]) && $callId === $id && ($name === null || $callName === $name)) {
                return $i;
            }
        }

        return null;
    }
}
