<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Generator;
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
 * A later row's result for the same call replaces an earlier one, in the
 * earlier one's position. The same call is the same tool call id, tool name
 * AND arguments (the n-th such result of a row replacing the n-th of an
 * earlier row), never the call id alone: a provider may give two calls of one
 * step the same id, and each keeps its own result. A decision is keyed by its
 * approval id, and the one that stands is the same in every row: a denial,
 * when any decision recorded for that approval is one, and otherwise the first
 * approval (G-401). A later approval never replaces a denial, and a denial
 * revokes an approval no run has acted on yet.
 */
class ToolResultRuns
{
    /**
     * @param  iterable<int, Message>  $messages
     * @param  array<int, int>  $answers  message index => index of the step that
     *                                    harness result row answers (G-407)
     * @return Generator<int, Message>
     */
    public static function fold(iterable $messages, array $answers = []): Generator
    {
        $messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        $final = self::finalDecisions($messages);

        yield from self::oncePerSegment(array_map(
            static fn (Message $message): Message => $message instanceof ToolResultMessage ? self::withFinalDecisions($message, $final) : $message,
            iterator_to_array(self::foldRuns(self::withAnswersInPlace($messages, $answers)), false),
        ));
    }

    /**
     * `$messages` with each harness result row that names an earlier step
     * moved to the end of that step's rows, so it is read as that step's,
     * not as a later step's (G-407). A mark that does not name an earlier
     * tool-call step with a call of each of its results' ids and tool names
     * is ignored: the row stays where it is.
     *
     * @param  list<Message>  $messages
     * @param  array<int, int>  $answers
     * @return list<Message>
     */
    private static function withAnswersInPlace(array $messages, array $answers): array
    {
        $moved = [];
        foreach ($answers as $index => $target) {
            $row = $messages[$index] ?? null;
            $step = $messages[$target] ?? null;
            if (! $row instanceof ToolResultMessage || ! $step instanceof AssistantMessage || $step->toolCalls === [] || $target >= $index) {
                continue;
            }
            $calls = [];
            foreach ($step->toolCalls as $call) {
                $calls[self::ownerKey($call->id, $call->name)] = true;
            }
            foreach ($row->toolResults as $result) {
                if (! isset($calls[self::ownerKey($result->toolCallId, $result->toolName)])) {
                    continue 2;
                }
            }
            // The end of the step's rows: just before the next tool-call step.
            $end = null;
            for ($i = $target + 1; $i < $index; $i++) {
                if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
                    $end = $i;

                    break;
                }
            }
            if ($end !== null) {
                $moved[$index] = $end;
            }
        }
        if ($moved === []) {
            return $messages;
        }

        $out = [];
        foreach ($messages as $index => $message) {
            foreach (array_keys($moved, $index, true) as $from) {
                $out[] = $messages[$from];
            }
            if (! isset($moved[$index])) {
                $out[] = $message;
            }
        }

        return $out;
    }

    /**
     * @param  iterable<int, Message>  $messages
     * @return Generator<int, Message>
     */
    private static function foldRuns(iterable $messages): Generator
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
     * Each call's result EXACTLY ONCE in its step's segment (the rows after
     * an assistant step with tool calls, up to the next one), also across
     * result rows that other rows separate, such as a new message's user
     * row before core's resolution row. A later result for the same call
     * replaces the earlier one in the earlier one's position, as within a
     * run. A result that answers none of the step's calls (by id and tool
     * name) is not replayed: a result counts only for the step whose rows it
     * sits in. A row left with no result and no decision is dropped.
     *
     * @param  list<Message>  $messages
     * @return list<Message>
     */
    private static function oncePerSegment(array $messages): array
    {
        /** @var array<string, array{0: int, 1: int}> $placed  call key => [message index, result index] */
        $placed = [];
        /** @var array<int, list<ToolResult>> $results */
        $results = [];
        /** @var array<string, true>|null $owners  the segment's calls, by id and tool name */
        $owners = null;
        foreach ($messages as $index => $message) {
            if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
                $placed = [];
                $owners = [];
                foreach ($message->toolCalls as $call) {
                    $owners[self::ownerKey($call->id, $call->name)] = true;
                }

                continue;
            }
            if (! $message instanceof ToolResultMessage) {
                continue;
            }
            $results[$index] = [];
            $seen = [];
            foreach ($message->toolResults as $result) {
                if ($owners !== null && ! isset($owners[self::ownerKey($result->toolCallId, $result->toolName)])) {
                    // Not a result of this step's calls: it is not replayed.
                    continue;
                }
                $same = self::callKey($result);
                $seen[$same] = ($seen[$same] ?? 0) + 1;
                $key = $same.'#'.$seen[$same];
                if (isset($placed[$key])) {
                    [$at, $position] = $placed[$key];
                    $results[$at][$position] = $result;

                    continue;
                }
                $placed[$key] = [$index, count($results[$index])];
                $results[$index][] = $result;
            }
        }

        $out = [];
        foreach ($messages as $index => $message) {
            if (! $message instanceof ToolResultMessage) {
                $out[] = $message;

                continue;
            }
            $kept = $results[$index];
            if ($kept === [] && $message->toolApprovalResponses === []) {
                continue;
            }
            $out[] = $kept === $message->toolResults ? $message : new ToolResultMessage($kept, $message->toolApprovalResponses);
        }

        return $out;
    }

    /**
     * The decision that stands for each approval across all of `$messages`
     * (G-401): a denial, when any decision recorded for it is one, and
     * otherwise the first approval.
     *
     * @param  list<Message>  $messages
     * @return array<string, ToolApprovalResponse>
     */
    private static function finalDecisions(array $messages): array
    {
        $final = [];
        foreach ($messages as $message) {
            if (! $message instanceof ToolResultMessage) {
                continue;
            }
            foreach ($message->toolApprovalResponses as $decision) {
                $standing = $final[$decision->approvalId] ?? null;
                if ($standing === null || ($standing->approved && ! $decision->approved)) {
                    $final[$decision->approvalId] = $decision;
                }
            }
        }

        return $final;
    }

    /**
     * `$message` with one decision per approval, the one that stands.
     *
     * @param  array<string, ToolApprovalResponse>  $final
     */
    private static function withFinalDecisions(ToolResultMessage $message, array $final): ToolResultMessage
    {
        $decisions = [];
        foreach ($message->toolApprovalResponses as $decision) {
            $decisions[$decision->approvalId] ??= $final[$decision->approvalId] ?? $decision;
        }
        $decisions = array_values($decisions);
        if ($decisions === $message->toolApprovalResponses) {
            return $message;
        }

        return new ToolResultMessage($message->toolResults, $decisions);
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

    /** A call's id and tool name, length-prefixed. */
    private static function ownerKey(string $id, string $name): string
    {
        return strlen($id).':'.$id.'|'.strlen($name).':'.$name;
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
