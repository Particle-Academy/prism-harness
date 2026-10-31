<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\CallBudgetDiagnostic;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Contracts\DescribesProjectedPayload;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Anthropic\Anthropic;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\CostObserver;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

class ProjectedFormatTestDecorator extends Provider
{
    public int $projectionCalls = 0;

    public function __construct(protected readonly Provider $inner, private readonly ?array $payload = null) {}

    public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array
    {
        $this->projectionCalls++;

        return $this->payload ?? $this->inner->projectPayload($request, $mode);
    }

    public function text(TextRequest $request): TextResponse
    {
        return $this->inner->text($request);
    }

    public function structured(StructuredRequest $request): StructuredResponse
    {
        return $this->inner->structured($request);
    }

    public function stream(TextRequest $request): Generator
    {
        yield from $this->inner->stream($request);
    }
}

function declaredFormatTestDecorator(Provider $inner, ?string $format, ?array $payload = null): Provider
{
    return new class($inner, $format, $payload) extends ProjectedFormatTestDecorator implements DescribesProjectedPayload
    {
        public int $metadataCalls = 0;

        public function __construct(Provider $inner, private readonly ?string $format, ?array $payload)
        {
            parent::__construct($inner, $payload);
        }

        public function projectedPayloadFormat(): ?string
        {
            $this->metadataCalls++;

            return $this->format;
        }
    };
}

function projectedFormatTestBudget(string $provider): CallBudget
{
    $run = RunContext::root('format-projection', new RunBudget(10, 10.0, maxOutputTokensPerCall: 4096), [
        $provider => ['offline-model' => ['inputPerMTok' => 1, 'outputPerMTok' => 1, 'imageTokenBound' => 3000]],
    ]);

    return CallBudget::forRun($run, $provider, 'offline-model', []);
}

function projectedFormatTestGeneration(string $provider, string $apiFormat, string $mode): mixed
{
    $generation = $mode === 'structured'
        ? Prism::structured()->withSchema(new ObjectSchema('answer', 'Answer', [new StringSchema('value', 'Value')], ['value']))
        : Prism::text();

    return $generation->using($provider, 'offline-model', ['api_key' => 'offline-only', 'api_format' => $apiFormat])
        ->withPrompt('Review', [Image::fromBase64(base64_encode(str_repeat("\xff", 768)), 'image/png')])->withMaxTokens(4096);
}

dataset('declared projection formats', [
    'Anthropic' => ['anthropic', 'responses', 'anthropic-messages'],
    'Responses' => ['openai', 'responses', 'openai-responses'],
    'Chat' => ['openai', 'chat_completions', 'openai-chat-completions'],
]);

it('keeps the old-core native fallback without autoloading the absent optional interface', function (string $name, string $apiFormat, string $format): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = projectedFormatTestGeneration($name, $apiFormat, 'text');
    $request = $generation->toRequest();
    $provider = $generation->provider();
    $payload = $provider->projectPayload($request, 'text');
    $image = $request->messages()[0]->additionalContent[0];
    $encodedImageBytes = strlen(json_encode($image->base64(), JSON_THROW_ON_ERROR)) - 2;
    $autoloadCalls = 0;
    $watch = function (string $class) use (&$autoloadCalls): void {
        if ($class === DescribesProjectedPayload::class) {
            $autoloadCalls++;
        }
    };
    spl_autoload_register($watch, prepend: true);
    try {
        $estimate = projectedFormatTestBudget($name)->estimate($request, $provider);
    } finally {
        spl_autoload_unregister($watch);
    }

    expect($estimate->inputTokenBound)->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) - $encodedImageBytes + 128 + 3000)
        ->and($estimate->diagnostics)->toBe([])
        ->and($autoloadCalls)->toBe(0);
    Http::assertNothingSent();
})->with('declared projection formats')
    ->skip(fn (): bool => interface_exists(DescribesProjectedPayload::class), 'Requires the optional interface to be absent, as on the core 0.143 lowest-dependency CI legs.');

