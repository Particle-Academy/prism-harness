<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\OpenRouter\OpenRouter;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\CostObserver;

require_once __DIR__.'/Support/CallBudgetFixtures.php';

function routerBillingContext(CostObserver $observer, float $cap = 1.0, array $extra = []): RunContext
{
    // Synthetic conservative bound: each call reserves $0.01. Missing billing
    // would otherwise fall back to a much cheaper token-derived cost.
    return RunContext::root('router-billing', new RunBudget(10, $cap, maxOutputTokensPerCall: 10), [
        'openrouter' => ['budget-model' => ['inputPerMTok' => 0, 'outputPerMTok' => 1000,
            'toolOverheadTokens' => 286, ...$extra]],
    ], observer: $observer);
}

function routerBillingReply(?array $billing, bool $tool = false, bool $stream = false): array|string
{
    $message = ['role' => 'assistant', 'content' => $tool ? '' : '{"ok":true}'];
    if ($tool) {
        $message['tool_calls'] = [['index' => 0, 'id' => 'owned-router-call', 'type' => 'function',
            'function' => ['name' => 'tick', 'arguments' => '{}']]];
    }
    $body = ['id' => 'owned-router-reply', 'model' => 'budget-model',
        'choices' => [['index' => 0, 'finish_reason' => $tool ? 'tool_calls' : 'stop', $stream ? 'delta' : 'message' => $message]]];
    if ($billing !== null) {
        $body['usage'] = ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5, ...$billing];
    }

    return $stream ? 'data: '.json_encode($body, JSON_THROW_ON_ERROR)."\n\ndata: [DONE]\n\n" : $body;
}

dataset('router billing evidence', [
    'zero fee still costs upstream inference' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => 0.004]], 0.004],
    'router fee plus upstream' => [['is_byok' => true, 'cost' => 0.0002, 'cost_details' => ['upstream_inference_cost' => 0.004]], 0.0042],
    'complete genuine free call' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => 0]], 0.0],
    'non-BYOK never counts upstream twice' => [['is_byok' => false, 'cost' => 0.004, 'cost_details' => ['upstream_inference_cost' => 0.004]], 0.004],
    'missing upstream cannot use token fallback' => [['is_byok' => true, 'cost' => 0], null],
    'null upstream cannot mean zero' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => null]], null],
    'negative upstream is unknown' => [['is_byok' => true, 'cost' => 0, 'cost_details' => ['upstream_inference_cost' => -0.004]], null],
    'missing identity cannot trust even positive legacy cost' => [['cost' => 0.004], null],
    'malformed identity cannot claim completeness' => [['is_byok' => 'true', 'cost' => 0.004], null],
    'missing router fee is unknown' => [['is_byok' => true, 'cost_details' => ['upstream_inference_cost' => 0.004]], null],
]);

it('settles native OpenRouter calls only with complete reported billing', function (string $shape, array $billing, ?float $cost): void {
    config()->set('prism.providers.openrouter.api_key', 'owned-test-only');
    $session = callBudgetSession()->usingProvider('openrouter');
    $observer = new CostObserver;
    $run = routerBillingContext($observer);
    Http::fakeSequence()->push(routerBillingReply($billing, stream: $shape === 'stream'), 200,
        $shape === 'stream' ? ['Content-Type' => 'text/event-stream'] : []);
    Http::preventStrayRequests();
    callBudgetRun($shape, $session, $run);
    Http::assertSentCount(1);
    expect($observer->settlements)->toHaveCount(1);
    $settled = $observer->settlements[0];
    expect($settled->usageKnown)->toBe($cost !== null)
        ->and($settled->outcome)->toBe('completed')
        ->and($settled->chargedUsd)->toEqualWithDelta($cost ?? $observer->reservations[0]->worstCaseUsd, 1e-12)
        ->and($run->ledger->costUsd())->toBe($settled->chargedUsd)
        ->and($run->ledger->reservedCostUsd())->toBe(0.0);
})->with(['send', 'structured', 'stream'])->with('router billing evidence');

