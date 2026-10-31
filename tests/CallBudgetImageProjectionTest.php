<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Exceptions\PrismUnprojectableRequestException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Anthropic\Anthropic;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Artifact;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

function imageProjectionPrice(string $provider = 'anthropic', int $imageBound = 3000): CallBudget
{
    $run = RunContext::root('image-projection', new RunBudget(10, 10.0, maxOutputTokensPerCall: 4096), [
        $provider => ['offline-model' => ['inputPerMTok' => 1, 'outputPerMTok' => 1, 'imageTokenBound' => $imageBound]],
    ]);

    return CallBudget::forRun($run, $provider, 'offline-model', []);
}

dataset('image projection formats', [
    ['anthropic', 'responses'], ['openai', 'responses'], ['openai', 'chat_completions'],
]);

it('charges one inline image once and retains exact JSON framing in every native mode', function (string $provider, string $format, string $mode): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = $mode === 'structured' ? Prism::structured()->withSchema(new ObjectSchema('answer', 'Answer', [new StringSchema('value', 'Value')], ['value'])) : Prism::text();
    // Slashes exercise JSON escaping: subtracting the raw base64 length is incorrect.
    $base64 = base64_encode("\xff\xff\xff");
    $generation->using($provider, 'offline-model', ['api_key' => 'offline-only', 'api_format' => $format])
        ->withPrompt('Review', [Image::fromBase64($base64, 'image/png')])->withMaxTokens(4096);
    $request = $generation->toRequest();
    $before = serialize($request);
    $payload = $generation->provider()->projectPayload($request, $mode);
    $fullPayload = $payload;
    if ($provider === 'anthropic') {
        $payload['messages'][0]['content'][1]['source']['data'] = '';
    } elseif ($format === 'chat_completions') {
        $payload['messages'][0]['content'][1]['image_url']['url'] = 'data:image/png;base64,';
    } else {
        $payload['input'][0]['content'][1]['image_url'] = 'data:image/png;base64,';
    }
    expect(imageProjectionPrice($provider)->inputTokenBound($request, $generation->provider(), $mode))
        ->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + ($mode === 'structured' && $format === 'chat_completions' ? 192 : 128) + 3000)
        ->and(serialize($request))->toBe($before);
    $sent = null;
    $intercepted = new RuntimeException('Offline image request intercepted');
    Http::fake(function (HttpRequest $wire) use (&$sent, $intercepted): never {
        $sent = $wire;
        throw $intercepted;
    });
    expect(fn () => match ($mode) {
        'structured' => $generation->provider()->structured($request),
        'stream' => iterator_to_array($generation->provider()->stream($request)),
        default => $generation->provider()->text($request),
    })->toThrow($intercepted);
    expect(json_decode($sent->body(), true, flags: JSON_THROW_ON_ERROR))->toBe($fullPayload);
})->with('image projection formats')->with(['text', 'structured', 'stream']);

it('charges two identical inline images separately while retaining identical text elsewhere', function (string $provider, string $format): void {
    $base64 = base64_encode(str_repeat('same-image', 800));
    $generation = Prism::text()->using($provider, 'offline-model', ['api_key' => 'offline-only', 'api_format' => $format])
        ->withPrompt($base64, [Image::fromBase64($base64, 'image/png'), Image::fromBase64($base64, 'image/png')]);
    $request = $generation->toRequest();
    $wire = json_encode($generation->provider()->projectPayload($request, 'text'), JSON_THROW_ON_ERROR);
    $encodedBytes = strlen(json_encode($base64, JSON_THROW_ON_ERROR)) - 2;
    expect(imageProjectionPrice($provider)->inputTokenBound($request, $generation->provider()))
        ->toBe(strlen($wire) - 2 * $encodedBytes + 128 + 6000)
        ->toBeGreaterThan(strlen($base64) + 6000);
})->with('image projection formats');

