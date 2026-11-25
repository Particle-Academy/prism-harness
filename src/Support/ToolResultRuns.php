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
 * A result is keyed by its tool call id, and a later row's result for the same
 * call replaces an earlier one, in the earlier one's position. A decision is
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
            foreach ($message->toolResults as $result) {
                $results[$result->toolCallId] = $result;
            }

            foreach ($message->toolApprovalResponses as $decision) {
                $decisions[$decision->approvalId] = $decision;
            }
        }

        return new ToolResultMessage(array_values($results), array_values($decisions));
    }
}
