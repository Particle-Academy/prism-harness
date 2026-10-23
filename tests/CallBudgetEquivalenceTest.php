<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Context\SummarisingCompaction;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;

require_once __DIR__.'/Support/CallBudgetFixtures.php';

require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

it('keeps a nonbinding capped run equivalent to the legacy provider call sequence and transcript', function (string $shape): void {
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    $results = [];
    foreach ([false, true] as $capped) {
        $session = callBudgetSession($capped ? 'capped' : 'legacy');
        // A fresh transport per leg: Http::fake merges callbacks, so reusing
        // the facade would leave the first leg's exhausted sequence installed.
        Http::swap(new Factory);
        $sequence = Http::fakeSequence();
        foreach ([1, 2, 3] as $index) {
            $sequence->push($shape === 'stream' ? budgetSseReply($index, $index === 3) : budgetHttpReply($index, $index === 3),
                200, $shape === 'stream' ? ['Content-Type' => 'text/event-stream'] : []);
        }
        Http::preventStrayRequests();
        $context = RunContext::root('equivalence', new RunBudget(8, $capped ? 100.0 : null, maxOutputTokensPerCall: 1000),
            ['anthropic' => ['budget-model' => ['inputPerMTok' => 2, 'outputPerMTok' => 10,
                'cacheWritePerMTok' => 2.5, 'cacheReadPerMTok' => 0.1, 'toolOverheadTokens' => 286]]]);
        callBudgetRun($shape, $session, $context);
        Http::assertSentCount(3);
        $results[] = [
            'calls' => Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->all(),
            'transcript' => $session->thread()->storedMessages()->orderBy('position')->get()
                ->map(fn ($row): array => ['type' => $row->type, 'payload' => $row->payload])->all(),
        ];
    }
    expect(json_encode($results[1], JSON_THROW_ON_ERROR))->toBe(json_encode($results[0], JSON_THROW_ON_ERROR));
})->with(['send', 'structured', 'stream']);

it('forwards the owner budget through the public approval resume methods', function (string $method): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = callBudgetSession();
    $invoked = 0;
    app(ToolRegistry::class)->register((new Tool)->as('tick')->for('Approved tool')
        ->using(function () use (&$invoked): string {
            $invoked++;

            return 'resolved';
        }));
    $session->thread()->record([
        new AssistantMessage('', [new ToolCall('approved', 'tick', [])],
            toolApprovalRequests: [new ToolApprovalRequest('approval', 'approved')]),
    ]);
    $run = callBudgetContext(0.0);
    $fake = Prism::fake([TextResponseFake::make()]);
    expect(fn () => match ($method) {
        'approve' => $session->approve('approval', context: $run),
        'deny' => $session->deny('approval', context: $run),
        'decide' => $session->decide([new ToolApprovalResponse('approval', true)], context: $run),
    })->toThrow(RunNotPermitted::class);
    $fake->assertCallCount(0);
    expect($invoked)->toBe($method === 'deny' ? 0 : 1)->and($run->ledger->reservedCostUsd())->toBe(0.0);
})->with(['approve', 'deny', 'decide']);

it('rejects a reentrant child before its request while the parent model call is reserved', function (): void {
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    $parent = callBudgetSession('parent');
    $child = callBudgetSession('child');
    $run = callBudgetContext();
    $childContext = new RunContext($run->ledger, (new RunBudget(5, 0.17, maxOutputTokensPerCall: 10))
        ->nestedWithin($run->budget, $run->ledger), pricing: $run->pricing);
    $reason = null;
    app(ToolRegistry::class)->register((new Tool)->as('tick')->for('Call the child')
        ->using(function () use ($child, $childContext, $run, &$reason): string {
            expect($run->ledger->reservedCostUsd())->toBe(0.1);
            try {
                $child->send('go', context: $childContext);
            } catch (RunNotPermitted $failure) {
                $reason = $failure->code();
            }

            return 'child refused';
        }));
    Http::fakeSequence()->push(budgetHttpReply(1))->push(budgetHttpReply(2, true));
    Http::preventStrayRequests();
    $parent->send('go', context: $run);
    Http::assertSentCount(2);
    expect($reason)->toBe('call_budget_exceeded')
        ->and($child->run()['failure'])->toBe('budget')
        ->and($run->ledger->reservedCostUsd())->toBe(0.0)
        ->and($run->ledger->costUsd())->toBe(0.04);
});

it('still performs configured summarising compaction for an uncapped session', function (): void {
    $session = callBudgetSession();
    $session->thread()->record([new UserMessage('old'), new AssistantMessage('old answer'), new UserMessage('recent')]);
    app()->instance(CompactionStrategy::class, new SummarisingCompaction(keep: 1));
    $fake = Prism::fake([TextResponseFake::make()->withText('summary'), TextResponseFake::make()->withText('answer')]);
    $session->send('go');
    $fake->assertCallCount(2);
    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->messages()[0]->content)->toContain('Summarise')
            ->and($requests[1]->messages()[0]->content)->toContain('[conversation-so-far] summary');
    });
});

