<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\CallRefused;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\Subagent;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\CostObserver;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

function observedBudget(CostObserver $observer, ?float $cap = 1.0): RunContext
{
    return RunContext::root('observed', new RunBudget(10, $cap, maxOutputTokensPerCall: 10),
        callBudgetContext()->pricing, observer: $observer);
}

it('orders host admission before every provider request and settles its exact ledger charge', function (string $shape): void {
    $session = callBudgetSession();
    $observer = new CostObserver;
    $run = observedBudget($observer);
    $fake = Prism::fake(callBudgetResponses($shape, true, [0.04, 0.05, 0.06]));
    $observer->before = function (CallReservation $call, RunContext $context) use ($observer, $fake, $run): void {
        $fake->assertCallCount(count($observer->reservations) - 1);
        expect($context)->toBe($run)->and($context->ledger->reservedCostUsd())->toBe($call->worstCaseUsd)
            ->and($call->worstCaseUsd)->toBe(0.1)->and($call->provider)->toBe('anthropic')
            ->and($call->model)->toBe('budget-model')->and($call->callId)->toMatch('/\A[0-9a-f]{32}\z/');
    };
    $observer->after = function (CallSettlement $call, RunContext $context) use ($observer, $fake): void {
        $fake->assertCallCount(count($observer->settlements));
        expect($context->ledger->reservedCostUsd())->toBe(0.0)
            ->and($context->ledger->costUsd())->toEqualWithDelta(array_sum(array_column($observer->settlements, 'chargedUsd')), 1e-9);
    };
    callBudgetRun($shape, $session, $run);
    $fake->assertCallCount(3);
    expect($observer->order)->toBe(['reserving', 'settled', 'reserving', 'settled', 'reserving', 'settled'])
        ->and(array_unique(array_column($observer->reservations, 'callId')))->toHaveCount(3);
    foreach ($observer->settlements as $index => $call) {
        expect($call->callId)->toBe($observer->reservations[$index]->callId)
            ->and($call->chargedUsd)->toBe([0.04, 0.05, 0.06][$index])->and($call->usageKnown)->toBeTrue()
            ->and($call->usage?->cost)->toBe($call->chargedUsd)->and($call->outcome)->toBe('completed')
            ->and($observer->reservations[$index]->kind)->toBe($shape === 'send' ? 'text' : $shape);
    }
})->with(['send', 'structured', 'stream']);

it('releases a host refusal without charging or settling and makes zero requests', function (string $shape, string $failureKind): void {
    $session = callBudgetSession();
    $observer = new CostObserver;
    $run = observedBudget($observer, 0.1);
    $failure = match ($failureKind) {
        'code' => new CallRefused('account_exhausted'),
        'error' => new TypeError('private host failure'),
        default => new RuntimeException('private host failure'),
    };
    $observer->before = static function () use ($failure): never {
        throw $failure;
    };
    $fake = Prism::fake(callBudgetResponses($shape, true, [0.04]));
    try {
        callBudgetRun($shape, $session, $run);
        $this->fail('The host refused before any request.');
    } catch (RunNotPermitted $caught) {
        expect($caught->code())->toBe($failureKind === 'code' ? 'account_exhausted' : 'call_refused_by_host')
            ->and($caught->getPrevious())->toBe($failure)->and($caught->getMessage())->not->toContain('private host failure');
    }
    $fake->assertCallCount(0);
    expect($observer->settlements)->toBe([])->and($run->ledger->reservedCostUsd())->toBe(0.0)
        ->and($run->ledger->costUsd())->toBe(0.0)->and($run->ledger->steps())->toBe(0)
        ->and($session->run()['failure'])->toBe('budget');
    // The refused reservation must leave the exact-fit allowance available.
    $observer->before = null;
    callBudgetRun($shape, $session, $run);
    $fake->assertCallCount(1);
    expect($observer->settlements)->toHaveCount(1)->and($run->ledger->costUsd())->toBe(0.04);
})->with(['send', 'structured', 'stream'])->with(['code', 'exception', 'error']);

it('validates host refusal codes without accepting trailing controls or arbitrary messages', function (): void {
    foreach (['', 'Upper', 'with-dash', 'a'.str_repeat('b', 64), "valid\n", 'private message'] as $code) {
        expect(fn () => new CallRefused($code))->toThrow(InvalidArgumentException::class);
    }
    expect((new CallRefused('a'.str_repeat('b', 63)))->code())->toHaveLength(64);
});

it('settles provider failures exactly once with the conservative ledger charge', function (string $shape): void {
    $session = callBudgetSession();
    $observer = new CostObserver;
    $run = observedBudget($observer);
    Prism::fake([TextResponseFake::make()]);
    Prism::text()->using('anthropic', 'budget-model')->withPrompt('consume')->asText();
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(Exception::class);
    expect($observer->settlements)->toHaveCount(1)->and($observer->order)->toBe(['reserving', 'settled']);
    $call = $observer->settlements[0];
    expect($call->outcome)->toBe('failed')->and($call->chargedUsd)->toBe(0.1)->and($call->usageKnown)->toBeFalse()
        ->and($call->usage)->toBeNull()->and($call->callId)->toBe($observer->reservations[0]->callId)
        ->and($run->ledger->costUsd())->toBe($call->chargedUsd)->and($run->ledger->reservedCostUsd())->toBe(0.0);
})->with(['send', 'structured', 'stream']);

