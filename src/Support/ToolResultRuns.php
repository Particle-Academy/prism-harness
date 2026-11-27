<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Generator;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Consecutive tool result rows, replayed as ONE message.
 *
 * An approval leaves several: the results of the tools the stopped step did
 * run, the decision recorded by approve(), and the merged message Prism builds
 * when the run resumes. Rows are never rewritten, so all of them stay stored.
 * Replayed as they are, a provider receives the same tool output twice, and
 * Anthropic receives an empty user turn for the decision-only row, which it
 * refuses. Folded, the model sees each call's result once, as it did live.
 *
 * A later row's result for the same call replaces an earlier one, in the
 * earlier one's position. The same call is the same tool call id, tool name
 * AND arguments (the n-th such result of a row replacing the n-th of an
 * earlier row), never the call id alone: a provider may give two calls of one
 * step the same id, and each keeps its own result. A decision is
 * keyed by its approval id, the same way.
 */
class ToolResultRuns
{
    /**
     * @param  iterable<int, Message>  $messages
     * @return Generator<int, Message>
     */
    public static function fold(iterable $messages): Generator
    {
        /** @var list<ToolResultMessage> $run */
        $run = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $run[] = $message;

                continue;
            }

            if ($run !== []) {
                yield static::merge($run);
                $run = [];
            }

            yield $message;
        }

        if ($run !== []) {
            yield static::merge($run);
        }
    }

    /**
     * @param  list<ToolResultMessage>  $run
     */
    protected static function merge(array $run): ToolResultMessage
    {
        if (count($run) === 1) {
            return $run[0];
        }

        /** @var array<string, ToolResult> $results */
        $results = [];
        /** @var array<string, ToolApprovalResponse> $decisions */
        $decisions = [];

        foreach ($run as $message) {
            $seen = [];
            foreach ($message->toolResults as $result) {
                // The same call is the same id, tool name and arguments, the
                // n-th such result of a row replacing the n-th of an earlier
                // row; never the id alone.
                $same = self::callKey($result);
                $seen[$same] = ($seen[$same] ?? 0) + 1;
                $results[$same.'#'.$seen[$same]] = $result;
            }

            foreach ($message->toolApprovalResponses as $decision) {
                $decisions[$decision->approvalId] = $decision;
            }
        }

        return new ToolResultMessage(array_values($results), array_values($decisions));
    }

    /**
     * A result's call, as an unambiguous key: its id and tool name, each
     * length-prefixed, and a digest of its arguments in canonical form.
     */
    private static function callKey(ToolResult $result): string
    {
        return strlen($result->toolCallId).':'.$result->toolCallId
            .'|'.strlen($result->toolName).':'.$result->toolName
            .'|'.hash('sha256', (string) json_encode(self::canonical($result->args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** `$value` with every string-keyed array's keys sorted, recursively. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
