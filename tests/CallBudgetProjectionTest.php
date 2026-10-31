<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\BudgetedCalls;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Exceptions\PrismUnprojectableRequestException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\CostObserver;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

function projectedCallPrice(string $provider = 'anthropic', array $extra = []): CallBudget
{
    $run = RunContext::root('projection', new RunBudget(10, 10.0, maxOutputTokensPerCall: 100), [
        $provider => ['diagnostic-model' => ['inputPerMTok' => 1, 'outputPerMTok' => 1, ...$extra]],
    ]);

    return CallBudget::forRun($run, $provider, 'diagnostic-model', []);
}

it('bounds actual dispatch bytes across provider formats, structured strategies and streaming', function (string $provider, string $format, string $mode, StructuredMode $structuredMode, array $options): void {
    Http::preventStrayRequests();
    $generation = $mode === 'structured' ? Prism::structured()->withSchema(new ObjectSchema('answer', 'Answer', [new StringSchema('value', 'Value')], ['value'])) : Prism::text();
    $generation->using($provider, 'diagnostic-model', ['api_key' => 'test-only', 'api_format' => $format])
        ->withSystemPrompt('System')->withMessages([
            new UserMessage('Question'),
            new AssistantMessage('', [new ToolCall('call', 'probe', ['operation' => str_repeat('a', 46000)], 'call')]),
            new ToolResultMessage([new ToolResult('call', 'probe', ['operation' => str_repeat('a', 46000)], ['signature' => 'billed result'], 'call')]),
        ])->withMaxTokens(100)->withProviderOptions($options);
    if ($mode === 'structured') {
        $generation->usingStructuredMode($structuredMode);
    }
    $request = $generation->toRequest();
    $before = serialize($request);
    $price = projectedCallPrice($provider);
    $bound = $price->inputTokenBound($request, $generation->provider(), $mode);
    expect(serialize($request))->toBe($before);
    $sent = null;
    $intercepted = new RuntimeException('Intercepted budget request');
    Http::fake(function (HttpRequest $wire) use (&$sent, $intercepted): never {
        $sent = $wire;
        throw $intercepted;
    });
    expect(fn () => match ($mode) {
        'structured' => $generation->provider()->structured($request),
        'stream' => iterator_to_array($generation->provider()->stream($request)),
        default => $generation->provider()->text($request),
    })->toThrow($intercepted);
    expect($bound)->toBeGreaterThanOrEqual(strlen($sent->body()))
        ->and($bound)->toBeLessThan($price->inputTokenBound($generation->toRequest()))
        ->and(substr_count($sent->body(), str_repeat('a', 46000)))->toBe(1)
        ->and($sent->body())->toContain('billed result');
})->with([
    ['anthropic', 'responses', 'text', StructuredMode::Auto, []],
    ['anthropic', 'responses', 'stream', StructuredMode::Auto, []],
    ['anthropic', 'responses', 'structured', StructuredMode::Auto, []],
    ['anthropic', 'responses', 'structured', StructuredMode::Auto, ['use_tool_calling' => true]],
    ['anthropic', 'responses', 'structured', StructuredMode::Auto, ['use_tool_calling' => true, 'thinking' => ['enabled' => false]]],
    ['openai', 'responses', 'text', StructuredMode::Auto, []],
    ['openai', 'responses', 'stream', StructuredMode::Auto, []],
    ['openai', 'responses', 'structured', StructuredMode::Structured, ['schema' => ['strict' => true]]],
    ['openai', 'responses', 'structured', StructuredMode::Json, []],
    ['openai', 'chat_completions', 'text', StructuredMode::Auto, []],
    ['openai', 'chat_completions', 'stream', StructuredMode::Auto, []],
    ['openai', 'chat_completions', 'structured', StructuredMode::Auto, []],
]);