it('settles an abandoned native generator exactly once with known or reserved usage', function (bool $known): void {
    $session = callBudgetSession();
    $observer = new CostObserver;
    $run = observedBudget($observer);
    Prism::fake(callBudgetResponses('send', true, [0.04]));
    $stream = $session->stream('go', context: $run);
    $stream->current();
    if ($known) {
        while (! $stream->current() instanceof StreamEndEvent) {
            $stream->next();
        }
    }
    expect($observer->settlements)->toBe([])->and($run->ledger->reservedCostUsd())->toBe(0.1);
    unset($stream);
    expect($observer->settlements)->toHaveCount(1)->and($observer->settlements[0]->outcome)->toBe('abandoned')
        ->and($observer->settlements[0]->chargedUsd)->toBe($known ? 0.04 : 0.1)
        ->and($observer->settlements[0]->usageKnown)->toBe($known)
        ->and($run->ledger->costUsd())->toBe($known ? 0.04 : 0.1)->and($run->ledger->reservedCostUsd())->toBe(0.0);
})->with([false, true]);

it('reports token-derived charged cost rather than a nullable provider cost', function (string $shape): void {
    $session = callBudgetSession();
    $observer = new CostObserver;
    $run = observedBudget($observer);
    Prism::fake(callBudgetResponses($shape, true, [null]));
    callBudgetRun($shape, $session, $run);
    expect($observer->settlements[0]->chargedUsd)->toBe(0.02)->and($observer->settlements[0]->usageKnown)->toBeTrue()
        ->and($observer->settlements[0]->usage?->cost)->toBeNull()->and($run->ledger->costUsd())->toBe(0.02);
})->with(['send', 'structured', 'stream']);

