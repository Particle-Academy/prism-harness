<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Prism\Harness\Exceptions\UnmappableContent;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Artifact;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Turns a Prism message into a storable array and back again.
 *
 * Faithfulness here is the whole job. A thread is replayed to the model as
 * context, so anything this mapper drops does not surface as an error — it
 * surfaces as a model that has forgotten something, which is far harder to
 * trace back to its cause. Tool calls and tool results matter most: lose them
 * and a conversation interrupted mid-tool-loop cannot be resumed at all.
 */
final class MessageMapper
{
    public const TYPE_SYSTEM = 'system';

    public const TYPE_USER = 'user';

    public const TYPE_ASSISTANT = 'assistant';

    public const TYPE_TOOL_RESULT = 'tool_result';

    public static function typeOf(Message $message): string
    {
        return match (true) {
            $message instanceof SystemMessage => self::TYPE_SYSTEM,
            $message instanceof UserMessage => self::TYPE_USER,
            $message instanceof AssistantMessage => self::TYPE_ASSISTANT,
            $message instanceof ToolResultMessage => self::TYPE_TOOL_RESULT,
            default => throw UnmappableContent::unknownMessageType($message::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(Message $message): array
    {
        return match (true) {
            $message instanceof SystemMessage => [
                'content' => $message->content,
            ],
            $message instanceof UserMessage => [
                'content' => $message->content,
                'additional_content' => array_map(
                    ContentPartMapper::toArray(...),
                    self::authoredParts($message),
                ),
                'additional_attributes' => $message->additionalAttributes,
            ],
            $message instanceof AssistantMessage => [
                'content' => $message->content,
                'tool_calls' => array_map(fn (ToolCall $c): array => $c->toArray(), $message->toolCalls),
                'additional_content' => $message->additionalContent,
                'tool_approval_requests' => array_map(
                    fn (ToolApprovalRequest $r): array => $r->toArray(),
                    $message->toolApprovalRequests,
                ),
            ],
            $message instanceof ToolResultMessage => [
                'tool_results' => array_map(fn (ToolResult $r): array => $r->toArray(), $message->toolResults),
                'tool_approval_responses' => array_map(
                    fn (ToolApprovalResponse $r): array => $r->toArray(),
                    $message->toolApprovalResponses,
                ),
            ],
            default => throw UnmappableContent::unknownMessageType($message::class),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(string $type, array $payload): Message
    {
        return match ($type) {
            self::TYPE_SYSTEM => new SystemMessage((string) ($payload['content'] ?? '')),
            self::TYPE_USER => new UserMessage(
                (string) ($payload['content'] ?? ''),
                array_map(ContentPartMapper::fromArray(...), self::arr($payload, 'additional_content')),
                self::arr($payload, 'additional_attributes'),
            ),
            self::TYPE_ASSISTANT => new AssistantMessage(
                (string) ($payload['content'] ?? ''),
                array_map(self::toolCall(...), self::arr($payload, 'tool_calls')),
                self::arr($payload, 'additional_content'),
                array_map(self::approvalRequest(...), self::arr($payload, 'tool_approval_requests')),
            ),
            self::TYPE_TOOL_RESULT => new ToolResultMessage(
                array_map(self::toolResult(...), self::arr($payload, 'tool_results')),
                array_map(self::approvalResponse(...), self::arr($payload, 'tool_approval_responses')),
            ),
            default => throw UnmappableContent::unknownMessageType($type),
        };
    }

    /**
     * The parts the caller actually supplied, without the one Prism adds.
     *
     * `UserMessage::__construct` appends `new Text($content)` to
     * `additionalContent` on every instantiation. Storing that copy and passing
     * it back would make the constructor append a SECOND one, and `text()`
     * concatenates parts — so a message's text would double on every
     * save/load cycle, compounding silently for as long as the thread lives.
     *
     * The appended part is always last, so dropping one trailing Text that
     * matches the content restores exactly what was passed in.
     *
     * @return array<int, Text|Media>
     */
    private static function authoredParts(UserMessage $message): array
    {
        $parts = $message->additionalContent;

        $lastKey = array_key_last($parts);

        if ($lastKey !== null) {
            $last = $parts[$lastKey];

            if ($last instanceof Text && $last->text === $message->content) {
                unset($parts[$lastKey]);
            }
        }

        return array_values($parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function arr(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function toolCall(array $data): ToolCall
    {
        /** @var string|array<mixed> $arguments */
        $arguments = $data['arguments'] ?? [];

        return new ToolCall(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            arguments: $arguments,
            resultId: isset($data['result_id']) ? (string) $data['result_id'] : null,
            reasoningId: isset($data['reasoning_id']) ? (string) $data['reasoning_id'] : null,
            reasoningSummary: is_array($data['reasoning_summary'] ?? null) ? $data['reasoning_summary'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function toolResult(array $data): ToolResult
    {
        /** @var array<mixed> $args */
        $args = is_array($data['args'] ?? null) ? $data['args'] : [];

        /** @var int|float|string|array<mixed>|null $result */
        $result = $data['result'] ?? null;

        return new ToolResult(
            toolCallId: (string) ($data['tool_call_id'] ?? ''),
            toolName: (string) ($data['tool_name'] ?? ''),
            args: $args,
            result: $result,
            toolCallResultId: isset($data['tool_call_result_id']) ? (string) $data['tool_call_result_id'] : null,
            artifacts: array_map(self::artifact(...), is_array($data['artifacts'] ?? null) ? $data['artifacts'] : []),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function artifact(array $data): Artifact
    {
        return new Artifact(
            data: (string) ($data['data'] ?? ''),
            mimeType: (string) ($data['mime_type'] ?? ''),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            id: isset($data['id']) ? (string) $data['id'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function approvalRequest(array $data): ToolApprovalRequest
    {
        return new ToolApprovalRequest(
            approvalId: (string) ($data['approval_id'] ?? ''),
            toolCallId: (string) ($data['tool_call_id'] ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function approvalResponse(array $data): ToolApprovalResponse
    {
        return new ToolApprovalResponse(
            approvalId: (string) ($data['approval_id'] ?? ''),
            approved: (bool) ($data['approved'] ?? false),
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
        );
    }
}