it('retains full URL image bytes plus its explicit image charge', function (string $provider, string $format): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = Prism::text()->using($provider, 'offline-model', ['api_key' => 'offline-only', 'api_format' => $format])
        ->withPrompt('Review', [Image::fromUrl('https://example.invalid/image.png')]);
    $request = $generation->toRequest();
    $wire = json_encode($generation->provider()->projectPayload($request, 'text'), JSON_THROW_ON_ERROR);
    expect(imageProjectionPrice($provider)->inputTokenBound($request, $generation->provider()))->toBe(strlen($wire) + 128 + 3000);
    Http::assertNothingSent();
})->with('image projection formats');

it('retains file-id image bytes plus its explicit image charge', function (string $provider): void {
    $generation = Prism::text()->using($provider, 'offline-model', ['api_key' => 'offline-only'])
        ->withPrompt('Review', [Image::fromFileId('file-offline')]);
    $request = $generation->toRequest();
    $wire = json_encode($generation->provider()->projectPayload($request, 'text'), JSON_THROW_ON_ERROR);
    expect(imageProjectionPrice($provider)->inputTokenBound($request, $generation->provider()))->toBe(strlen($wire) + 128 + 3000);
})->with(['anthropic', 'openai']);

it('keeps omitted tool artifact charges and never excludes a base64 copy returned as text', function (string $provider, string $format, bool $textCopy): void {
    $base64 = base64_encode(str_repeat('artifact', 1000));
    $generation = Prism::text()->using($provider, 'offline-model', ['api_key' => 'offline-only', 'api_format' => $format])->withMessages([
        new UserMessage('Inspect'), new AssistantMessage('', [new ToolCall('call', 'screenshot', [], 'call')]),
        new ToolResultMessage([new ToolResult('call', 'screenshot', [], $textCopy ? $base64 : 'Done', 'call', [new Artifact($base64, 'image/png')])]),
    ]);
    $request = $generation->toRequest();
    $wire = json_encode($generation->provider()->projectPayload($request, 'text'), JSON_THROW_ON_ERROR);
    // Core 0.143 does not map the artifact. Its data appears only as billed result text.
    expect(substr_count($wire, $base64))->toBe($textCopy ? 1 : 0)
        ->and(imageProjectionPrice($provider)->inputTokenBound($request, $generation->provider()))->toBe(strlen($wire) + 256 + 3000);
})->with('image projection formats')->with([true, false]);

function syntheticImageProvider(array $payload): Provider
{
    return new class($payload) extends Anthropic
    {
        public function __construct(private array $payload)
        {
            parent::__construct('offline-only', '2023-06-01', 'https://example.invalid');
        }

        public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array
        {
            return $this->payload;
        }
    };
}

it('matches inline images only once in order at semantic user image positions', function (): void {
    $data = base64_encode(str_repeat('image', 300));
    $block = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $data]];
    $request = new TextRequest(model: 'offline-model', providerKey: 'anthropic', messages: [new UserMessage('go', [Image::fromBase64($data, 'image/png')])]);
    $payload = ['messages' => [['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => $data], $block, $block,
    ]], ['role' => 'assistant', 'content' => [$block]]], 'metadata' => $block, 'tools' => [$block]];
    $wireBytes = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
    // Five duplicate values: exactly one real charged image, never a reusable value allowlist.
    expect(imageProjectionPrice()->inputTokenBound($request, syntheticImageProvider($payload)))
        ->toBe($wireBytes - strlen($data) + 64 * 4 + 3000);
});

it('does not skip ahead and reuse an earlier image after matching a later one', function (): void {
    $first = base64_encode('first-image');
    $second = base64_encode('second-image');
    $request = new TextRequest(model: 'offline-model', providerKey: 'anthropic', messages: [new UserMessage('go', [Image::fromBase64($first, 'image/png'), Image::fromBase64($second, 'image/png')])]);
    $payload = ['messages' => [['role' => 'user', 'content' => array_map(fn ($data) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $data]], [$second, $first])]]];
    expect(imageProjectionPrice()->inputTokenBound($request, syntheticImageProvider($payload)))
        ->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) - strlen($second) + 128 + 6000);
});

