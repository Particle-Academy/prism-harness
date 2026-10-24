<?php

declare(strict_types=1);

use Prism\Harness\Context\SummarisingCompaction;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\Subagent;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;

require_once __DIR__.'/Support/CallBudgetFixtures.php';

it('preserves the existing exception constructor and chaining contract', function (): void {
    $previous = new RuntimeException('cause');
    $failure = new RunNotPermitted('message', 123, $previous);
    expect($failure->getMessage())->toBe('message')->and($failure->getCode())->toBe(123)
        ->and($failure->getPrevious())->toBe($previous)->and($failure->code())->toBe('run_not_permitted');
});

it('refuses the third model call and retains both completed calls on every API', function (string $shape): void {
    $session = callBudgetSession();
    $run = callBudgetContext();
    $fake = Prism::fake(callBudgetResponses($shape, true, [0.04, 0.04, 0.04, 0.04]));
    try {
        callBudgetRun($shape, $session, $run);
        $this->fail('The third call must not be made.');
    } catch (RunNotPermitted $failure) {
        expect($failure->code())->toBe('call_budget_exceeded');
    }
    $fake->assertCallCount(2);
    $fake->assertRequest(function (array $requests): void {
        foreach ($requests as $request) {
            expect($request->maxSteps())->toBe(1)->and($request->maxTokens())->toBe(10);
        }
    });
    $rows = $session->thread()->storedMessages()->orderBy('position')->get();
    $serialized = json_encode($rows->pluck('payload')->all());
    expect($run->ledger->costUsd())->toBe(0.08)->and($run->ledger->steps())->toBe(2)
        ->and($run->ledger->reservedCostUsd())->toBe(0.0)
        ->and($session->run()['failure'])->toBe('budget')
        ->and($serialized)->toContain('result 1', 'result 2', 'call-1', 'call-2')->not->toContain('result 3');
})->with(['send', 'structured', 'stream']);

it('admits a call whose worst case exactly fits the remaining allowance', function (): void {
    $session = callBudgetSession();
    $run = callBudgetContext(0.1);
    $fake = Prism::fake(callBudgetResponses('send', true, [0.04]));
    $session->send('go', context: $run);
    $fake->assertCallCount(1);
    expect($run->ledger->costUsd())->toBe(0.04)->and($run->ledger->reservedCostUsd())->toBe(0.0);
});

it('counts calls even when provider fakes omit their internal step collection', function (string $shape): void {
    $session = callBudgetSession();
    $run = callBudgetContext();
    $fixtures = [];
    foreach (callBudgetResponses($shape, true, [0.04, 0.04, 0.04, 0.04]) as $response) {
        $fixtures[] = $response instanceof StructuredResponse
            ? new StructuredResponse(collect(), $response->text, $response->structured, $response->finishReason,
                $response->usage, $response->meta, $response->toolCalls, toolResults: $response->toolResults)
            : new TextResponse(collect(), $response->text, $response->finishReason, $response->toolCalls,
                $response->toolResults, $response->usage, $response->meta, $response->messages);
    }
    $fake = Prism::fake($fixtures);
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(RunNotPermitted::class);
    $fake->assertCallCount(2);
    expect($run->ledger->steps())->toBe(2)->and($run->ledger->costUsd())->toBe(0.08);
})->with(['send', 'structured']);

it('admits a genuinely free call at a zero-dollar cap', function (): void {
    $session = callBudgetSession();
    $run = RunContext::root('free', new RunBudget(1, 0.0, maxOutputTokensPerCall: 10), [
        'anthropic' => ['budget-model' => ['inputPerMTok' => 0, 'outputPerMTok' => 0, 'toolOverheadTokens' => 286]],
    ]);
    $fake = Prism::fake(callBudgetResponses('send', true, [0.0]));
    $session->send('go', context: $run);
    $fake->assertCallCount(1);
    expect($run->ledger->costUsd())->toBe(0.0);
});

it('charges token-derived cost after each call before considering the next one', function (string $shape): void {
    $session = callBudgetSession();
    $run = callBudgetContext(0.13);
    $fake = Prism::fake(callBudgetResponses($shape, true, [null, null, null, null]));
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(RunNotPermitted::class);
    $fake->assertCallCount(2);
    expect($run->ledger->costUsd())->toBe(0.04)->and($run->ledger->unmeteredRuns())->toBe(0);
})->with(['send', 'structured', 'stream']);

