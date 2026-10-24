<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\CostObserver;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

/*
 * A provider that reports no usage, or a stream that never reached its end,
 * must not be read as a cheap call. The SSE cases go through core's real
 * Anthropic stream handler, so they fail on whatever core actually emits; the
 * finish-reason cases use Prism::fake to pin the harness rule on any core.
 */

function incompleteUsageRun(string $sse, RunContext $run): CallSettlement
{
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    $session = callBudgetSession();
    Http::fakeSequence()->push($sse, 200, ['Content-Type' => 'text/event-stream']);
    Http::preventStrayRequests();
    foreach ($session->stream('go', context: $run) as $event) {
    }
    Http::assertSentCount(1);

    return $run->observer->settlements[0];
}

function incompleteUsageContext(CostObserver $observer): RunContext
{
    // $0.10 reserved per call: 10 output tokens at $10,000 per million.
    return RunContext::root('incomplete', new RunBudget(10, 1.0, maxOutputTokensPerCall: 10), [
        'anthropic' => ['budget-model' => ['inputPerMTok' => 1, 'outputPerMTok' => 10000, 'toolOverheadTokens' => 286]],
    ], observer: $observer);
}

/** @param list<string> $drop event types removed from a complete reply */
function incompleteSse(array $drop = [], bool $usage = true, ?array $after = null): string
{
    $events = array_values(array_filter(explode("\n\n", trim(budgetSseReply(1, true))),
        fn (string $event): bool => ! in_array(json_decode(substr($event, strpos($event, 'data: ') + 6), true)['type'], $drop, true)));
    if (! $usage) {
        $events = array_map(function (string $event): string {
            $data = json_decode(substr($event, strpos($event, 'data: ') + 6), true);
            unset($data['usage'], $data['message']['usage']);

            return 'event: '.$data['type']."\ndata: ".json_encode($data);
        }, $events);
    }
    foreach ($after ?? [] as $extra) {
        $events[] = 'event: '.$extra['type']."\ndata: ".json_encode($extra);
    }

    return implode("\n\n", $events)."\n\n";
}

it('charges a complete stream its reported usage', function (): void {
    $observer = new CostObserver;
    $run = incompleteUsageContext($observer);
    $settled = incompleteUsageRun(incompleteSse(), $run);

    // The control: 3 input tokens at $1/M plus 2 output tokens at $10,000/M.
    expect($settled->usageKnown)->toBeTrue()
        ->and($settled->chargedUsd)->toEqualWithDelta(0.020003, 1e-9)
        ->and($run->ledger->costUsd())->toEqualWithDelta(0.020003, 1e-9);
});

// Whether a TRUNCATED provider stream is detected is core's job: it must end
// such a stream with Error rather than Stop. The harness's part is to never
// treat a stream that did not finish normally as known usage, which these
// fakes pin independently of the installed core version.
it('charges the reserved maximum when the stream did not finish normally', function (FinishReason $finish): void {
    $observer = new CostObserver;
    $run = incompleteUsageContext($observer);
    $session = callBudgetSession();
    Prism::fake([TextResponseFake::make()->withText('partial')->withFinishReason($finish)->withUsage(new Usage(3, 1))]);
    foreach ($session->stream('go', context: $run) as $event) {
    }

    $settled = $observer->settlements[0];
    expect($settled->usageKnown)->toBeFalse()
        ->and($settled->chargedUsd)->toBe($observer->reservations[0]->worstCaseUsd)
        ->and($run->ledger->costUsd())->toBe($observer->reservations[0]->worstCaseUsd);
})->with([FinishReason::Error, FinishReason::Unknown]);

it('keeps reported usage when a faked stream finishes normally', function (): void {
    $observer = new CostObserver;
    $run = incompleteUsageContext($observer);
    $session = callBudgetSession();
    Prism::fake([TextResponseFake::make()->withText('done')->withFinishReason(FinishReason::Stop)->withUsage(new Usage(3, 1))]);
    foreach ($session->stream('go', context: $run) as $event) {
    }

    expect($observer->settlements[0]->usageKnown)->toBeTrue()
        ->and($observer->settlements[0]->chargedUsd)->toEqualWithDelta(0.010003, 1e-9);
});

it('charges the reserved maximum when the provider reported no usage at all', function (): void {
    $observer = new CostObserver;
    $run = incompleteUsageContext($observer);
    $settled = incompleteUsageRun(incompleteSse(usage: false), $run);

    expect($settled->usageKnown)->toBeFalse()
        ->and($settled->chargedUsd)->toBe($observer->reservations[0]->worstCaseUsd);
});

