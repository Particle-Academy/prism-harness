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
 * Only what is new is added: a tool result message holding the results that
 * were missing, and no decision. The messages already there are left as they
 * are, so nothing stored is repeated. Core reads every tool result message
 * after an assistant step as one, and the thread replays consecutive tool
 * result rows as one message, so every call is still answered.
 *
 * @internal
 */
final class ResolvedApprovals
{
    /**
     * The messages with one tool result message added at the end, holding
     * the results in $resolved for the last assistant step's own tool calls
     * that have no result yet, and only when decisions were recorded after
     * it; null when there is nothing to add.
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
        $results = [];
        $responses = [];
        foreach ($messages as $index => $message) {
            if ($index > $assistant && $message instanceof ToolResultMessage) {
                $results = [...$results, ...$message->toolResults];
                $responses = [...$responses, ...$message->toolApprovalResponses];
            }
        }
        // Only what core's resolution produces: results for this assistant
        // step's own calls that had no result yet, after decisions were made
        // on it. Anything else (a tool result of a later step that arrived
        // early, a call of another turn) is not a resolution.
        if ($responses === []) {
            return null;
        }
        $known = [];
        foreach ($results as $result) {
            $known[$result->toolCallId] = true;
        }
        $own = [];
        $last = $messages[$assistant];
        if ($last instanceof AssistantMessage) {
            foreach ($last->toolCalls as $call) {
                $own[$call->id] = true;
            }
        }
        $new = array_values(array_filter($resolved, fn (ToolResult $result): bool => isset($own[$result->toolCallId]) && ! isset($known[$result->toolCallId])));
        if ($new === []) {
            return null;
        }

        return [...$messages, new ToolResultMessage($new)];
    }
}