it('removes stored run-147-shaped duplication while retaining all billed thinking and signatures', function (): void {
    // Synthetic shape from the reported components, not a customer transcript or usage measurement.
    Http::preventStrayRequests();
    Http::fake();
    $args = ['operation' => str_repeat('a', 46000)];
    $signature = str_repeat('s', 46000);
    $thinking = ['type' => 'thinking', 'thinking' => 'Retained reasoning', 'signature' => $signature];
    $generation = Prism::text()->using('anthropic', 'diagnostic-model')->withMaxTokens(100)->withMessages([
        new UserMessage('Run the operation.'),
        new AssistantMessage('', [new ToolCall('call', 'probe', $args, 'call')], [
            'content_blocks' => [$thinking, ['type' => 'tool_use', 'id' => 'call', 'name' => 'probe', 'input' => $args]],
            'thinking_blocks' => [$thinking], 'thinking' => 'Retained reasoning', 'thinking_signature' => $signature,
        ]),
        new ToolResultMessage([new ToolResult('call', 'probe', $args, ['signature' => 'billed tool result'], 'call')]),
    ]);
    $request = $generation->toRequest();
    $price = projectedCallPrice();
    $legacy = $price->inputTokenBound($request);
    $bound = $price->inputTokenBound($request, $generation->provider());
    $wire = json_encode($generation->provider()->projectPayload($request, 'text'), JSON_THROW_ON_ERROR);
    expect($bound)->toBeGreaterThanOrEqual(strlen($wire))->toBeLessThan($legacy * 0.6)
        ->and(substr_count($wire, $signature))->toBe(1)
        ->and(substr_count($wire, $args['operation']))->toBe(1)
        ->and($wire)->toContain('Retained reasoning', 'billed tool result');
    Http::assertNothingSent();
});

it('counts hostile billed text and result fields named signature in full', function (string $provider): void {
    Http::preventStrayRequests();
    Http::fake();
    $user = str_repeat('user-signature-', 4000);
    $result = str_repeat('result-signature-', 4000);
    $generation = Prism::text()->using($provider, 'diagnostic-model')->withMessages([
        new UserMessage(json_encode(['signature' => $user], JSON_THROW_ON_ERROR)),
        new AssistantMessage('', [new ToolCall('call', 'probe', [], 'call')]),
        new ToolResultMessage([new ToolResult('call', 'probe', [], ['signature' => $result], 'call')]),
    ]);
    $request = $generation->toRequest();
    $wire = json_encode($generation->provider()->projectPayload($request, 'text'), JSON_THROW_ON_ERROR);
    expect(projectedCallPrice($provider)->inputTokenBound($request, $generation->provider()))->toBeGreaterThanOrEqual(strlen($wire))
        ->toBeGreaterThanOrEqual(strlen($user) + strlen($result));
    expect($wire)->toContain($user, $result);
    Http::assertNothingSent();
})->with(['anthropic', 'openai']);

it('preserves exact historical bounds when projection is absent or unprojectable', function (string $kind): void {
    $args = ['operation' => str_repeat('a', 46000)];
    $request = new TextRequest(model: 'diagnostic-model', providerKey: 'anthropic', maxTokens: 100, messages: [
        new UserMessage('Run the operation.'), new AssistantMessage('', [new ToolCall('call', 'probe', $args, 'call')]),
        new ToolResultMessage([new ToolResult('call', 'probe', $args, 'Tool completed.', 'call')]),
    ]);
    $provider = $kind === 'absent' ? new class extends Provider {} : new class extends Provider
    {
        public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array
        {
            throw new PrismUnprojectableRequestException;
        }
    };
    // Measured against unchanged harness4208acc + normal core0.141.0 in measurement-1793406199904.
    expect(projectedCallPrice()->inputTokenBound($request, $provider))->toBe(92813);
})->with(['absent', 'unprojectable']);

it('fails closed on unexpected projection and encoding failures without transport', function (string $kind): void {
    Http::preventStrayRequests();
    Http::fake();
    $provider = new class($kind) extends Provider
    {
        public function __construct(public string $kind) {}

        public function projectPayload(TextRequest|StructuredRequest $request, string $mode): ?array
        {
            return match ($this->kind) {
                'throw' => throw new RuntimeException('private counter failure'),
                'error' => throw new TypeError('private counter failure'),
                default => ['metadata' => ['signature' => "\xFF"]],
            };
        }
    };
    $request = Prism::text()->using('anthropic', 'diagnostic-model')->withPrompt('go')->toRequest();
    expect(fn () => projectedCallPrice()->inputTokenBound($request, $provider))->toThrow(RunNotPermitted::class);
    Http::assertNothingSent();
})->with(['throw', 'error', 'json']);