it('charges the reserved maximum when the stream ends with an error event', function (): void {
    $observer = new CostObserver;
    $run = incompleteUsageContext($observer);
    $settled = incompleteUsageRun(incompleteSse(['message_delta', 'message_stop'],
        after: [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]]), $run);

    expect($settled->usageKnown)->toBeFalse()
        ->and($settled->chargedUsd)->toBe($observer->reservations[0]->worstCaseUsd);
});

it('treats usage with no input tokens and no reported cost as unknown', function (string $shape, Usage $usage, bool $known): void {
    $observer = new CostObserver;
    $run = incompleteUsageContext($observer);
    $session = callBudgetSession();
    Prism::fake([$shape === 'structured'
        ? StructuredResponseFake::make()->withText('{"ok":true}')->withStructured(['ok' => true])
            ->withFinishReason(FinishReason::Stop)->withUsage($usage)
        : TextResponseFake::make()->withText('done')->withFinishReason(FinishReason::Stop)->withUsage($usage)]);
    callBudgetRun($shape, $session, $run);

    $settled = $observer->settlements[0];
    expect($settled->usageKnown)->toBe($known)
        ->and($settled->chargedUsd)->toBe($known ? $usage->cost : $observer->reservations[0]->worstCaseUsd);
})->with(['send', 'structured'])->with([
    'zero usage' => [new Usage(0, 0), false],
    'output without input' => [new Usage(0, 5), false],
    'a provider-reported zero cost is trusted' => [new Usage(0, 0, cost: 0.0), true],
]);

it('charges the reserved maximum when the consumer stops reading before a normal end', function (string $stopAt, bool $error, bool $known): void {
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    $observer = new CostObserver;
    $run = incompleteUsageContext($observer);
    $session = callBudgetSession();
    Http::fakeSequence()->push($error
        ? incompleteSse(['message_delta', 'message_stop'], after: [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]])
        : incompleteSse(), 200, ['Content-Type' => 'text/event-stream']);
    Http::preventStrayRequests();
    foreach ($session->stream('go', context: $run) as $event) {
        if (class_basename($event) === $stopAt) {
            break;
        }
    }
    unset($event);
    gc_collect_cycles();

    $settled = $observer->settlements[0];
    expect($settled->outcome)->toBe('abandoned')
        ->and($settled->usageKnown)->toBe($known)
        ->and($settled->chargedUsd)->toBe($known ? $settled->chargedUsd : $observer->reservations[0]->worstCaseUsd);
})->with([
    'after the step finished, before the stream ended' => ['StepFinishEvent', false, false],
    'at the end of a stream that reported an error' => ['StreamEndEvent', true, false],
    'at the end of a stream that finished normally' => ['StreamEndEvent', false, true],
]);

it('charges a stream truncated without any error the reserved maximum, as a failed call', function (array $drop): void {
    $observer = new CostObserver;
    $run = incompleteUsageContext($observer);
    $settled = incompleteUsageRun(incompleteSse($drop), $run);

    // Core 0.134.0 ends such a stream with incomplete_stream and Error; before
    // it, this read as a complete call charged its message_start usage.
    expect($settled->usageKnown)->toBeFalse()
        ->and($settled->outcome)->toBe('failed')
        ->and($settled->chargedUsd)->toBe($observer->reservations[0]->worstCaseUsd);
})->with([
    'no final delta or stop' => [['message_delta', 'message_stop']],
    'no stop' => [['message_stop']],
]);

it('reports an abnormally ended stream as failed, and a normal one as completed', function (bool $error, string $outcome): void {
    $sse = $error
        ? incompleteSse(['message_delta', 'message_stop'], after: [['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]])
        : incompleteSse();

    expect(incompleteUsageRun($sse, incompleteUsageContext(new CostObserver))->outcome)->toBe($outcome);
})->with([
    'a normal end' => [false, 'completed'],
    'a provider error' => [true, 'failed'],
]);

it('charges the reserved maximum, as failed, when the stream stopped but never sent its final usage', function (): void {
    // Reported by fancy against core 0.134.0 + harness 0.16.0: message_stop
    // arrives but the message_delta with the final output_tokens does not.
    // Core ends with Stop and null usage; the step's partial usage (input,
    // no output) must not be priced as the call's cost.
    $observer = new CostObserver;
    $run = incompleteUsageContext($observer);
    $settled = incompleteUsageRun(incompleteSse(['message_delta']), $run);

    expect($settled->usageKnown)->toBeFalse()
        ->and($settled->outcome)->toBe('failed')
        ->and($settled->chargedUsd)->toBe($observer->reservations[0]->worstCaseUsd)
        ->and($run->ledger->costUsd())->toBe($observer->reservations[0]->worstCaseUsd);
});