it('stops for approval and stores the original approval identity on capped text and streams', function (string $shape): void {
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = callBudgetSession();
    $invoked = 0;
    app(ToolRegistry::class)->register((new Tool)->as('tick')->for('Needs approval')
        ->using(function () use (&$invoked): string {
            $invoked++;

            return 'must wait';
        }));
    Http::fakeSequence()->push($shape === 'stream' ? budgetSseReply(1) : budgetHttpReply(1));
    Http::preventStrayRequests();
    Event::fake([RunFinished::class]);
    $run = callBudgetContext();
    if ($shape === 'send') {
        $response = $session->send('go', context: $run);
        expect($response->awaitingApproval())->toBeTrue()->and($response->pendingApprovals())->toHaveCount(1);
    } else {
        foreach ($session->stream('go', context: $run) as $event) {
        }
    }
    Http::assertSentCount(1);
    $pending = $session->thread()->storedMessages()->where('type', 'assistant')->firstOrFail()->payload['tool_approval_requests'];
    expect($invoked)->toBe(0)->and($pending)->toHaveCount(1)
        ->and($pending[0]['tool_call_id'])->toBe('call-1')
        ->and($run->ledger->reservedCostUsd())->toBe(0.0);
    Event::assertDispatched(RunFinished::class, fn (RunFinished $event): bool => $event->awaitingApproval);
})->with(['send', 'stream']);

it('bounds approved tool results before the resumed model call and never executes them twice', function (string $shape): void {
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = callBudgetSession();
    $invoked = 0;
    app(ToolRegistry::class)->register((new Tool)->as('tick')->for('Approved read')
        ->using(function () use (&$invoked): string {
            $invoked++;

            return str_repeat('€', 10000);
        }));
    $session->thread()->record([
        new UserMessage('Read it'),
        new AssistantMessage('', [new ToolCall('approved-call', 'tick', [])],
            toolApprovalRequests: [new ToolApprovalRequest('approval', 'approved-call')]),
        new ToolResultMessage(toolApprovalResponses: [
            new ToolApprovalResponse('approval', true),
        ]),
    ]);
    $run = RunContext::root('resume', new RunBudget(10, 0.01, maxOutputTokensPerCall: 10), [
        'anthropic' => ['budget-model' => ['inputPerMTok' => 1, 'outputPerMTok' => 0, 'toolOverheadTokens' => 286]],
    ]);
    Http::fakeSequence()->push($shape === 'stream' ? budgetSseReply(2, true) : budgetHttpReply(2, true));
    Http::preventStrayRequests();
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            callBudgetRun($shape, $session, $run);
            $this->fail('The approved result makes the actual input exceed the cap.');
        } catch (RunNotPermitted $failure) {
            expect($failure->code())->toBe('call_budget_exceeded');
        }
    }
    Http::assertSentCount(0);
    // A later, deliberately larger allowance admits the already-resolved
    // input. Core must skip the persisted result, not re-run the side effect.
    callBudgetRun($shape, $session, new RunContext($run->ledger,
        new RunBudget(10, 1.0, maxOutputTokensPerCall: 10), pricing: $run->pricing));
    Http::assertSentCount(1);
    $results = $session->thread()->storedMessages()->where('type', 'tool_result')->get()
        ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
        ->where('tool_call_id', 'approved-call')->values();
    expect($invoked)->toBe(1)->and($results)->toHaveCount(1)
        ->and(strlen($results[0]['result']))->toBe(30000)
        ->and($run->ledger->steps())->toBe(1)->and($run->ledger->reservedCostUsd())->toBe(0.0);
})->with(['send', 'structured', 'stream']);

it('writes approved stream results before yielding resolution events and survives abandonment there', function (): void {
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = callBudgetSession();
    $invoked = 0;
    app(ToolRegistry::class)->register((new Tool)->as('tick')->for('Approved tool')
        ->using(function () use (&$invoked): string {
            $invoked++;

            return 'resolved';
        }));
    $session->thread()->record([
        new AssistantMessage('', [new ToolCall('approved', 'tick', [])],
            toolApprovalRequests: [new ToolApprovalRequest('approval', 'approved')]),
        new ToolResultMessage(toolApprovalResponses: [
            new ToolApprovalResponse('approval', true),
        ]),
    ]);
    Http::fakeSequence()->push(budgetSseReply(2, true));
    Http::preventStrayRequests();
    $run = callBudgetContext();
    $stream = $session->stream('go', context: $run);
    expect($stream->current())->toBeInstanceOf(ToolResultEvent::class);
    Http::assertSentCount(0);
    expect($invoked)->toBe(1)
        ->and(json_encode($session->thread()->storedMessages()->get()->pluck('payload')->all()))->toContain('resolved');
    unset($stream);
    expect($run->ledger->reservedCostUsd())->toBe(0.0)->and($run->ledger->unmeteredRuns())->toBe(0);
    foreach ($session->stream('go', context: $run) as $event) {
    }
    Http::assertSentCount(1);
    expect($invoked)->toBe(1)->and($run->ledger->reservedCostUsd())->toBe(0.0);
});

it('honors tree cancellation before executing an already-approved side effect', function (string $shape): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = callBudgetSession();
    $invoked = 0;
    app(ToolRegistry::class)->register((new Tool)->as('tick')->for('Approved tool')
        ->using(function () use (&$invoked): string {
            $invoked++;

            return 'must not run';
        }));
    $session->thread()->record([
        new AssistantMessage('', [new ToolCall('approved', 'tick', [])],
            toolApprovalRequests: [new ToolApprovalRequest('approval', 'approved')]),
        new ToolResultMessage(toolApprovalResponses: [
            new ToolApprovalResponse('approval', true),
        ]),
    ]);
    $run = callBudgetContext();
    $run->ledger->cancel('cancelled');
    $fake = Prism::fake([TextResponseFake::make()]);
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(RunNotPermitted::class);
    $fake->assertCallCount(0);
    expect($invoked)->toBe(0)->and($run->ledger->reservedCostUsd())->toBe(0.0);
})->with(['send', 'structured', 'stream']);