it('gets the exact native image bound through a decorator forwarding both capabilities', function (string $name, string $apiFormat, string $format, string $mode): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = projectedFormatTestGeneration($name, $apiFormat, $mode);
    $request = $generation->toRequest();
    $provider = $generation->provider();
    $wrapper = declaredFormatTestDecorator($provider, $format);
    $before = serialize($request);
    $payload = $provider->projectPayload($request, $mode);
    $image = $request->messages()[0]->additionalContent[0];
    $encodedImageBytes = strlen(json_encode($image->base64(), JSON_THROW_ON_ERROR)) - 2;
    $overhead = $mode === 'structured' && $apiFormat === 'chat_completions' ? 192 : 128;

    expect(projectedFormatTestBudget($name)->inputTokenBound($request, $wrapper, $mode))
        ->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) - $encodedImageBytes + $overhead + 3000)
        ->toBe(projectedFormatTestBudget($name)->inputTokenBound($request, $provider, $mode))
        ->and($wrapper->projectionCalls)->toBe(1)
        ->and(serialize($request))->toBe($before);
    Http::assertNothingSent();
})->with('declared projection formats')->with(['text', 'structured', 'stream'])
    ->skip(fn (): bool => ! interface_exists(DescribesProjectedPayload::class), 'Core 0.144 format declarations are unavailable; legacy behavior is tested separately.');

it('reports an unknown projection-only wrapper without refusing or reducing its full byte bound', function (string $name, string $apiFormat, string $format, string $mode): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = projectedFormatTestGeneration($name, $apiFormat, $mode);
    $request = $generation->toRequest();
    $provider = $generation->provider();
    $wrapper = new ProjectedFormatTestDecorator($provider);
    $payload = $provider->projectPayload($request, $mode);
    $overhead = $mode === 'structured' && $apiFormat === 'chat_completions' ? 192 : 128;
    $budget = projectedFormatTestBudget($name);
    $estimate = $budget->estimate($request, $wrapper, $mode);

    expect($estimate->inputTokenBound)->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + $overhead + 3000)
        ->and($estimate->diagnostics)->toBe([CallBudgetDiagnostic::ProjectedImageFormatUnknown])
        ->and($estimate->worstCaseUsd)->toBe(($estimate->inputTokenBound + 4096) / 1_000_000)
        ->and($wrapper->projectionCalls)->toBe(1)
        ->and($budget->inputTokenBound($request, $wrapper, $mode))->toBe($estimate->inputTokenBound)
        ->and($budget->worstCase($request, $wrapper, $mode))->toBe($estimate->worstCaseUsd);
    Http::assertNothingSent();
})->with('declared projection formats')->with(['text', 'structured', 'stream']);

it('keeps null and private unknown declarations conservative without exposing their values', function (?string $format): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = projectedFormatTestGeneration('anthropic', 'responses', 'text');
    $request = $generation->toRequest();
    $provider = $generation->provider();
    $payload = $provider->projectPayload($request, 'text');
    $estimate = projectedFormatTestBudget('anthropic')->estimate($request, declaredFormatTestDecorator($provider, $format));

    expect($estimate->inputTokenBound)->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + 128 + 3000)
        ->and($estimate->diagnostics)->toBe([CallBudgetDiagnostic::ProjectedImageFormatUnknown])
        ->and(json_encode($estimate->diagnostics, JSON_THROW_ON_ERROR))->toBe('["projected_image_format_unknown"]');
    Http::assertNothingSent();
})->with([null, 'private-future-format:do-not-disclose'])
    ->skip(fn (): bool => ! interface_exists(DescribesProjectedPayload::class), 'Requires the optional core 0.144 declaration.');

it('fails closed when a declared format throws without exposing its error', function (): void {
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response(budgetHttpReply(1, true)));
    $session = callBudgetSession('throwing-format-admission');
    $observer = new CostObserver;
    $run = RunContext::root('throwing-format', new RunBudget(10, 1.0, maxOutputTokensPerCall: 10), [
        'anthropic' => ['budget-model' => ['inputPerMTok' => 1, 'outputPerMTok' => 1, 'imageTokenBound' => 3000, 'toolOverheadTokens' => 286]],
    ], observer: $observer);
    $inner = Prism::text()->using('anthropic', 'budget-model', ['api_key' => 'offline-only'])->provider();
    $provider = new class($inner) extends ProjectedFormatTestDecorator implements DescribesProjectedPayload
    {
        public int $metadataCalls = 0;

        public function projectedPayloadFormat(): ?string
        {
            $this->metadataCalls++;
            throw new LogicException('synthetic-private-format-error');
        }
    };
    app(PrismManager::class)->extend('anthropic', static fn ($app, array $config): Provider => $provider);

    try {
        callBudgetRun('send', $session, $run, [Image::fromBase64('YWJj', 'image/png')]);
        $this->fail('A throwing format declaration must refuse admission.');
    } catch (RunNotPermitted $error) {
        expect($error->code())->toBe('budget_input_unbounded')
            ->and($error->getMessage())->not->toContain('synthetic-private-format-error')
            ->and($error->getPrevious())->toBeNull();
    }
    expect($provider->projectionCalls)->toBe(1)
        ->and($provider->metadataCalls)->toBe(1)
        ->and($observer->reservations)->toBe([])
        ->and($observer->settlements)->toBe([])
        ->and($run->ledger->reservedCostUsd())->toBe(0.0)
        ->and($run->ledger->costUsd())->toBe(0.0)
        ->and($run->ledger->steps())->toBe(0)
        ->and(json_encode($observer->reservations, JSON_THROW_ON_ERROR))->not->toContain('synthetic-private-format-error');
    Http::assertNothingSent();
})->skip(fn (): bool => ! interface_exists(DescribesProjectedPayload::class), 'Requires the optional core 0.144 declaration.');

