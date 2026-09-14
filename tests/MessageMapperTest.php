<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\UnmappableContent;
use Prism\Harness\Support\MessageMapper;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\ValueObjects\Artifact;
use Prism\Prism\ValueObjects\Citation;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\MessagePartWithCitations;
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
    // Before prism v0.120.0, Media::toArray() recorded where a file lived but
    // not what it was, so an Image and a Document serialised identically.
    // Without the class the mapper writes, every attachment in those older
    // rows would come back as whichever type we guessed.
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

it('round-trips the citation object Anthropic attaches to every reply', function (): void {
    // Not an edge case. Anthropic wraps EVERY assistant reply in a
    // MessagePartWithCitations, including replies that cite nothing — so a raw
    // passthrough corrupts every Anthropic message. Found by running a
    // two-turn conversation through a thread in a real app: turn two died in
    // Anthropic's MessageMap with "array given" for a value object.
    $message = new AssistantMessage('Teal noted, cool choice!', [], [
        'citations' => [
            new MessagePartWithCitations(
                outputText: 'Teal noted, cool choice!',
                citations: [
                    new Citation(
                        sourceType: CitationSourceType::Url,
                        source: 'https://example.com/a',
                        sourceText: 'teal',
                        sourceTitle: 'A page',
                    ),
                ],
            ),
        ],
    ]);

    $restored = roundTrip($message);
    $part = $restored->additionalContent['citations'][0];

    expect($part)->toBeInstanceOf(MessagePartWithCitations::class)
        ->and($part->outputText)->toBe('Teal noted, cool choice!')
        ->and($part->citations[0])->toBeInstanceOf(Citation::class)
        // The enum has to survive as an enum, not as its backing string.
        ->and($part->citations[0]->sourceType)->toBe(CitationSourceType::Url)
        ->and($part->citations[0]->source)->toBe('https://example.com/a')
        ->and($part->citations[0]->sourceTitle)->toBe('A page');
});

it('keeps plain additional content plain', function (): void {
    // Anthropic also puts scalars in here — thinking blocks and signatures.
    // Those must not acquire an envelope.
    $restored = roundTrip(new AssistantMessage('Hi', [], [
        'thinking' => 'considering',
        'thinking_signature' => 'sig_123',
    ]));

    expect($restored->additionalContent)->toBe([
        'thinking' => 'considering',
        'thinking_signature' => 'sig_123',
    ]);
});

it('refuses to store an object it could never rebuild', function (): void {
    $foreign = new class
    {
        public string $x = 'y';
    };

    expect(fn (): array => MessageMapper::toArray(new AssistantMessage('Hi', [], ['thing' => $foreign])))
        ->toThrow(UnmappableContent::class);
});

describe('media in a stored thread', function (): void {
    // What a thread with an attachment is replayed from. Prism v0.120.0 stores
    // media with its bytes, its kind and no file paths (prism-parity's
    // media-roundtrip suite); rows written before that carry paths instead.
    // Both have to replay.

    it('replays a raw-content image with its bytes', function (): void {
        $restored = roundTrip(new UserMessage('Look', [Image::fromRawContent('PNGBYTES', 'image/png')]));

        expect($restored->additionalContent[0])->toBeInstanceOf(Image::class)
            ->and($restored->additionalContent[0]->rawContent())->toBe('PNGBYTES')
            ->and($restored->additionalContent[0]->mimeType())->toBe('image/png');
    });

    it('replays a local file from its stored bytes, and stores no path to read', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'thread');
        file_put_contents($path, 'PNGBYTES');

        $message = new UserMessage('Look', [Image::fromLocalPath($path, 'image/png')]);
        $payload = json_decode((string) json_encode(MessageMapper::toArray($message)), true);

        // Gone before replay: a rebuild that still resolved the path would
        // fail here, or worse, read whatever file now has that name.
        unlink($path);

        $restored = MessageMapper::fromArray('user', $payload);

        expect(json_encode($payload))->not->toContain(basename($path))
            ->and($restored->additionalContent[0]->rawContent())->toBe('PNGBYTES');
    });

    it('replays a titled url document without turning its mime type into its title', function (): void {
        // Document's factories take the TITLE where Media's take the mime type,
        // so rebuilding through `$class::fromUrl($url, $mimeType)` named every
        // stored url document after its mime type.
        $restored = roundTrip(new UserMessage('Read', [Document::fromUrl('https://example.com/report.pdf', 'Quarterly report')]));

        expect($restored->additionalContent[0]->documentTitle())->toBe('Quarterly report')
            ->and($restored->additionalContent[0]->url)->toBe('https://example.com/report.pdf');
    });

    it('replays a text document with its text and title', function (): void {
        $restored = roundTrip(new UserMessage('Read', [Document::fromText('The whole document.', 'Notes')]));

        expect($restored->additionalContent[0]->rawContent())->toBe('The whole document.')
            ->and($restored->additionalContent[0]->documentTitle())->toBe('Notes')
            ->and($restored->additionalContent[0]->mimeType())->toBe('text/plain');
    });

    it('replays a chunked document', function (): void {
        // Chunks carry no file id, url, path or bytes, so this used to throw
        // noMediaLocator: a thread holding one could not be replayed at all.
        $restored = roundTrip(new UserMessage('Read', [Document::fromChunks(['First.', 'Second.'], 'Chunked')]));

        expect($restored->additionalContent[0]->chunks())->toBe(['First.', 'Second.'])
            ->and($restored->additionalContent[0]->documentTitle())->toBe('Chunked');
    });

    it('keeps a filename', function (): void {
        $restored = roundTrip(new UserMessage('Look', [Image::fromBase64('UE5HQllURVM=', 'image/png')->as('diamond.png')]));

        expect($restored->additionalContent[0]->filename())->toBe('diamond.png');
    });

    it('replays a url without fetching it', function (): void {
        Http::fake(['*' => Http::response('metadata')]);

        $restored = roundTrip(new UserMessage('Look', [Image::fromUrl('http://169.254.169.254/latest/meta-data/')]));

        expect($restored->additionalContent[0]->url)->toBe('http://169.254.169.254/latest/meta-data/')
            ->and($restored->additionalContent[0]->base64())->toBeNull();

        Http::assertSentCount(0);
    });

    it('still replays a row written before v0.120.0, which carries a path and no bytes', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'legacy');
        file_put_contents($path, 'LEGACYBYTES');

        try {
            $restored = MessageMapper::fromArray('user', [
                'content' => 'Old',
                'additional_content' => [[
                    'class' => Image::class,
                    'data' => [
                        'url' => null, 'base64' => null, 'mime_type' => 'image/png', 'file_id' => null,
                        'local_path' => $path, 'storage_path' => null, 'filename' => null,
                    ],
                ]],
            ]);

            expect($restored->additionalContent[0]->rawContent())->toBe('LEGACYBYTES');
        } finally {
            @unlink($path);
        }
    });
});