it('stops a one-dollar page before a second one-dollar worst case under a ten-dollar root', function (): void {
    $session = callBudgetSession();
    $root = RunContext::root('build', new RunBudget(100, 10.0, maxOutputTokensPerCall: 100), callBudgetContext()->pricing);
    $page = new RunContext($root->ledger, (new RunBudget(10, 1.0, maxOutputTokensPerCall: 100))
        ->nestedWithin($root->budget, $root->ledger), pricing: $root->pricing);
    $fake = Prism::fake(callBudgetResponses('send', true, [0.04, 0.04]));
    expect(fn () => $session->send('go', context: $page))->toThrow(RunNotPermitted::class);
    $fake->assertCallCount(1);
    expect($root->ledger->costUsd())->toBe(0.04);
});

it('inherits the stricter per-call output ceiling and pricing through child contexts', function (): void {
    $root = callBudgetContext();
    $child = $root->forChild(new Subagent('page', 'Page', 'chat',
        RunBudget::fromArray(['max_steps' => 5, 'max_cost_usd' => 1.0, 'max_output_tokens_per_call' => 100])), 'parent', null);
    expect($child->budget->maxOutputTokensPerCall)->toBe(10)
        ->and($child->pricing)->toBe($root->pricing)->and($child->ledger)->toBe($root->ledger);
});

it('refuses an unpriceable or unbounded call before any provider request', function (string $reason, string $shape): void {
    $session = callBudgetSession();
    $run = callBudgetContext(maxOutput: $reason === 'budget_unbounded_output' ? null : 10);
    if ($reason === 'budget_unpriced') {
        $run = RunContext::root('root', $run->budget);
    }
    $attachments = match ($reason) {
        'image' => [Image::fromBase64('eA==', 'image/png')],
        'document' => [Document::fromText('brief', 'Brief')],
        default => [],
    };
    $fake = Prism::fake([TextResponseFake::make()]);
    try {
        callBudgetRun($shape, $session, $run, $attachments);
        $this->fail('No provider request is permitted.');
    } catch (RunNotPermitted $failure) {
        expect($failure->code())->toBe(in_array($reason, ['image', 'document']) ? 'budget_input_unbounded' : $reason);
    }
    $fake->assertCallCount(0);
    expect($run->ledger->steps())->toBe(0)->and($run->ledger->reservedCostUsd())->toBe(0.0);
})->with(['budget_unpriced', 'budget_unbounded_output', 'image', 'document'])->with(['send', 'structured', 'stream']);

it('keeps page and session ceilings on the same shared ledger', function (float $spent, float $pageCap): void {
    $session = callBudgetSession();
    $root = callBudgetContext(10.0);
    $root->ledger->recordCost($spent);
    $page = new RunContext($root->ledger, (new RunBudget(10, $pageCap, maxOutputTokensPerCall: 10))
        ->nestedWithin($root->budget, $root->ledger), pricing: $root->pricing);
    $fake = Prism::fake(callBudgetResponses('send', true, [0.04]));
    expect(fn () => $session->send('go', context: $page))->toThrow(RunNotPermitted::class);
    $fake->assertCallCount(0);
    expect($root->ledger->costUsd())->toBe($spent);
})->with([[0.0, 0.09], [9.95, 1.0]]);

it('allows a one-dollar page child under a ten-dollar session', function (): void {
    $session = callBudgetSession();
    $root = callBudgetContext(10.0);
    $root->ledger->recordCost(8.0);
    $child = new RunContext($root->ledger, (new RunBudget(5, 1.0, maxOutputTokensPerCall: 10))
        ->nestedWithin($root->budget, $root->ledger), pricing: $root->pricing);
    $fake = Prism::fake(callBudgetResponses('send', true, [0.04]));
    $session->send('go', context: $child);
    $fake->assertCallCount(1);
    expect($root->ledger->costUsd())->toBe(8.04);
});

