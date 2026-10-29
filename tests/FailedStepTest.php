<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Tests\Fixtures\CostObserver;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

it('does not buy another step after a failed stream with answered calls', function (string $fault, FinishReason $expected, bool $capped): void {
    $provider = new class(callBudgetResponses('stream', true, [0.04, 0.04])) extends PrismFake
    {
        public string $fault = 'error';

        public function stream(Request $request): Generator
        {
            $first = $this->recorded === [];
            foreach (parent::stream($request) as $event) {
                if ($first && $event instanceof StreamEndEvent) {
                    if ($this->fault === 'error') {
                        yield new ErrorEvent('failure', time(), 'provider_error', 'Owned failure', true);
                    }
                    $finish = match ($this->fault) {
                        'finish-error' => FinishReason::Error,
                        'finish-unknown' => FinishReason::Unknown,
                        default => FinishReason::Stop,
                    };
                    yield new StreamEndEvent($event->id, $event->timestamp, $finish,
                        $this->fault === 'missing-usage' ? null : $event->usage);
                } else {
                    yield $event;
                }
            }
        }
    };
    $provider->fault = $fault;
    app(PrismManager::class)->extend('anthropic', fn () => $provider);
    $observer = new CostObserver;
    $base = callBudgetContext(1.0);
    $run = new RunContext($base->ledger, $base->budget, pricing: $base->pricing, observer: $observer);
    $session = callBudgetSession();
    Event::fake([RunFailed::class, RunFinished::class]);
    foreach ($session->stream('go', context: $capped ? $run : null) as $event) {
    }

    $provider->assertCallCount(1);
    if ($capped) {
        expect($run->ledger->steps())->toBe(1)
            ->and($observer->reservations)->toHaveCount(1)
            ->and($observer->settlements)->toHaveCount(1)
            ->and($observer->settlements[0]->outcome)->toBe('failed')
            ->and($run->ledger->reservedCostUsd())->toBe(0.0)
            ->and($run->ledger->costUsd())->toBe($observer->reservations[0]->worstCaseUsd);
    }
    expect($session->run()['status'])->toBe('failed')
        ->and($session->run()['failure'])->toBe($expected->value);
    Event::assertDispatched(RunFailed::class, 1);
    Event::assertNotDispatched(RunFinished::class);
})->with([
    'recoverable error then old-core Stop' => ['error', FinishReason::Error],
    'Error finish' => ['finish-error', FinishReason::Error],
    'Unknown finish' => ['finish-unknown', FinishReason::Unknown],
    'Stop without final usage' => ['missing-usage', FinishReason::Unknown],
])->with([true, false]);

it('still continues a successful old-core Stop with answered tools', function (): void {
    $provider = new class(callBudgetResponses('stream', true, [0.04, 0.04])) extends PrismFake
    {
        public function stream(Request $request): Generator
        {
            foreach (parent::stream($request) as $event) {
                yield $event instanceof StreamEndEvent
                    ? new StreamEndEvent($event->id, $event->timestamp, FinishReason::Stop, $event->usage)
                    : $event;
            }
        }
    };
    app(PrismManager::class)->extend('anthropic', fn () => $provider);
    $observer = new CostObserver;
    $base = callBudgetContext(1.0);
    $run = new RunContext($base->ledger, $base->budget, pricing: $base->pricing, observer: $observer);
    $session = callBudgetSession();
    foreach ($session->stream('go', context: $run) as $event) {
    }
    $provider->assertCallCount(2);
    expect($run->ledger->steps())->toBe(2)
        ->and($observer->settlements)->toHaveCount(2)
        ->and(array_column($observer->settlements, 'outcome'))->toBe(['completed', 'completed'])
        ->and($session->run()['status'])->toBe('completed');
});

