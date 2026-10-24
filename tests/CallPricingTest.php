<?php

declare(strict_types=1);

use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\PrismHarness;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

require_once __DIR__.'/Support/CallBudgetFixtures.php';

function haikuCallPrice(): array
{
    // Owner-supplied acceptance fixture, verified by master on 2026-10-23.
    return ['inputPerMTok' => 0.10, 'outputPerMTok' => 0.50,
        'cacheWritePerMTok' => 0.125, 'cacheReadPerMTok' => 0.01, 'toolOverheadTokens' => 286,
        'tiers' => [['aboveInputTokens' => 100000, 'inputPerMTok' => 0.50, 'outputPerMTok' => 2.50,
            'cacheWritePerMTok' => 0.625, 'cacheReadPerMTok' => 0.05]]];
}

function configuredCallPrice(array $price): array
{
    $run = RunContext::root('priced', new RunBudget(10, 10.0, maxOutputTokensPerCall: 10000),
        ['anthropic' => ['budget-model' => $price]]);

    return [$run, CallBudget::forRun($run, 'anthropic', 'budget-model', [])];
}

it('uses UTF-8 bytes of the complete conversation and schemas as an upper bound', function (): void {
    [, $price] = configuredCallPrice(haikuCallPrice());
    $text = str_repeat('日本語🙂', 40);
    $request = Prism::text()->using('anthropic', 'budget-model')->withSystemPrompt('System '.$text)->withMessages([
        new UserMessage($text), new AssistantMessage('Look', [new ToolCall('one', 'lookup', ['query' => $text])]),
        new ToolResultMessage([new ToolResult('one', 'lookup', ['query' => $text], ['result' => $text])]),
    ])->withTools([(new Tool)->as('lookup')->for('Find '.$text)->withStringParameter('query', $text)])->toRequest();
    // This over-estimates tokens deliberately: byte counts and JSON framing
    // make admission early, never late. The fixture's reported input is 1,200.
    expect($price->inputTokenBound($request))->toBeGreaterThanOrEqual(1200)
        ->and($price->inputTokenBound($request))->toBeGreaterThanOrEqual(strlen($text) * 5 + 286);
});

it('adds a configured image token bound without guessing from the image bytes', function (): void {
    [, $price] = configuredCallPrice([...haikuCallPrice(), 'imageTokenBound' => 3000]);
    $plain = Prism::text()->using('anthropic', 'budget-model')->withMessages([new UserMessage('go')])->toRequest();
    $image = Prism::text()->using('anthropic', 'budget-model')
        ->withMessages([new UserMessage('go', [Image::fromBase64('eA==', 'image/png')])])->toRequest();
    expect($price->inputTokenBound($image) - $price->inputTokenBound($plain))->toBe(3000);
});

it('refuses tools whose hidden provider overhead has not been configured', function (string $shape): void {
    $session = callBudgetSession();
    $run = RunContext::root('priced', new RunBudget(10, 1.0, maxOutputTokensPerCall: 10), [
        'anthropic' => ['budget-model' => ['inputPerMTok' => 2, 'outputPerMTok' => 10]],
    ]);
    $fake = Prism::fake([TextResponseFake::make()]);
    try {
        callBudgetRun($shape, $session, $run);
        $this->fail('The hidden tool prompt must be bounded.');
    } catch (RunNotPermitted $failure) {
        expect($failure->code())->toBe('budget_input_unbounded');
    }
    $fake->assertCallCount(0);
})->with(['send', 'structured', 'stream']);

it('selects the higher Haiku tier from the upper bound before admitting the call', function (): void {
    $session = callBudgetSession();
    $run = RunContext::root('haiku', new RunBudget(10, 0.05, maxOutputTokensPerCall: 10000),
        ['anthropic' => ['budget-model' => haikuCallPrice()]]);
    $price = CallBudget::forRun($run, 'anthropic', 'budget-model', []);
    $prompt = str_repeat('x', 100000);
    $request = Prism::text()->using('anthropic', 'budget-model')->withPrompt($prompt)->toRequest();
    $bound = $price->inputTokenBound($request);
    expect($bound)->toBeGreaterThan(100000)
        ->and(($bound * 0.125 + 10000 * 0.50) / 1000000)->toBeLessThan(0.05)
        ->and($price->worstCase($request))->toBeGreaterThan(0.05);
    $fake = Prism::fake([TextResponseFake::make()]);
    expect(fn () => $session->send($prompt, context: $run))->toThrow(RunNotPermitted::class);
    $fake->assertCallCount(0);
});

