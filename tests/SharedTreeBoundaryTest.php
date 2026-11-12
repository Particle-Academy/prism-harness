<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'synthetic-test-key');
    config()->set('prism-harness.agent.max_cost_usd', null);
    Http::preventStrayRequests();
});

it('charges the parent before its tool runs and refuses the next call after cancellation', function (string $shape): void {
    $session = callBudgetSession('tree-cancel-'.$shape);
    $run = RunContext::root('synthetic-cancel', new RunBudget(6));
    $invoked = 0;
    replaceCallBudgetTool((new Tool)->as('tick')->for('Count')->using(function () use ($run, &$invoked): string {
        expect($run->ledger->steps())->toBe(1);
        $invoked++;
        $run->ledger->cancel('synthetic host cancellation');

        return 'completed before cancellation';
    }));
    Http::fakeSequence()->push($shape === 'stream' ? budgetSseReply(1) : budgetHttpReply(1),
        200, $shape === 'stream' ? ['Content-Type' => 'text/event-stream'] : []);
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(RunNotPermitted::class);
    Http::assertSentCount(1);
    expect($invoked)->toBe(1)->and($run->ledger->steps())->toBe(1)
        ->and($session->run()['status'])->toBe('failed')
        ->and($session->thread()->storedMessages()->orderBy('position')->get()->pluck('type')->all())
        ->toBe($shape === 'structured'
            ? ['user', 'assistant', 'tool_result', 'assistant']
            : ['user', 'assistant', 'tool_result']);
})->with(['send', 'structured', 'stream']);

it('rechecks cancellation after resolving and persisting an approved side effect', function (string $shape): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = callBudgetSession('tree-approved-cancel-'.$shape);
    $run = RunContext::root('synthetic-approved-cancel', new RunBudget(6));
    $invoked = 0;
    replaceCallBudgetTool((new Tool)->as('tick')->for('Approved')->using(function () use ($run, &$invoked): string {
        $invoked++;
        $run->ledger->cancel('synthetic cancellation during approved work');

        return 'completed approved work';
    }));
    $session->thread()->record([
        new AssistantMessage('', [new ToolCall('approved-call', 'tick', [])],
            toolApprovalRequests: [new ToolApprovalRequest('approval', 'approved-call')]),
        new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse('approval', true)]),
    ]);
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(RunNotPermitted::class);
    Http::assertNothingSent();
    $results = $session->thread()->storedMessages()->where('type', 'tool_result')->get()
        ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
        ->where('tool_call_id', 'approved-call');
    expect($invoked)->toBe(1)->and($run->ledger->steps())->toBe(0)
        ->and($results)->toHaveCount(1)
        ->and($results->first()['result'])->toBe('completed approved work');
})->with(['send', 'structured', 'stream']);

it('respects exhausted and expired shared trees before any HTTP request', function (string $shape, string $cause): void {
    $session = callBudgetSession('tree-refuse-'.$shape.'-'.$cause);
    $run = RunContext::root('synthetic-refuse', new RunBudget(6, maxSeconds: $cause === 'deadline' ? 0 : null));
    if ($cause === 'steps') {
        $run->ledger->recordSteps(6);
    }
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(RunNotPermitted::class);
    Http::assertNothingSent();
    expect($session->thread()->storedMessages()->count())->toBe(0);
})->with(['send', 'structured', 'stream'])->with(['steps', 'deadline']);

it('stops an uncapped shared tree for approval without executing the tool', function (string $shape): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['tick']);
    $session = callBudgetSession('tree-approval-'.$shape);
    $run = RunContext::root('synthetic-approval', new RunBudget(6));
    $invoked = 0;
    replaceCallBudgetTool((new Tool)->as('tick')->for('Count')->using(function () use (&$invoked): string {
        $invoked++;

        return 'must not execute';
    }));
    Http::fakeSequence()->push($shape === 'stream' ? budgetSseReply(1) : budgetHttpReply(1),
        200, $shape === 'stream' ? ['Content-Type' => 'text/event-stream'] : []);
    callBudgetRun($shape, $session, $run);
    Http::assertSentCount(1);
    $assistant = $session->thread()->storedMessages()->where('type', 'assistant')->firstOrFail();
    expect($invoked)->toBe(0)->and($run->ledger->steps())->toBe(1)
        ->and($assistant->payload['tool_approval_requests'])->toHaveCount(1)
        ->and($assistant->payload['tool_approval_requests'][0]['tool_call_id'])->toBe('call-1');
})->with(['send', 'stream']);

it('counts an abandoned shared stream request once and closes the run', function (): void {
    $session = callBudgetSession('tree-abandoned');
    $run = RunContext::root('synthetic-abandoned', new RunBudget(6));
    Http::fakeSequence()->push(budgetSseReply(1), 200, ['Content-Type' => 'text/event-stream']);
    $stream = $session->stream('go', context: $run);
    while ($stream->valid() && ! $stream->current() instanceof TextDeltaEvent) {
        $stream->next();
    }
    expect($stream->current())->toBeInstanceOf(TextDeltaEvent::class);
    unset($stream);
    Http::assertSentCount(1);
    expect($run->ledger->steps())->toBe(1)->and($session->run()['status'])->toBe('failed')
        ->and($session->thread()->storedMessages()->count())->toBe(1);
});

it('charges a failed native request once and refuses to reopen its exhausted tree', function (string $shape): void {
    $session = callBudgetSession('tree-provider-failure-'.$shape);
    $run = RunContext::root('synthetic-provider-failure', new RunBudget(1));
    Http::fakeSequence()->push(['error' => ['type' => 'overloaded_error', 'message' => 'synthetic unavailable']], 529);
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(PrismException::class);
    Http::assertSentCount(1);
    expect($run->ledger->steps())->toBe(1)->and($session->run()['status'])->toBe('failed');
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(RunNotPermitted::class);
    Http::assertSentCount(1);
})->with(['send', 'structured', 'stream']);