it('executes a valid approved call but never one from a failed step', function (bool $failed): void {
    $provider = new class(callBudgetResponses('stream', true, [0.04, 0.04])) extends PrismFake
    {
        public bool $failed = false;

        public function stream(Request $request): Generator
        {
            $first = $this->recorded === [];
            foreach (parent::stream($request) as $event) {
                if ($first && $event instanceof ToolResultEvent) {
                    continue;
                }
                if ($first && $event instanceof StreamEndEvent) {
                    if ($this->failed) {
                        yield new ErrorEvent('failure', time(), 'provider_error', 'Owned failure', true);
                    }
                    yield new StreamEndEvent($event->id, $event->timestamp, FinishReason::Stop, $event->usage);
                } else {
                    yield $event;
                    if ($first && $event instanceof ToolCallEvent) {
                        yield new ToolApprovalRequestEvent('approval', time(), $event->toolCall, $event->messageId, 'failed-approval');
                    }
                }
            }
        }
    };
    $provider->failed = $failed;
    app(PrismManager::class)->extend('anthropic', fn () => $provider);
    $observer = new CostObserver;
    $base = callBudgetContext(1.0);
    $run = new RunContext($base->ledger, $base->budget, pricing: $base->pricing, observer: $observer);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = callBudgetSession();
    $tools = 0;
    replaceCallBudgetTool((new Tool)->as('tick')->for('Count')->using(function () use (&$tools): string {
        $tools++;

        return 'unexpected';
    }));
    foreach ($session->stream('go', context: $run) as $event) {
    }
    $provider->assertCallCount(1);
    expect($observer->settlements)->toHaveCount(1);
    $session->approve('failed-approval', context: $run);
    expect($tools)->toBe($failed ? 0 : 1);
})->with([false, true]);

it('keeps text and structured failed results terminal and reports a failed run', function (string $shape, FinishReason $finish): void {
    $session = callBudgetSession();
    $observer = new CostObserver;
    $base = callBudgetContext(1.0);
    $run = new RunContext($base->ledger, $base->budget, pricing: $base->pricing, observer: $observer);
    $responses = callBudgetResponses($shape, true, [0.04, 0.04]);
    $first = $responses[0];
    $failed = $shape === 'structured'
        ? new StructuredResponse(collect(), '{"ok":true}', ['ok' => true], $finish,
            $first->usage, $first->meta, $first->toolCalls, toolResults: $first->toolResults)
        : new TextResponse(collect(), 'partial', $finish, $first->toolCalls,
            $first->toolResults, $first->usage, $first->meta, $first->messages);
    $fake = Prism::fake([$failed, $responses[1]]);
    Event::fake([RunFailed::class, RunFinished::class]);
    callBudgetRun($shape, $session, $run);
    $fake->assertCallCount(1);
    expect($run->ledger->steps())->toBe(1)
        ->and($observer->settlements)->toHaveCount(1)
        ->and($run->ledger->costUsd())->toBe(0.04)
        ->and($session->run()['status'])->toBe('failed')
        ->and($session->run()['failure'])->toBe($finish->value);
    Event::assertDispatched(RunFailed::class, 1);
    Event::assertNotDispatched(RunFinished::class);
})->with(['send', 'structured'])->with([FinishReason::Error, FinishReason::Unknown]);

it('does not invoke a tool after an actual provider stream has reported an error', function (): void {
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    $session = callBudgetSession();
    $tools = 0;
    replaceCallBudgetTool((new Tool)->as('tick')->for('Count')->using(function () use (&$tools): string {
        $tools++;

        return 'unexpected';
    }));
    $parts = array_filter(explode("\n\n", trim(budgetSseReply(1))), function (string $part): bool {
        $data = json_decode(substr($part, strpos($part, 'data: ') + 6), true);

        return ! in_array($data['type'], ['message_delta', 'message_stop'], true);
    });
    $parts[] = 'event: error'."\ndata: ".json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Owned failure']]);
    Http::preventStrayRequests();
    Http::fakeSequence()->push(implode("\n\n", $parts)."\n\n", 200, ['Content-Type' => 'text/event-stream'])
        ->push(budgetSseReply(2, true), 200, ['Content-Type' => 'text/event-stream']);
    $observer = new CostObserver;
    $base = callBudgetContext(1.0);
    $run = new RunContext($base->ledger, $base->budget, pricing: $base->pricing, observer: $observer);
    $sawError = false;
    foreach ($session->stream('go', context: $run) as $event) {
        $sawError = $sawError || $event instanceof ErrorEvent;
    }
    expect($sawError)->toBeTrue()->and($tools)->toBe(0)
        ->and($run->ledger->steps())->toBe(1)->and($observer->settlements)->toHaveCount(1);
    Http::assertSentCount(1);
});
