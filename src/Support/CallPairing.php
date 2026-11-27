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
 * - A result answers ONE call: the first call with its id (and its tool
 *   name, when one matches) that no earlier row answered, else the first not
 *   yet taken in the same row. A row may add one result to a step (an ended
 *   call) or repeat every result of it (a resume records them all again).
 * - A request is issued for ONE call: the first call not yet paired that has
 *   its id and that no result of the step's own run answered (a call that
 *   ran was never waiting on a person, so an ungated call is never given an
 *   approval). Only a call whose id no other call shares may be paired
 *   although it has a result, which is the pairing by id. A request left over
 *   pairs with nothing. Core issues requests in call order, one per gated call.
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
                // A call no earlier row answered first: a row that adds one
                // result (an ended call, a resolved approval) adds it to a
                // call still open, while a row that repeats every result of
                // the step (a resume) still answers each call once.
                $open = $taken + array_filter($answered);
                $at = self::firstFree($calls, $open, $id, $name) ?? self::firstFree($calls, $taken, $id, $name)
                    ?? self::firstFree($calls, $open, $id, null) ?? self::firstFree($calls, $taken, $id, null);
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
            // A call that ran was never waiting on a person. Only when no
            // other call shares the id may the request name a call that has
            // a result (the pairing is then the same as by id).
            $sharing = count(array_filter($calls, static fn (array $call): bool => $call[0] === $want));
            foreach ($sharing === 1 ? [true, false] : [true] as $waitingOnly) {
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
