<?php

declare(strict_types=1);

namespace Prism\Harness\Streaming;

use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Rebuilds a turn's messages from the events a stream emitted.
 *
 * READ THIS BEFORE TRUSTING THE RESULT. A non-streamed run records
 * `$response->messages`, which Prism assembled itself — the authoritative
 * account of what was said. A stream emits deltas and never carries that
 * object, so a transcript recorded from a stream is RECONSTRUCTED here, and a
 * reconstruction can differ from what the same exchange would have stored
 * without streaming.
 *
 * That difference is the risk worth naming, because it is invisible: a thread
 * is replayed to a model as context, so a message assembled slightly wrong does
 * not surface as an error. It surfaces later as a model that remembers the
 * conversation differently than it happened.
 *
 * What is faithfully captured: assistant text, tool calls, and tool results.
 * What is NOT: anything a provider expressed only in a shape that has no event
 * — additional content and provider-specific fields ride on the response object
 * and are lost here. If your application needs those persisted exactly, use
 * `send()` and stream your own view of it rather than `stream()`.
 */
final class StreamRecorder
{
    private string $text = '';

    /** @var list<ToolCall> */
    private array $toolCalls = [];

    /** @var list<ToolResult> */
    private array $toolResults = [];

    public function observe(StreamEvent $event): void
    {
        match (true) {
            $event instanceof TextDeltaEvent => $this->text .= $event->delta,
            $event instanceof ToolCallEvent => $this->toolCalls[] = $event->toolCall,
            $event instanceof ToolResultEvent => $this->toolResults[] = $event->toolResult,
            default => null,
        };
    }

    /**
     * The messages to record for this turn.
     *
     * The user's prompt is included because the thread has to hold both halves
     * of an exchange: recording only the answer leaves a conversation that
     * replays as a monologue.
     *
     * @return list<UserMessage|AssistantMessage|ToolResultMessage>
     */
    public function messages(string $prompt): array
    {
        $messages = [new UserMessage($prompt)];

        // Emitted even when the text is empty, provided the model did
        // something: a turn that only called tools is still a turn, and
        // dropping it would leave the results below answering nothing.
        if ($this->text !== '' || $this->toolCalls !== []) {
            $messages[] = new AssistantMessage($this->text, $this->toolCalls);
        }

        if ($this->toolResults !== []) {
            $messages[] = new ToolResultMessage($this->toolResults);
        }

        return $messages;
    }

    public function text(): string
    {
        return $this->text;
    }
}