it('retains hidden tool, image and hosted-context guards on the projection path', function (string $kind): void {
    Http::preventStrayRequests();
    Http::fake();
    $generation = Prism::text()->using('anthropic', 'diagnostic-model')->withPrompt('go');
    match ($kind) {
        'tool' => $generation->withTools([(new Tool)->as('probe')->for('Probe')]),
        'server-tool' => $generation->withProviderTools([new ProviderTool('computer_test')]),
        'image' => $generation->withPrompt('go', [Image::fromBase64('eA==', 'image/png')]),
        default => $generation->withProviderOptions(['mcp_servers' => [['type' => 'url', 'url' => 'https://host.invalid']]]),
    };
    expect(fn () => projectedCallPrice()->inputTokenBound($generation->toRequest(), $generation->provider()))->toThrow(RunNotPermitted::class);
    Http::assertNothingSent();
})->with(['tool', 'server-tool', 'image', 'hosted-mcp']);

it('adds the explicit image bound in addition to complete projected JSON bytes', function (): void {
    $generation = Prism::text()->using('anthropic', 'diagnostic-model')->withPrompt('go', [Image::fromBase64('eA==', 'image/png')]);
    $request = $generation->toRequest();
    $wireBytes = strlen(json_encode($generation->provider()->projectPayload($request, 'text'), JSON_THROW_ON_ERROR));
    expect(projectedCallPrice(extra: ['imageTokenBound' => 3000])->inputTokenBound($request, $generation->provider()))->toBeGreaterThanOrEqual($wireBytes + 3000);
});

it('counts native OpenAI replay items and opaque encrypted content without filtering keys', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $encrypted = str_repeat('opaque-encrypted-', 4000);
    $generation = Prism::text()->using('openai', 'diagnostic-model')->withMessages([
        new UserMessage('Continue'),
        new AssistantMessage('', additionalContent: ['output_items' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'Retained summary']], 'encrypted_content' => $encrypted],
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Retained text']]],
        ]]),
    ])->withProviderOptions(['metadata' => ['signature' => 'retained metadata']]);
    $request = $generation->toRequest();
    $wire = json_encode($generation->provider()->projectPayload($request, 'text'), JSON_THROW_ON_ERROR);
    expect(projectedCallPrice('openai')->inputTokenBound($request, $generation->provider()))->toBeGreaterThanOrEqual(strlen($wire))
        ->toBeGreaterThanOrEqual(strlen($encrypted));
    expect($wire)->toContain($encrypted, 'Retained summary', 'Retained text', 'retained metadata');
    Http::assertNothingSent();
});

it('admits a native call that fits its mapped payload but not duplicated storage', function (string $shape): void {
    Http::preventStrayRequests();
    Http::fakeSequence()->push($shape === 'stream' ? budgetSseReply(1, true) : budgetHttpReply(1, true));
    $args = ['operation' => str_repeat('a', 46000)];
    $generation = $shape === 'structured' ? Prism::structured()->withSchema(callBudgetSchema()) : Prism::text();
    $generation->using('anthropic', 'diagnostic-model', ['api_key' => 'test-only'])->withMessages([
        new UserMessage('Run the operation.'), new AssistantMessage('', [new ToolCall('call', 'probe', $args, 'call')]),
        new ToolResultMessage([new ToolResult('call', 'probe', $args, 'Tool completed.', 'call')]),
    ]);
    $observer = new CostObserver;
    $run = RunContext::root('mapped-admission', new RunBudget(2, 0.07, maxOutputTokensPerCall: 100), [
        'anthropic' => ['diagnostic-model' => ['inputPerMTok' => 1, 'outputPerMTok' => 1]],
    ], observer: $observer);
    $price = CallBudget::forRun($run, 'anthropic', 'diagnostic-model', []);
    expect($price->worstCase($generation->toRequest()))->toBeGreaterThan(0.07);
    $calls = new BudgetedCalls($price);
    $record = static function (): void {};
    $resolved = static function (): void {};
    match ($shape) {
        'structured' => $calls->structured($generation, $run, $record, $resolved),
        'stream' => iterator_to_array($calls->stream($generation, $run, $record, $resolved)),
        default => $calls->text($generation, $run, $record, $resolved),
    };
    Http::assertSentCount(1);
    expect($observer->reservations)->toHaveCount(1)
        ->and($observer->reservations[0]->worstCaseUsd)->toBeLessThan(0.07)
        ->and($observer->settlements)->toHaveCount(1)
        ->and($run->ledger->reservedCostUsd())->toBe(0.0);
})->with(['text', 'structured', 'stream']);