it('charges a failed capped request its reserved worst case and preserves the remaining allowance', function (string $shape): void {
    $session = callBudgetSession();
    $run = callBudgetContext(0.23);
    Prism::fake([TextResponseFake::make()]);
    Prism::text()->using('anthropic', 'budget-model')->withPrompt('consume')->asText();
    expect(fn () => callBudgetRun($shape, $session, $run))->toThrow(Exception::class);
    expect($run->ledger->reservedCostUsd())->toBe(0.0)->and($run->ledger->costUsd())->toBe(0.1)
        ->and($run->ledger->unmeteredRuns())->toBe(0);

    $fake = Prism::fake(array_merge(callBudgetResponses($shape, true, [0.04]), callBudgetResponses($shape, true, [0.04])));
    callBudgetRun($shape, $session, $run);
    $fake->assertCallCount(1);
    expect($run->ledger->costUsd())->toEqualWithDelta(0.14, 0.00000001);
    try {
        callBudgetRun($shape, $session, $run);
        $this->fail('The failed call must leave too little for another $0.10 reservation.');
    } catch (RunNotPermitted $failure) {
        expect($failure->code())->toBe('call_budget_exceeded');
    }
    $fake->assertCallCount(1);
    expect($run->ledger->reservedCostUsd())->toBe(0.0)->and($run->ledger->unmeteredRuns())->toBe(0);
})->with(['send', 'structured', 'stream']);

it('releases an abandoned stream reservation with its known usage when available', function (bool $known): void {
    $session = callBudgetSession();
    $run = callBudgetContext(0.23);
    Prism::fake(callBudgetResponses('send', true, [0.04]));
    $stream = $session->stream('go', context: $run);
    $stream->current();
    expect($run->ledger->reservedCostUsd())->toBe(0.1);
    if ($known) {
        while (! $stream->current() instanceof StreamEndEvent) {
            $stream->next();
        }
    }
    unset($stream);
    expect($run->ledger->reservedCostUsd())->toBe(0.0)
        ->and($run->ledger->costUsd())->toBe($known ? 0.04 : 0.1)
        ->and($run->ledger->unmeteredRuns())->toBe(0);

    $fake = Prism::fake(array_merge(callBudgetResponses('send', true, [0.04]), callBudgetResponses('send', true, [0.04])));
    callBudgetRun('stream', $session, $run);
    $fake->assertCallCount(1);
    expect($run->ledger->costUsd())->toEqualWithDelta($known ? 0.08 : 0.14, 0.00000001);
    $larger = new RunContext($run->ledger, new RunBudget(10, 0.23, maxOutputTokensPerCall: 20), pricing: $run->pricing);
    try {
        callBudgetRun('stream', $session, $larger);
        $this->fail('A $0.20 reservation must not fit the remaining allowance.');
    } catch (RunNotPermitted $failure) {
        expect($failure->code())->toBe('call_budget_exceeded');
    }
    $fake->assertCallCount(1);
    expect($run->ledger->reservedCostUsd())->toBe(0.0)->and($run->ledger->unmeteredRuns())->toBe(0);
})->with([false, true]);

it('counts a parent reservation before admitting an in-flight child', function (): void {
    $session = callBudgetSession();
    $run = callBudgetContext();
    $reservation = $run->ledger->reserveCost($run->budget, 0.1);
    $child = new RunContext($run->ledger, (new RunBudget(5, 0.17, maxOutputTokensPerCall: 10))
        ->nestedWithin($run->budget, $run->ledger), pricing: $run->pricing);
    $fake = Prism::fake(callBudgetResponses('send', true, [0.04]));
    try {
        $session->send('go', context: $child);
        $this->fail('The parent already reserved too much for this child.');
    } catch (RunNotPermitted $failure) {
        expect($failure->code())->toBe('call_budget_exceeded');
    }
    $fake->assertCallCount(0);
    expect($run->ledger->reservedCostUsd())->toBe(0.1);
    $run->ledger->settleCost($reservation, 0.04);
    expect($run->ledger->reservedCostUsd())->toBe(0.0);
});

it('refuses model-based compaction before any request under a hard cap', function (string $shape): void {
    $session = callBudgetSession();
    app()->instance(CompactionStrategy::class, new SummarisingCompaction(keep: 1));
    $fake = Prism::fake([TextResponseFake::make()]);
    try {
        callBudgetRun($shape, $session, callBudgetContext());
        $this->fail('Compaction must not spend outside admission.');
    } catch (RunNotPermitted $failure) {
        expect($failure->code())->toBe('budget_compaction_unmetered');
    }
    $fake->assertCallCount(0);
})->with(['send', 'structured', 'stream']);