it('keeps the complete byte bound for an unknown projector even when its keys resemble images', function (): void {
    $data = base64_encode('charged-image');
    $payload = ['messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $data]]]]]];
    $provider = new class($payload) extends Provider
    {
        public function __construct(private array $payload) {}

        public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array
        {
            return $this->payload;
        }
    };
    $request = new TextRequest(model: 'offline-model', providerKey: 'anthropic', messages: [new UserMessage('go', [Image::fromBase64($data, 'image/png')])]);
    expect(imageProjectionPrice()->inputTokenBound($request, $provider))
        ->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + 128 + 3000);
});

it('does not exclude provider image blocks without a charged inline Image', function (): void {
    $payload = ['messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'eA==']]]]]];
    $request = new TextRequest(model: 'offline-model', providerKey: 'anthropic', messages: [new UserMessage('go')]);
    expect(imageProjectionPrice()->inputTokenBound($request, syntheticImageProvider($payload)))
        ->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + 128);
});

it('counts unmatched inline data and image-looking keys conservatively', function (string $kind): void {
    $data = base64_encode('charged-image');
    $block = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $data]];
    $payload = match ($kind) {
        'wrong-value' => ['messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'other']]]]]],
        'wrong-mime' => ['messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $data]]]]]],
        'assistant-image' => ['messages' => [['role' => 'assistant', 'content' => [$block]]]],
        'wrong-kind' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'source' => $block['source']]]]]],
        default => ['metadata' => $block, 'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'data' => $data]]]]],
    };
    $request = new TextRequest(model: 'offline-model', providerKey: 'anthropic', messages: [new UserMessage('go', [Image::fromBase64($data, 'image/png')])]);
    expect(imageProjectionPrice()->inputTokenBound($request, syntheticImageProvider($payload)))
        ->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + 128 + 3000);
})->with(['wrong-value', 'wrong-mime', 'wrong-position', 'assistant-image', 'wrong-kind']);

it('preserves historical image fallback bounds without a projectable provider', function (string $kind): void {
    $provider = $kind === 'absent' ? new class extends Provider {} : new class extends Provider
    {
        public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array
        {
            throw new PrismUnprojectableRequestException;
        }
    };
    $request = new TextRequest(model: 'offline-model', providerKey: 'anthropic', messages: [new UserMessage('go', [Image::fromBase64('eA==', 'image/png')])]);
    expect(imageProjectionPrice()->inputTokenBound($request, $provider))->toBe(imageProjectionPrice()->inputTokenBound($request));
})->with(['absent', 'unprojectable']);

it('removes the synthetic run-154 image double count without tightening any text byte bound', function (): void {
    // Numeric severity from the report, synthetic data only; 10,997 is reported usage, not a tokenizer oracle.
    $base64 = str_repeat('A', 914432);
    $make = fn (string $text) => Prism::text()->using('anthropic', 'offline-model')->withPrompt($text, [Image::fromBase64($base64, 'image/png')]);
    $generation = $make('p');
    $initial = strlen(json_encode($generation->provider()->projectPayload($generation->toRequest(), 'text'), JSON_THROW_ON_ERROR)) + 128 + 14000;
    $generation = $make(str_repeat('p', 1 + 943299 - $initial));
    $request = $generation->toRequest();
    $oldBound = strlen(json_encode($generation->provider()->projectPayload($request, 'text'), JSON_THROW_ON_ERROR)) + 128 + 14000;
    expect($oldBound)->toBe(943299)
        ->and($oldBound / 10997)->toBeGreaterThan(85)
        ->and(imageProjectionPrice(imageBound: 14000)->inputTokenBound($request, $generation->provider()))->toBe(943299 - strlen($base64));
});
