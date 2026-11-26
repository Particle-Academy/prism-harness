<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * What core's approval resolution would have written back, when it stopped
 * before writing it.
 *
 * Core resolves a resumed run's approved calls one by one and merges their
 * results into the history only once every call is resolved. When a turn ends
 * part-way (a tool that throws its own exception, a stream the consumer
 * abandons), the calls already run have no result in the record, and the next
 * resume runs them again. merged() builds the message core would have built
 * for the results resolved so far, so the harness can record it.
 *
 * @internal
 */
final class ResolvedApprovals
{
    /**
     * The messages with $resolved merged into the tool results after the last
     * assistant tool call, as core merges them: every tool result message
     * after that call is folded into one, at the end; null when there is
     * nothing to merge.
     *
     * @param  list<Message>  $messages
     * @param  list<ToolResult>  $resolved
     * @return list<Message>|null
     */
    public static function merged(array $messages, array $resolved): ?array
    {
        $assistant = null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
                $assistant = $i;

                break;
            }
        }
        if ($resolved === [] || $assistant === null) {
            return null;
        }
        $kept = [];
        $results = [];
        $responses = [];
        foreach ($messages as $index => $message) {
            if ($index > $assistant && $message instanceof ToolResultMessage) {
                $results = [...$results, ...$message->toolResults];
                $responses = [...$responses, ...$message->toolApprovalResponses];

                continue;
            }
            $kept[] = $message;
        }
        $known = [];
        foreach ($results as $result) {
            $known[$result->toolCallId] = true;
        }
        $new = array_values(array_filter($resolved, fn (ToolResult $result): bool => ! isset($known[$result->toolCallId])));
        if ($new === []) {
            return null;
        }
        $kept[] = new ToolResultMessage([...$results, ...$new], $responses);

        return $kept;
    }
}