it('never falls back to a native class after an explicit null or unknown declaration', function (?string $format): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = projectedFormatTestGeneration('anthropic', 'responses', 'text');
    $request = $generation->toRequest();
    $provider = new class($format) extends Anthropic
    {
        public function __construct(private readonly ?string $format)
        {
            parent::__construct('offline-only', '2023-06-01', 'https://format.invalid');
        }

        public function projectedPayloadFormat(): ?string
        {
            return $this->format;
        }
    };
    $payload = $provider->projectPayload($request, 'text');
    $estimate = projectedFormatTestBudget('anthropic')->estimate($request, $provider);

    expect($estimate->inputTokenBound)->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + 128 + 3000)
        ->and($estimate->diagnostics)->toBe([CallBudgetDiagnostic::ProjectedImageFormatUnknown]);
    Http::assertNothingSent();
})->with([null, 'unknown-native-format'])
    ->skip(fn (): bool => ! interface_exists(DescribesProjectedPayload::class), 'Requires the optional core 0.144 declaration.');

it('treats a declared format only as a location hint and retains every nonmatching image byte', function (string $name, string $apiFormat, string $format, string $mismatch): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = projectedFormatTestGeneration($name, $apiFormat, 'text');
    $request = $generation->toRequest();
    $provider = $generation->provider();
    $payload = $provider->projectPayload($request, 'text');
    $field = $format === 'openai-responses' ? 'input' : 'messages';
    // Keep mismatched data large enough that a bad discount remains positive:
    // this must detect undercounting rather than relying on the negative-byte guard.
    $differentData = str_repeat('A', 2048);
    $block = &$payload[$field][0]['content'][1];
    if ($mismatch === 'role') {
        $payload[$field][0]['role'] = 'assistant';
    } elseif ($mismatch === 'position') {
        $payload['metadata'] = $block;
        array_splice($payload[$field][0]['content'], 1, 1);
    } elseif ($mismatch === 'type') {
        $block['type'] = 'text';
    } elseif ($format === 'anthropic-messages') {
        $block['source'][$mismatch === 'data' ? 'data' : 'media_type'] = $mismatch === 'data' ? $differentData : 'image/jpeg';
    } elseif ($format === 'openai-chat-completions') {
        $block['image_url']['url'] = $mismatch === 'data' ? 'data:image/png;base64,'.$differentData : str_replace('image/png', 'image/jpeg', $block['image_url']['url']);
    } else {
        $block['image_url'] = $mismatch === 'data' ? 'data:image/png;base64,'.$differentData : str_replace('image/png', 'image/jpeg', $block['image_url']);
    }
    unset($block);
    $wrapper = declaredFormatTestDecorator($provider, $format, $payload);
    $estimate = projectedFormatTestBudget($name)->estimate($request, $wrapper);

    expect($estimate->inputTokenBound)->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + 128 + 3000)
        ->and($estimate->diagnostics)->toBe([])
        ->and($wrapper->projectionCalls)->toBe(1);
    Http::assertNothingSent();
})->with('declared projection formats')->with(['data', 'mime', 'role', 'position', 'type'])
    ->skip(fn (): bool => ! interface_exists(DescribesProjectedPayload::class), 'Requires the optional core 0.144 declaration.');

