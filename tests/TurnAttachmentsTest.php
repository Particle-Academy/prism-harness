<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Prism\Harness\Exceptions\UnacceptableAttachment;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\Participant;

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
});

function attachmentFixture(): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/openai/text-reply.json');
}

it('sends an image with the prompt, stores it, and replays it on the next turn', function (): void {
    Http::fake(['*' => Http::response(attachmentFixture())]);
    $ada = Participant::create(['name' => 'Ada']);

    app(PrismHarness::class)->for($ada)->session('chat')->send('What is in this?', null, [Image::fromBase64('UE5HQllURVM=', 'image/png')]);
    app(PrismHarness::class)->for($ada->fresh())->session('chat')->send('And again?');

    // Measured on what LEFT the process, not on the objects built: the image
    // has to reach the provider on the turn it was attached AND on the turn
    // after, which only happens if the thread stored it.
    $bodies = collect(Http::recorded())->map(fn (array $pair): string => (string) $pair[0]->body())->all();

    expect($bodies)->toHaveCount(2)
        ->and($bodies[0])->toContain('UE5HQllURVM=')
        ->and($bodies[1])->toContain('UE5HQllURVM=');

    $stored = Thread::query()->where('scope', 'chat')->firstOrFail()->storedMessages()->orderBy('position')->first();
    expect($stored->type)->toBe('user')
        ->and($stored->toPrismMessage())->toBeInstanceOf(UserMessage::class)
        ->and($stored->toPrismMessage()->images()[0]->base64())->toBe('UE5HQllURVM=');
});

it('streams a turn with an attachment and records it', function (): void {
    $fake = Prism::fake([TextResponseFake::make()->withText('seen')]);
    $ada = Participant::create(['name' => 'Ada']);

    foreach (app(PrismHarness::class)->for($ada)->session('chat')->stream('Describe it', null, [Document::fromText('The brief.', 'Brief')]) as $event) {
    }

    $fake->assertRequest(function (array $requests): void {
        $sent = $requests[0]->messages();
        expect(end($sent))->toBeInstanceOf(UserMessage::class)
            ->and(end($sent)->documents()[0]->documentTitle())->toBe('Brief');
    });

    $stored = Thread::query()->where('scope', 'chat')->firstOrFail()->storedMessages()->orderBy('position')->first();
    expect($stored->toPrismMessage()->documents()[0]->rawContent())->toBe('The brief.');
});

it('admits bytes, a provider file id and chunks', function (): void {
    // Through the fake, because whether a PROVIDER accepts chunks is the
    // provider's rule (OpenAI refuses them, Anthropic takes them). What this
    // pins is that the harness passes all three on rather than refusing any.
    $fake = Prism::fake([TextResponseFake::make()->withText('read')]);
    $ada = Participant::create(['name' => 'Ada']);

    app(PrismHarness::class)->for($ada)->session('chat')->send('Read these', null, [
        Image::fromRawContent('PNGBYTES', 'image/png'),
        Document::fromFileId('file_123', 'Report'),
        Document::fromChunks(['One.', 'Two.'], 'Chunked'),
    ]);

    $fake->assertRequest(function (array $requests): void {
        $sent = $requests[0]->messages();
        $turn = end($sent);

        expect($turn->images())->toHaveCount(1)
            ->and($turn->documents())->toHaveCount(2);
    });
});

it('refuses an attachment it will not send, before any run or request exists', function (callable $attachment, string $code, string $prompt): void {
    Http::fake(['*' => Http::response(attachmentFixture())]);
    Storage::fake();
    Storage::put('uploads/secret.png', 'SECRET');
    $ada = Participant::create(['name' => 'Ada']);
    $session = app(PrismHarness::class)->for($ada)->session('chat');

    try {
        $session->send($prompt, null, [$attachment()]);
        $this->fail('The attachment was admitted.');
    } catch (UnacceptableAttachment $refused) {
        expect($refused->code())->toBe($code);
    }

    // Nothing reached the PROVIDER. The fetched-url row makes its own request to
    // example.com while building the media, before the harness is called.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.openai.com'));
    expect(Thread::query()->where('scope', 'chat')->first()?->storedMessages()->count() ?? 0)->toBe(0);
})->with([
    'a url, even the metadata endpoint' => [fn (): Image => Image::fromUrl('http://169.254.169.254/latest/meta-data/'), 'attachment_by_reference', 'Look'],
    // Holds bytes AND a url. A provider that accepts URLs sends the url, so the
    // question is where the media came from, not whether bytes are in hand.
    'a url that was fetched' => [fn (): Image => Image::fromUrl('https://example.com/a.png')->fetchUrlContent(), 'attachment_by_reference', 'Look'],
    'a local path' => [fn (): Image => Image::fromLocalPath(__DIR__.'/Fixtures/openai/text-reply.json', 'image/png'), 'attachment_by_reference', 'Look'],
    'a storage path' => [fn (): Image => Image::fromStoragePath('uploads/secret.png'), 'attachment_by_reference', 'Look'],
    'not media' => [fn (): Text => new Text('hello'), 'attachment_not_media', 'Look'],
    'a string' => [fn (): string => 'UE5HQllURVM=', 'attachment_not_media', 'Look'],
    'empty base64' => [fn (): Image => Image::fromBase64('', 'image/png'), 'attachment_empty', 'Look'],
    'nothing at all' => [fn (): Image => new Image, 'attachment_empty', 'Look'],
    'an empty prompt' => [fn (): Image => Image::fromBase64('UE5HQllURVM=', 'image/png'), 'attachment_without_prompt', ''],
]);

it('refuses a streamed attachment the same way', function (): void {
    Http::fake(['*' => Http::response(attachmentFixture())]);
    $ada = Participant::create(['name' => 'Ada']);

    expect(function () use ($ada): void {
        foreach (app(PrismHarness::class)->for($ada)->session('chat')->stream('Look', null, [Image::fromUrl('http://169.254.169.254/')]) as $event) {
        }
    })->toThrow(UnacceptableAttachment::class);

    Http::assertNothingSent();
});