it('prices each actual prompt tier including cache reads and cache writes', function (): void {
    [, $price] = configuredCallPrice(haikuCallPrice());
    expect($price->costOf(new Usage(20000, 10, cacheWriteInputTokens: 40000, cacheReadInputTokens: 50000)))
        ->toBe(0.037525)
        ->and($price->costOf(new Usage(20000, 10, cacheWriteInputTokens: 30000, cacheReadInputTokens: 50000)))
        ->toBe(0.006255); // Exactly 100K still uses the base tier.
});

it('applies a price multiplier to both worst-case admission and token-derived cost', function (): void {
    $session = callBudgetSession();
    $run = RunContext::root('geo', new RunBudget(10, 0.105, maxOutputTokensPerCall: 10), [
        'anthropic' => ['budget-model' => ['inputPerMTok' => 0, 'outputPerMTok' => 10000,
            'toolOverheadTokens' => 286, 'multiplier' => 1.1]],
    ]);
    $price = CallBudget::forRun($run, 'anthropic', 'budget-model', []);
    $request = Prism::text()->using('anthropic', 'budget-model')->withPrompt('go')->toRequest();
    expect($price->worstCase($request))->toEqualWithDelta(0.11, 1e-12)
        // One input token at a zero input rate: usage with no input at all is unknown.
        ->and($price->costOf(new Usage(1, 10)))->toEqualWithDelta(0.11, 1e-12)
        // Provider-reported total already includes its pricing/multipliers.
        ->and($price->costOf(new Usage(0, 10, cost: 0.08)))->toBe(0.08);
    $fake = Prism::fake([TextResponseFake::make()]);
    expect(fn () => $session->send('go', context: $run))->toThrow(RunNotPermitted::class);
    $fake->assertCallCount(0);
});

it('uses input rates for cache counters when no cache-specific rates were supplied', function (): void {
    [, $price] = configuredCallPrice(['inputPerMTok' => 2, 'outputPerMTok' => 10]);
    expect($price->costOf(new Usage(100, 20, cacheWriteInputTokens: 30, cacheReadInputTokens: 40, thoughtTokens: 5)))
        ->toBe(0.00054); // Thinking is a subset of output, not another output charge.
});

it('reads configured pricing when the run has no override', function (): void {
    $session = callBudgetSession();
    config()->set('prism-harness.pricing.anthropic.budget-model', haikuCallPrice());
    // Runtime is lazy: configure before it is resolved by the session helper.
    app()->forgetInstance(AgentRuntime::class);
    app()->forgetInstance(PrismHarness::class);
    $session = callBudgetSession('configured');
    $run = RunContext::root('configured', new RunBudget(10, 1.0, maxOutputTokensPerCall: 10));
    $fake = Prism::fake(callBudgetResponses('send', true, [null]));
    $session->send('go', context: $run);
    $fake->assertCallCount(1);
    expect($run->ledger->costUsd())->toBe(0.0000013)->and($run->ledger->unmeteredRuns())->toBe(0);
});

it('ships no guessed model pricing and no implicit output limit', function (): void {
    $config = require __DIR__.'/../config/prism-harness.php';
    expect($config['pricing'])->toBe([])->and($config['agent']['max_output_tokens_per_call'])->toBeNull();
});

it('requires a separate overhead for each declared provider toolset', function (): void {
    $request = Prism::text()->using('anthropic', 'budget-model')->withPrompt('go')
        ->withProviderTools([new ProviderTool('computer_test')])->toRequest();
    [, $without] = configuredCallPrice(haikuCallPrice());
    expect(fn () => $without->inputTokenBound($request))->toThrow(RunNotPermitted::class);
    [, $with] = configuredCallPrice([...haikuCallPrice(), 'serverToolOverheadTokens' => ['computer_test' => 10000]]);
    expect($with->inputTokenBound($request))->toBeGreaterThan(10000);
});