it('stops before the next native request when reported or unknown billing consumes its allowance', function (string $shape, bool $complete): void {
    config()->set('prism.providers.openrouter.api_key', 'owned-test-only');
    $session = callBudgetSession()->usingProvider('openrouter');
    $observer = new CostObserver;
    $run = routerBillingContext($observer, 0.01);
    $billing = ['is_byok' => true, 'cost' => 0];
    if ($complete) {
        $billing['cost_details'] = ['upstream_inference_cost' => 0.004];
    }
    Http::fakeSequence()->push(routerBillingReply($billing, tool: true, stream: $shape === 'stream'), 200,
        $shape === 'stream' ? ['Content-Type' => 'text/event-stream'] : []);
    Http::preventStrayRequests();
    try {
        callBudgetRun($shape, $session, $run);
        $this->fail('The next reservation cannot fit.');
    } catch (RunNotPermitted $failure) {
        expect($failure->code())->toBe('call_budget_exceeded');
    }
    Http::assertSentCount(1);
    expect($observer->reservations)->toHaveCount(1)->and($observer->settlements)->toHaveCount(1)
        ->and($observer->settlements[0]->usageKnown)->toBe($complete)
        ->and($run->ledger->costUsd())->toBe($complete ? 0.004 : 0.01);
})->with(['send', 'structured', 'stream'])->with([true, false]);

it('requires exactly one StepFinish provenance and a valid final stream total', function (string $fault): void {
    config()->set('prism.providers.openrouter.api_key', 'owned-test-only');
    app(PrismManager::class)->extend('openrouter', fn () => new class($fault) extends OpenRouter
    {
        public function __construct(private string $fault)
        {
            parent::__construct('owned-test-only', 'https://openrouter.invalid/api/v1');
        }

        public function stream(Request $request): Generator
        {
            foreach (parent::stream($request) as $event) {
                if ($event instanceof StepFinishEvent && $this->fault === 'missing-step') {
                    continue;
                }
                if ($event instanceof StreamEndEvent && $this->fault === 'null-final') {
                    $event = new StreamEndEvent($event->id, $event->timestamp, $event->finishReason);
                }
                yield $event;
                if (($event instanceof StepFinishEvent && $this->fault === 'duplicate-step')
                    || ($event instanceof StreamEndEvent && $this->fault === 'duplicate-end')) {
                    yield $event;
                }
            }
        }
    });
    $session = callBudgetSession()->usingProvider('openrouter');
    $observer = new CostObserver;
    $run = routerBillingContext($observer);
    Http::fakeSequence()->push(routerBillingReply(['is_byok' => true, 'cost' => 0,
        'cost_details' => ['upstream_inference_cost' => 0.004]], stream: true), 200, ['Content-Type' => 'text/event-stream']);
    Http::preventStrayRequests();
    callBudgetRun('stream', $session, $run);
    Http::assertSentCount(1);
    expect($observer->settlements)->toHaveCount(1)
        ->and($observer->settlements[0]->usageKnown)->toBeFalse()
        ->and($observer->settlements[0]->chargedUsd)->toBe($observer->reservations[0]->worstCaseUsd);
})->with(['missing-step', 'duplicate-step', 'null-final', 'duplicate-end']);

it('opts other providers into complete billing and refuses token fallback even with a completeness flag', function (): void {
    $run = RunContext::root('opt-in', new RunBudget(1, 1.0, maxOutputTokensPerCall: 10), [
        'custom' => ['model' => ['inputPerMTok' => 1, 'outputPerMTok' => 1, 'billing' => 'reported-complete']],
    ]);
    $price = CallBudget::forRun($run, 'custom', 'model', []);
    expect($price->costOf(new Usage(3, 2, cost: 0.004)))->toBeNull()
        ->and($price->costOf(new Usage(3, 2), ['openrouter_billing' => ['complete' => true]]))->toBeNull()
        ->and($price->costOf(new Usage(3, 2, cost: 0.0), ['openrouter_billing' => ['complete' => true]]))->toBe(0.0)
        ->and($price->costOf(new Usage(3, 2, cost: 0.004), ['openrouter_billing' => ['complete' => 'true']]))->toBeNull();
});

it('refuses invalid billing modes and disabling OpenRouter complete billing before requests', function (mixed $mode): void {
    config()->set('prism.providers.openrouter.api_key', 'owned-test-only');
    $session = callBudgetSession()->usingProvider('openrouter');
    $run = routerBillingContext(new CostObserver, extra: ['billing' => $mode]);
    Http::fake()->preventStrayRequests();
    expect(fn () => callBudgetRun('send', $session, $run))->toThrow(RunNotPermitted::class);
    Http::assertNothingSent();
})->with(['reported-or-tokens', 'typo', false]);

it('keeps complete billing when provider spelling differs in case like the core resolver allows', function (): void {
    $run = RunContext::root('case', new RunBudget(1, 1.0, maxOutputTokensPerCall: 10), [
        'OpenRouter' => ['model' => ['inputPerMTok' => 1, 'outputPerMTok' => 1]],
    ]);
    $price = CallBudget::forRun($run, 'OpenRouter', 'model', []);
    expect($price->requiresCompleteBilling)->toBeTrue()
        ->and($price->costOf(new Usage(3, 2, cost: 0.004)))->toBeNull();
});
