<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Generator;
use Prism\Harness\Tools\CanonicalJson;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
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
 * A later row's result for the same call is left out: the FIRST stands, in
 * its position, the result every outcome reader counts as the call's (G-387
 * round 17, decision 7; it was the later one, so the model could be told
 * an outcome no reader recorded). The same call is the same tool call id, tool name
 * AND recorded arguments (the n-th such result of a row replacing the n-th of
 * an earlier row), never the call id alone (G-387 round 13b): a provider may
 * give two calls of one step the same id, and keyed by id a later same-id
 * result with other arguments replaced a call's OWN result, so that call read
 * as open and was given a second outcome. Within a step's rows a result is its
 * call's by exact arguments first, and the replay keeps it so. A decision is
 * keyed by its approval id.
 *
 * EACH RESULT ONCE, IN ITS OWN SEGMENT (G-387 round 14): a step's segment is
 * every row after it up to the next step with tool calls, and its result rows
 * need not be consecutive. A resolution recorded after a row that is not a
 * result (a system row) carries the step's earlier results again, so a fold
 * of consecutive rows alone replayed them twice, and a provider can refuse a
 * duplicate tool result. A later run of the segment therefore leaves out
 * every result (same key, same occurrence) and decision an earlier run of it
 * already replayed; the first one stands where it was. A run left with
 * nothing is not replayed at all.
 *
 * THE DECISION THAT STOOD (G-387 round 16, item 10): each decision is
 * replayed as the one that stood for its approval when its call was
 * resolved ({@see StepRows::standing()}): a decision recorded after the call
 * had its outcome never stood, and is not replayed, nor does it replace one
 * that did.
 */
class ToolResultRuns
{
    /**
     * @param  iterable<int, Message>  $messages
     * @return Generator<int, Message>
     */
    public static function fold(iterable $messages): Generator
    {
        $messages = static::withStandingDecisions(is_array($messages) ? array_values($messages) : iterator_to_array($messages, false));
        /** @var list<ToolResultMessage> $run */
        $run = [];
        /** @var array<string, int> $replayed by result key, how many this segment replayed */
        $replayed = [];
        /** @var array<string, true> $decided by approval id, the decisions this segment replayed */
        $decided = [];
        /** @var AssistantMessage|null $step the step whose segment this is */
        $step = null;
        /** @var array<int, true> $sent by call index, the calls of `$step` this segment answered */
        $sent = [];

        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $run[] = $message;

                continue;
            }

            if ($run !== []) {
                $merged = static::answering(static::unreplayed(static::merge($run), $replayed, $decided), $step, $sent);
                if ($merged !== null) {
                    yield $merged;
                }
                $run = [];
            }

