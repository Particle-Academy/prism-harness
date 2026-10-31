<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Subagents\BudgetedCalls;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\CostObserver;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

it('resolves and persists approval results before bounding every capped call shape', function (string $shape): void {
    Http::preventStrayRequests();
    Http::fakeSequence()->push($shape === 'stream' ? budgetSseReply(1, true) : budgetHttpReply(1, true));
    $largeResult = str_repeat('approval-result-', 4000);
    $invocations = 0;
    $tool = (new Tool)->as('tick')->for('Approved tool')->requiresApproval()
        ->using(function () use (&$invocations, $largeResult): string {
            $invocations++;

            return $largeResult;
        });
    $generation = $shape === 'structured' ? Prism::structured()->withSchema(callBudgetSchema()) : Prism::text();
    $generation->using('anthropic', 'budget-model', ['api_key' => 'test-only'])
        ->withTools([$tool])->withMessages([
            new UserMessage('Question'),
            new AssistantMessage('', [new ToolCall('approved', 'tick', [])],
                toolApprovalRequests: [new ToolApprovalRequest('approval', 'approved')]),
            new ToolResultMessage([], [new ToolApprovalResponse('approval', true)]),
        ]);
    $persisted = [];
    $observer = new CostObserver;
    $run = RunContext::root('approval-projection', new RunBudget(2, 10.0, maxOutputTokensPerCall: 10), [
        'anthropic' => ['budget-model' => ['inputPerMTok' => 1, 'outputPerMTok' => 1, 'toolOverheadTokens' => 286]],
    ], observer: $observer);
    $price = CallBudget::forRun($run, 'anthropic', 'budget-model', []);
    $before = $price->inputTokenBound($generation->toRequest());
    $observer->before = function () use ($generation, $price, &$invocations, &$persisted, $largeResult, $before): void {
        Http::assertNothingSent();
        $request = $generation->toRequest();
        $results = [];
        foreach ($request->messages() as $message) {
            if ($message instanceof ToolResultMessage) {
                $results = [...$results, ...$message->toolResults];
            }
        }
        expect($invocations)->toBe(1)->and($persisted)->not->toBeEmpty()
            ->and($results)->toHaveCount(1)->and($results[0]->result)->toBe($largeResult)
            ->and($price->inputTokenBound($request))->toBeGreaterThanOrEqual($before + strlen($largeResult));
    };
    $calls = new BudgetedCalls($price);
    $record = static function (): void {};
    $resolved = function (array $messages) use (&$persisted): void {
        $persisted = $messages;
    };
    match ($shape) {
        'structured' => $calls->structured($generation, $run, $record, $resolved),
        'stream' => iterator_to_array($calls->stream($generation, $run, $record, $resolved)),
        default => $calls->text($generation, $run, $record, $resolved),
    };
    Http::assertSentCount(1);
    $wire = Http::recorded()[0][0]->body();
    expect(substr_count($wire, $largeResult))->toBe(1)->and($invocations)->toBe(1)
        ->and($observer->reservations)->toHaveCount(1)->and($observer->settlements)->toHaveCount(1);
})->with(['text', 'structured', 'stream']);
