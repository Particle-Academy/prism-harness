<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Generator;
use Prism\Harness\Tools\CanonicalJson;
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
 * AND recorded arguments (the n-th such result of a row replacing the n-th of
 * an earlier row), never the call id alone (G-387 round 13b): a provider may
 * give two calls of one step the same id, and keyed by id a later same-id
 * result with other arguments replaced a call's OWN result, so that call read
 * as open and was given a second outcome. Within a step's rows a result is its
 * call's by exact arguments first, and the replay keeps it so. A decision is
 * keyed by its approval id.
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
                $same = json_encode([$result->toolCallId, $result->toolName, CanonicalJson::digest($result->args)], JSON_THROW_ON_ERROR);
                $seen[$same] = ($seen[$same] ?? 0) + 1;
                $results[$same.'#'.$seen[$same]] = $result;
            }

            foreach ($message->toolApprovalResponses as $decision) {
                $decisions[$decision->approvalId] = $decision;
            }
        }

        return new ToolResultMessage(array_values($results), array_values($decisions));
    }
}