            if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
                // A new step: a new segment.
                $replayed = [];
                $decided = [];
                $step = $message;
                $sent = [];
            }

            yield $message;
        }

        if ($run !== []) {
            $merged = static::answering(static::unreplayed(static::merge($run), $replayed, $decided), $step, $sent);
            if ($merged !== null) {
                yield $merged;
            }
        }
    }

    /**
     * `$message` with only the results that answer a call of `$step` this
     * segment has not answered yet ({@see CallPairing::takers()}), each call
     * once (G-387 round 17): a result answering no call of its step (one
     * naming a call the step does not have, or a second result for a call)
     * is never sent, because no provider accepts an output for a call that
     * is not directly before it. It stays stored; nothing reads it as an
     * outcome of any other step. Results before any step are sent as they are.
     *
     * @param  array<int, true>  $sent
     */
    protected static function answering(?ToolResultMessage $message, ?AssistantMessage $step, array &$sent): ?ToolResultMessage
    {
        if ($message === null || $step === null || $message->toolResults === []) {
            return $message;
        }
        $takers = CallPairing::takers(CallPairing::ofCalls(array_values($step->toolCalls)), CallPairing::ofResults($message->toolResults), $sent);
        $calls = array_values($step->toolCalls);
        $results = [];
        $changed = false;
        foreach ($message->toolResults as $k => $result) {
            $at = $takers[$k];
            if ($at === null) {
                $changed = true;

                continue;
            }
            $sent[$at] = true;
            // Sent with the ids of the call it answers (item 4): a result
            // stored without the provider's call id (a host's, say) would
            // otherwise reach the provider with a null one.
            $call = $calls[$at];
            if ($result->toolCallId !== $call->id || $result->toolCallResultId !== $call->resultId) {
                $result = new ToolResult($call->id, $result->toolName, $result->args, $result->result, $call->resultId, $result->artifacts);
                $changed = true;
            }
            $results[] = $result;
        }
        if (! $changed) {
            return $message;
        }

        return $results === [] && $message->toolApprovalResponses === [] ? null : new ToolResultMessage($results, $message->toolApprovalResponses);
    }

    /**
     * `$messages` with every decision after a step the one that stood for its
     * approval (round 16): a decision that never stood is left out.
     *
     * @param  list<Message>  $messages
     * @return list<Message>
     */
    protected static function withStandingDecisions(array $messages): array
    {
        $n = count($messages);
        for ($s = 0; $s < $n; $s++) {
            $step = $messages[$s];
            if (! $step instanceof AssistantMessage || $step->toolCalls === []) {
                continue;
            }
            // Paired exactly as a stored step is ({@see ThreadView::pair()}).
            $results = [];
            $at = [];
            for ($i = $s + 1; $i < $n && ! ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []); $i++) {
                if ($messages[$i] instanceof ToolResultMessage) {
                    $at[] = $i;
                    $results[] = MessageMapper::toArray($messages[$i]);
                }
            }
            $standing = ThreadView::pair(MessageMapper::toArray($step), $results)['standing'];
            if ($standing === []) {
                continue;
            }
            foreach ($at as $i) {
                $message = $messages[$i];
                if (! $message instanceof ToolResultMessage) {
                    continue;
                }
                $decisions = [];
                foreach ($message->toolApprovalResponses as $response) {
                    if (! array_key_exists($response->approvalId, $standing)) {
                        $decisions[] = $response;
                    } elseif ($standing[$response->approvalId] !== null) {
                        $stood = $standing[$response->approvalId];
                        $decisions[] = new ToolApprovalResponse($stood['approval_id'], ($stood['approved'] ?? false) === true,
                            is_string($stood['reason'] ?? null) ? $stood['reason'] : null);
                    }
                }
                if ($decisions !== $message->toolApprovalResponses) {
                    $messages[$i] = new ToolResultMessage($message->toolResults, $decisions);
                }
            }
        }

        return $messages;
    }

    /**
     * `$message` without what this segment already replayed, or null when
     * that leaves nothing of a message that held something. Records what it
     * replays now.
     *
     * @param  array<string, int>  $replayed
     * @param  array<string, true>  $decided
     */
    protected static function unreplayed(ToolResultMessage $message, array &$replayed, array &$decided): ?ToolResultMessage
    {
        if ($message->toolResults === [] && $message->toolApprovalResponses === []) {
            return $message;
        }
        $seen = [];
        $results = [];
        foreach ($message->toolResults as $result) {
            $same = static::keyOf($result);
            $seen[$same] = ($seen[$same] ?? 0) + 1;
            if ($seen[$same] > ($replayed[$same] ?? 0)) {
                $results[] = $result;
            }
        }
        foreach ($seen as $same => $count) {
            $replayed[$same] = max($replayed[$same] ?? 0, $count);
        }
        $decisions = [];
        foreach ($message->toolApprovalResponses as $decision) {
            if (! isset($decided[$decision->approvalId])) {
                $decided[$decision->approvalId] = true;
                $decisions[] = $decision;
            }
        }
        if (count($results) === count($message->toolResults) && count($decisions) === count($message->toolApprovalResponses)) {
            return $message;
        }

        return $results === [] && $decisions === [] ? null : new ToolResultMessage($results, $decisions);
    }

    /** The same call: tool call id, tool name and recorded arguments, length-prefixed. */
    protected static function keyOf(ToolResult $result): string
    {
        return strlen($result->toolCallId).':'.$result->toolCallId.'|'.strlen($result->toolName).':'.$result->toolName.'|'.CanonicalJson::digest($result->args);
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
                // Length-prefixed, so no id or name can collide with another
                // pair, and no encoding step can fail on an odd value.
                $same = static::keyOf($result);
                $seen[$same] = ($seen[$same] ?? 0) + 1;
                // The FIRST result per call stands (round 17, decision 7):
                // the one every outcome reader counts.
                $results[$same.'#'.$seen[$same]] ??= $result;
            }

            foreach ($message->toolApprovalResponses as $decision) {
                $decisions[$decision->approvalId] = $decision;
            }
        }

        return new ToolResultMessage(array_values($results), array_values($decisions));
    }
}
