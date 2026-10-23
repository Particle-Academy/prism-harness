<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\StreamCollector;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

it('pins the real Anthropic single-call stream finish and its collected tool results', function (): void {
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    Http::preventStrayRequests();
    Http::fakeSequence()->push(budgetSseReply(1), 200, ['Content-Type' => 'text/event-stream']);
    $calls = 0;
    $tool = (new Tool)->as('tick')->for('Count')->using(function () use (&$calls): string {
        $calls++;

        return 'done';
    });
    $collected = null;
    $stream = Prism::text()->using('anthropic', 'budget-model')->withPrompt('go')->withTools([$tool])->withMaxSteps(1)->asStream();
    $collector = new StreamCollector($stream, null,
        function ($pending, $messages, $response) use (&$collected): void {
            $collected = $response;
        });
    foreach ($collector->collect() as $event) {
    }
    Http::assertSentCount(1);
    // Minimum supported core 0.123.0 reports Stop here; later core correctly
    // reports ToolCalls. The loop relies on completed tool/result pairs.
    expect($calls)->toBe(1)->and($collected->finishReason)->toBeIn([FinishReason::Stop, FinishReason::ToolCalls])
        ->and($collected->toolCalls)->toHaveCount(1)->and($collected->toolResults)->toHaveCount(1)
        ->and($collected->messages->map(fn ($message): string => $message::class)->all())
        ->toBe([AssistantMessage::class, ToolResultMessage::class]);
});

it('pins core single-call tool execution and returned messages before the harness owns the loop', function (): void {
    // Prism::fake returns its fixture verbatim and cannot establish whether core
    // executes a tool. Exercise the installed real handler with faked HTTP.
    config()->set('prism.providers.openai.api_key', 'test-only');
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response((string) file_get_contents(__DIR__.'/Fixtures/openai/tool-calls.json'))]);
    $invoked = [];
    $tools = [
        (new Tool)->as('search')->for('Search')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$invoked): string {
                $invoked[] = 'search';

                return 'search result';
            }),
        (new Tool)->as('weather')->for('Weather')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$invoked): string {
                $invoked[] = 'weather';

                return 'weather result';
            }),
    ];

    $response = Prism::text()->using('openai', 'gpt-4o')->withPrompt('Check both')
        ->withTools($tools)->withMaxSteps(1)->asText();

    Http::assertSentCount(1);
    expect($invoked)->toBe(['search', 'weather'])
        ->and($response->steps)->toHaveCount(1)
        ->and($response->finishReason)->toBe(FinishReason::ToolCalls)
        ->and($response->toolResults)->toHaveCount(2)
        ->and($response->messages->map(fn ($message): string => $message::class)->all())->toBe([
            UserMessage::class, AssistantMessage::class, ToolResultMessage::class,
        ]);
});
