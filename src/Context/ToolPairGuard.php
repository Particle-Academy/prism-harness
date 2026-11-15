<?php

declare(strict_types=1);

namespace Prism\Harness\Context;

use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * A tool call and its result are kept together, or neither is kept.
 *
 * APPLIED TO EVERY STRATEGY'S OUTPUT, including ones written outside this
 * package, and it cannot be turned off. A strategy author is deciding what a
 * conversation should look like; they should not also have to know that cutting
 * between an assistant's tool call and the message answering it produces a
 * transcript the provider refuses outright:
 *
 *     tool_use ids were found without tool_result blocks immediately after
 *
 * The failure mode is what makes this worth enforcing centrally rather than
 * documenting. It depends on where the cut happens to fall, so it fires
 * INTERMITTENTLY; it surfaces at the provider, several messages after the
 * compaction that caused it; and the error names an id rather than a turn. It
 * was hit twice in one afternoon here by two different pieces of code, which is
 * the argument that a comment would not have been enough.
 *
 * ## What it does
 *
 * Both directions, because a cut can orphan either side:
 *
 *  - a tool RESULT whose call was evicted is evicted too — it answers a
 *    question no longer in the transcript;
 *  - a tool CALL whose result was evicted is evicted too — it is a question the
 *    model asked and can no longer see answered.
 *
 * An assistant message that loses every one of its tool calls this way still
 * keeps its text, because what it SAID is a legitimate part of the conversation
 * even when the calls it made are gone.
 *
 * ## What it deliberately does not do
 *
 * It does not reorder, deduplicate, or repair anything else. A guard that
 * quietly fixed a strategy's other mistakes would make those mistakes
 * undiagnosable, and the pairing rule is the only one whose violation is both
 * certain to break the request and invisible to the author who caused it.
 */
final class ToolPairGuard
{
    /**
     * Repair an outcome so the kept transcript is valid.
     *
     * Anything the guard removes joins `evicted` rather than disappearing —
     * a message dropped for pairing reasons is exactly as worth recovering as
     * one the strategy dropped on purpose.
     */
    public function enforce(CompactionOutcome $outcome): CompactionOutcome
    {
        $keptCallIds = $this->callIds($outcome->kept);
        $keptResultIds = $this->resultIds($outcome->kept);

        // The ids that survive are the ones present on BOTH sides, plus calls still AWAITING an approval
        // decision or its execution: core resolves those from the approval response, so they have no result
        // yet by design. A call counts as awaiting only if its own message carries an approval request for it
        // AND no result exists anywhere, kept or evicted. One that already ran but lost its result to the cut is
        // still an orphan. (Without this, every approval-gated tool was stripped once compaction was on.)
        $executed = [...$keptResultIds, ...$this->resultIds($outcome->evicted)];
        $awaiting = array_values(array_diff($this->approvalCallIds($outcome->kept), $executed));
        $paired = array_values(array_unique([...array_intersect($keptCallIds, $keptResultIds), ...$awaiting]));
        $keptCallIds = array_values(array_unique($keptCallIds));
        $keptResultIds = array_values(array_unique($keptResultIds));

        $unpairedCalls = array_diff($keptCallIds, $paired);
        $unpairedResults = array_diff($keptResultIds, $paired);
        if ($unpairedCalls === [] && $unpairedResults === []) {
            return $outcome;
        }

        $kept = [];
        $evicted = $outcome->evicted;

        foreach ($outcome->kept as $message) {
            $repaired = $this->strip($message, $paired, $evicted);

            if ($repaired instanceof Message) {
                $kept[] = $repaired;
            }
        }

        return new CompactionOutcome($kept, $evicted);
    }

    /**
     * @param  list<string>  $paired
     * @param  list<Message>  $evicted  Appended to by reference.
     */
    private function strip(Message $message, array $paired, array &$evicted): ?Message
    {
        if ($message instanceof AssistantMessage) {
            $calls = array_values(array_filter(
                $message->toolCalls,
                fn (ToolCall $call): bool => in_array($call->id, $paired, true),
            ));

            if (count($calls) === count($message->toolCalls)) {
                return $message;
            }

            // The text survives even when every call is gone: what the
            // assistant SAID is part of the conversation independently of the
            // calls it made.
            if ($calls === [] && trim($message->content) === '') {
                $evicted[] = $message;

                return null;
            }

            // Approval requests follow their calls; dropping them would orphan the approval on the next send.
            $kept = array_map(static fn (ToolCall $call): string => $call->id, $calls);
            $approvals = array_values(array_filter(
                $message->toolApprovalRequests,
                static fn (ToolApprovalRequest $request): bool => in_array($request->toolCallId, $kept, true),
            ));

            return new AssistantMessage($message->content, $calls, $message->additionalContent, $approvals);
        }

        if ($message instanceof ToolResultMessage) {
            $results = array_values(array_filter(
                $message->toolResults,
                fn (ToolResult $result): bool => in_array($result->toolCallId, $paired, true),
            ));

            if (count($results) === count($message->toolResults)) {
                return $message;
            }

            if ($results === []) {
                $evicted[] = $message;

                return null;
            }

            return new ToolResultMessage($results, $message->toolApprovalResponses);
        }

        return $message;
    }

    /**
     * @param  list<Message>  $messages
     * @return list<string>
     */
    private function callIds(array $messages): array
    {
        $ids = [];

        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls as $call) {
                    $ids[] = $call->id;
                }
            }
        }

        return $ids;
    }

    /**
     * Calls that carry an approval request on their own assistant message.
     *
     * @param  list<Message>  $messages
     * @return list<string>
     */
    private function approvalCallIds(array $messages): array
    {
        $ids = [];

        foreach ($messages as $message) {
            if (! $message instanceof AssistantMessage) {
                continue;
            }
            $calls = array_map(static fn (ToolCall $call): string => $call->id, $message->toolCalls);
            foreach ($message->toolApprovalRequests as $request) {
                if (in_array($request->toolCallId, $calls, true)) {
                    $ids[] = $request->toolCallId;
                }
            }
        }

        return $ids;
    }

    /**
     * @param  list<Message>  $messages
     * @return list<string>
     */
    private function resultIds(array $messages): array
    {
        $ids = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $ids[] = $result->toolCallId;
                }
            }
        }

        return $ids;
    }
}
