<?php

declare(strict_types=1);

use Prism\Harness\Exceptions\UnmappableContent;
use Prism\Harness\Support\MessageMapper;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Artifact;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

function roundTrip(Message $message): Message
{
    // Through JSON as well as the mapper: the payload lands in a json column,
    // so anything that survives the mapper but not json_encode/decode would
    // still be lost in practice.
    $type = MessageMapper::typeOf($message);
    $payload = json_decode((string) json_encode(MessageMapper::toArray($message)), true);

    return MessageMapper::fromArray($type, $payload);
}

it('preserves the concrete media class', function (): void {
    // Prism's Media::toArray() records where a file lives but not what it is,
    // so an Image and a Document serialise identically. Without the class
    // discriminator the mapper writes, every attachment would come back as
    // whichever type we guessed.
    $message = new UserMessage('Look at these', [
        Image::fromUrl('https://example.com/chart.png', 'image/png'),
        Document::fromUrl('https://example.com/report.pdf', 'application/pdf'),
    ]);

    $restored = roundTrip($message);

    expect($restored->additionalContent[0])->toBeInstanceOf(Image::class)
        ->and($restored->additionalContent[1])->toBeInstanceOf(Document::class)
        ->and($restored->additionalContent[0]->url)->toBe('https://example.com/chart.png')
        ->and($restored->additionalContent[1]->url)->toBe('https://example.com/report.pdf')
        ->and($restored->text())->toBe('Look at these');
});

it('preserves every field of a tool call', function (): void {
    $message = new AssistantMessage('', [
        new ToolCall(
            id: 'call_1',
            name: 'weather',
            arguments: ['city' => 'Paris'],
            resultId: 'res_1',
            reasoningId: 'reason_1',
            reasoningSummary: ['step' => 'checked the forecast'],
        ),
    ]);

    $call = roundTrip($message)->toolCalls[0];

    expect($call->id)->toBe('call_1')
        ->and($call->name)->toBe('weather')
        ->and($call->arguments)->toBe(['city' => 'Paris'])
        ->and($call->resultId)->toBe('res_1')
        ->and($call->reasoningId)->toBe('reason_1')
        ->and($call->reasoningSummary)->toBe(['step' => 'checked the forecast']);
});

it('preserves tool result artifacts', function (): void {
    $message = new ToolResultMessage([
        new ToolResult(
            toolCallId: 'call_1',
            toolName: 'chart',
            args: ['series' => 'revenue'],
            result: 'rendered',
            toolCallResultId: 'tcr_1',
            artifacts: [new Artifact('YmluYXJ5', 'image/png', ['width' => 640], 'art_1')],
        ),
    ]);

    $result = roundTrip($message)->toolResults[0];

    expect($result->toolCallResultId)->toBe('tcr_1')
        ->and($result->artifacts)->toHaveCount(1)
        ->and($result->artifacts[0]->id)->toBe('art_1')
        ->and($result->artifacts[0]->data)->toBe('YmluYXJ5')
        ->and($result->artifacts[0]->mimeType)->toBe('image/png')
        ->and($result->artifacts[0]->metadata)->toBe(['width' => 640]);
});

it('preserves a pending approval on both sides', function (): void {
    // A half-executed tool awaiting a human is the state that most needs to
    // survive storage — it outlives the request that created it.
    $asked = roundTrip(new AssistantMessage('', [], [], [
        new ToolApprovalRequest('appr_1', 'call_1'),
    ]));

    $answered = roundTrip(new ToolResultMessage([], [
        new ToolApprovalResponse('appr_1', false, 'Too risky'),
    ]));

    expect($asked->toolApprovalRequests[0]->approvalId)->toBe('appr_1')
        ->and($asked->toolApprovalRequests[0]->toolCallId)->toBe('call_1')
        ->and($answered->toolApprovalResponses[0]->approvalId)->toBe('appr_1')
        ->and($answered->toolApprovalResponses[0]->approved)->toBeFalse()
        ->and($answered->toolApprovalResponses[0]->reason)->toBe('Too risky');
});

it('preserves user additional attributes', function (): void {
    $restored = roundTrip(new UserMessage('Hi', [], ['locale' => 'en-GB']));

    expect($restored->additionalAttributes)->toBe(['locale' => 'en-GB']);
});

it('refuses a message type it cannot store, rather than dropping it', function (): void {
    $custom = new class implements Message {};

    expect(fn (): string => MessageMapper::typeOf($custom))
        ->toThrow(UnmappableContent::class);
});

it('refuses a content part it cannot rebuild, rather than returning an empty one', function (): void {
    expect(fn (): Message => MessageMapper::fromArray('user', [
        'content' => 'Hi',
        'additional_content' => [['class' => Image::class, 'data' => []]],
    ]))->toThrow(UnmappableContent::class);
});