it('does not consult declaration metadata when no payload projection exists', function (): void {
    $provider = new class extends Provider implements DescribesProjectedPayload
    {
        public int $metadataCalls = 0;

        public function projectedPayloadFormat(): ?string
        {
            $this->metadataCalls++;

            return 'anthropic-messages';
        }
    };
    $request = new TextRequest(model: 'offline-model', providerKey: 'anthropic', messages: [new UserMessage('Review', [Image::fromBase64('YWJj', 'image/png')])]);
    $budget = projectedFormatTestBudget('anthropic');
    $estimate = $budget->estimate($request, $provider);

    expect($estimate->inputTokenBound)->toBe($budget->inputTokenBound($request))
        ->and($estimate->diagnostics)->toBe([])
        ->and($provider->metadataCalls)->toBe(0);
})->skip(fn (): bool => ! interface_exists(DescribesProjectedPayload::class), 'Requires the optional core 0.144 declaration.');

it('keeps inline media opaque when its projected format is unknown', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $image = new class extends Image
    {
        public int $contentReads = 0;

        public function base64(): ?string
        {
            $this->contentReads++;
            throw new LogicException('Unknown projection must not inspect inline content.');
        }

        public function mimeType(): ?string
        {
            $this->contentReads++;
            throw new LogicException('Unknown projection must not inspect inline content.');
        }
    };
    $payload = ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'opaque image']]]]];
    $provider = new class($payload) extends Provider
    {
        public function __construct(private readonly array $payload) {}

        public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array
        {
            return $this->payload;
        }
    };
    $request = new TextRequest(model: 'offline-model', providerKey: 'anthropic', messages: [new UserMessage('Review', [$image])]);
    $estimate = projectedFormatTestBudget('anthropic')->estimate($request, $provider);

    expect($estimate->inputTokenBound)->toBe(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) + 128 + 3000)
        ->and($estimate->diagnostics)->toBe([CallBudgetDiagnostic::ProjectedImageFormatUnknown])
        ->and($image->contentReads)->toBe(0);
    Http::assertNothingSent();
});

it('exposes the fixed reason at native host admission after one projection and before dispatch', function (string $shape, bool $declared): void {
    if ($declared && ! interface_exists(DescribesProjectedPayload::class)) {
        $this->markTestSkipped('Core 0.144 is required to forward the declaration; projection-only admission runs on the legacy core too.');
    }
    Http::preventStrayRequests();
    $session = callBudgetSession('format-admission-'.$shape);
    $observer = new CostObserver;
    $run = RunContext::root('format-admission', new RunBudget(10, 1.0, maxOutputTokensPerCall: 10), [
        'anthropic' => ['budget-model' => ['inputPerMTok' => 1, 'outputPerMTok' => 1, 'imageTokenBound' => 3000, 'toolOverheadTokens' => 286]],
    ], observer: $observer);
    $inner = Prism::text()->using('anthropic', 'budget-model', ['api_key' => 'offline-only'])->provider();
    $wrapper = $declared ? declaredFormatTestDecorator($inner, 'anthropic-messages') : new ProjectedFormatTestDecorator($inner);
    app(PrismManager::class)->extend('anthropic', static fn ($app, array $config): Provider => $wrapper);
    $observer->before = function (CallReservation $call) use ($wrapper, $declared, $shape): void {
        Http::assertNothingSent();
        expect($call->diagnostics)->toBe($declared ? [] : [CallBudgetDiagnostic::ProjectedImageFormatUnknown])
            ->and($call->kind)->toBe($shape === 'send' ? 'text' : $shape)
            ->and($wrapper->projectionCalls)->toBe(1)
            ->and(json_encode($call, JSON_THROW_ON_ERROR))->not->toContain('YWJj');
        if ($declared) {
            expect($wrapper->metadataCalls)->toBe(1);
        }
    };
    Http::fake(function () use ($observer, $wrapper, $shape) {
        expect($observer->reservations)->toHaveCount(1)
            ->and($wrapper->projectionCalls)->toBe(1);

        return $shape === 'stream'
            ? Http::response(budgetSseReply(1, true), 200, ['Content-Type' => 'text/event-stream'])
            : Http::response(budgetHttpReply(1, true));
    });

    callBudgetRun($shape, $session, $run, [Image::fromBase64('YWJj', 'image/png')]);

    expect($observer->order)->toBe(['reserving', 'settled'])
        ->and($observer->reservations)->toHaveCount(1)
        ->and($observer->settlements)->toHaveCount(1)
        ->and($observer->settlements[0]->outcome)->toBe('completed')
        ->and($run->ledger->reservedCostUsd())->toBe(0.0)
        ->and($wrapper->projectionCalls)->toBe(1);
    Http::assertSentCount(1);
})->with(['send', 'structured', 'stream'])->with([false, true]);