it('propagates settlement persistence failures after the ledger and completed transcript stand', function (string $shape): void {
    $session = callBudgetSession();
    $observer = new CostObserver;
    $run = observedBudget($observer);
    $failure = new RuntimeException('host persistence failed');
    $observer->after = static function () use ($failure): never {
        throw $failure;
    };
    $fake = Prism::fake(callBudgetResponses($shape, true, [0.04]));
    try {
        callBudgetRun($shape, $session, $run);
        $this->fail('Settlement failure must propagate.');
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    $fake->assertCallCount(1);
    expect($observer->settlements)->toHaveCount(1)->and($run->ledger->costUsd())->toBe(0.04)
        ->and($run->ledger->reservedCostUsd())->toBe(0.0)
        ->and($session->thread()->storedMessages()->where('type', 'assistant')->count())->toBe(1);
})->with(['send', 'structured', 'stream']);

it('refuses an observer on every uncapped API before provider requests', function (string $shape, bool $configured): void {
    $observer = new CostObserver;
    if ($configured) {
        app()->instance(CostObserver::class, $observer);
        config()->set('prism-harness.cost_observer', CostObserver::class);
    }
    $session = callBudgetSession();
    $run = $configured ? null : observedBudget($observer, null);
    $fake = Prism::fake(callBudgetResponses($shape, true, [0.04]));
    try {
        callBudgetRun($shape, $session, $run);
        $this->fail('An uncapped observer must never be silently ignored.');
    } catch (RunNotPermitted $caught) {
        expect($caught->code())->toBe('budget_observer_uncapped');
    }
    $fake->assertCallCount(0);
    expect($observer->reservations)->toBe([])->and($observer->settlements)->toBe([]);
})->with(['send', 'structured', 'stream'])->with([false, true]);

it('resolves the shipped optional config and inherits the effective observer through children', function (): void {
    $shipped = require __DIR__.'/../config/prism-harness.php';
    expect(array_key_exists('cost_observer', $shipped))->toBeTrue()->and($shipped['cost_observer'])->toBeNull();
    $observer = new CostObserver;
    app()->instance(CostObserver::class, $observer);
    config()->set('prism-harness.cost_observer', CostObserver::class);
    $observer->before = function (CallReservation $call, RunContext $run) use ($observer): void {
        $child = $run->forChild(new Subagent('child', 'Child', 'chat', new RunBudget(2, 0.5)), 'parent', null);
        expect($run->observer)->toBe($observer)->and($child->observer)->toBe($observer)
            ->and($child->ledger)->toBe($run->ledger);
    };
    $session = callBudgetSession();
    Prism::fake(callBudgetResponses('send', true, [0.04]));
    $session->send('go', context: callBudgetContext());
    expect($observer->settlements)->toHaveCount(1);
});

it('lets explicit context observers override config and keeps them on descendant calls', function (): void {
    config()->set('prism-harness.cost_observer', 'invalid-fallback-must-not-resolve');
    $observer = new CostObserver;
    $run = observedBudget($observer);
    $child = $run->forChild(new Subagent('child', 'Child', 'chat', new RunBudget(2, 0.5)), 'parent', null);
    $session = callBudgetSession();
    Prism::fake(callBudgetResponses('send', true, [0.04]));
    $session->send('go', context: $child);
    expect($child->observer)->toBe($observer)->and($observer->settlements)->toHaveCount(1);
    $direct = new RunContext($run->ledger, $child->budget, pricing: $run->pricing);
    expect($direct->observer)->toBe($observer);
    expect(fn () => new RunContext($run->ledger, $child->budget, observer: new CostObserver))
        ->toThrow(LogicException::class);
});

it('rejects invalid configured observer shapes before spending', function (mixed $configured): void {
    config()->set('prism-harness.cost_observer', $configured);
    $session = callBudgetSession();
    $fake = Prism::fake(callBudgetResponses('send', true, [0.04]));
    expect(fn () => $session->send('go', context: callBudgetContext()))->toThrow(InvalidArgumentException::class);
    $fake->assertCallCount(0);
})->with([[''], [123], [stdClass::class]]);

it('keeps settlement failures loud on provider failure and abandoned streams', function (string $outcome): void {
    $session = callBudgetSession();
    $observer = new CostObserver;
    $run = observedBudget($observer);
    $failure = new RuntimeException('host persistence failed');
    $observer->after = static function () use ($failure): never {
        throw $failure;
    };
    try {
        if ($outcome === 'abandoned') {
            Prism::fake(callBudgetResponses('send', true, [0.04]));
            $stream = $session->stream('go', context: $run);
            $stream->current();
            unset($stream);
        } else {
            Prism::fake([TextResponseFake::make()]);
            Prism::text()->using('anthropic', 'budget-model')->withPrompt('consume')->asText();
            $session->send('go', context: $run);
        }
        $this->fail('The host persistence failure must propagate.');
    } catch (RuntimeException $caught) {
        expect($caught)->toBe($failure);
    }
    expect($observer->settlements)->toHaveCount(1)->and($observer->settlements[0]->outcome)->toBe($outcome)
        ->and($run->ledger->costUsd())->toBe(0.1)->and($run->ledger->reservedCostUsd())->toBe(0.0);
})->with(['failed', 'abandoned']);

it('observes approval continuations after resolving the approved or denied side effect', function (string $decision): void {
    config()->set('prism.providers.anthropic.api_key', 'fixture-only');
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = callBudgetSession();
    $invoked = 0;
    replaceCallBudgetTool((new Tool)->as('tick')->for('Approved tool')->using(function () use (&$invoked): string {
        $invoked++;

        return 'resolved before observer';
    }));
    $session->thread()->record([new AssistantMessage('', [new ToolCall('approved', 'tick', [])],
        toolApprovalRequests: [new ToolApprovalRequest('approval', 'approved')])]);
    Http::fakeSequence()->push(budgetHttpReply(2, true));
    Http::preventStrayRequests();
    $observer = new CostObserver;
    $observer->before = function () use (&$invoked, $decision, $session): void {
        Http::assertSentCount(0);
        expect($invoked)->toBe($decision === 'deny' ? 0 : 1);
        expect($session->thread()->storedMessages()->where('type', 'tool_result')->count())->toBeGreaterThan(0);
    };
    $run = observedBudget($observer);
    match ($decision) {
        'approve' => $session->approve('approval', context: $run),
        'deny' => $session->deny('approval', 'denied', context: $run),
        default => $session->decide([new ToolApprovalResponse('approval', true)], context: $run),
    };
    Http::assertSentCount(1);
    expect($observer->settlements)->toHaveCount(1)->and($observer->settlements[0]->outcome)->toBe('completed');
})->with(['approve', 'deny', 'decide']);

it('reports each call charge independently when an observer invokes a nested child', function (): void {
    $parent = callBudgetSession('parent-observed');
    $child = callBudgetSession('child-observed');
    $observer = new CostObserver;
    $run = observedBudget($observer);
    $entered = false;
    $observer->before = function (CallReservation $call, RunContext $context) use (&$entered, $child): void {
        if ($entered) {
            return;
        }
        $entered = true;
        $nested = $context->forChild(new Subagent('child', 'Child', 'chat', new RunBudget(5, 0.5)), 'parent', null);
        $child->send('go', context: $nested);
    };
    $fake = Prism::fake([...callBudgetResponses('send', true, [0.03]), ...callBudgetResponses('send', true, [0.04])]);
    $parent->send('go', context: $run);
    $fake->assertCallCount(2);
    expect($observer->settlements)->toHaveCount(2)
        ->and($observer->settlements[0]->callId)->toBe($observer->reservations[1]->callId)
        ->and($observer->settlements[0]->chargedUsd)->toBe(0.03)
        ->and($observer->settlements[1]->callId)->toBe($observer->reservations[0]->callId)
        ->and($observer->settlements[1]->chargedUsd)->toBe(0.04)
        ->and($run->ledger->costUsd())->toBe(0.07)->and($run->ledger->reservedCostUsd())->toBe(0.0);
});
