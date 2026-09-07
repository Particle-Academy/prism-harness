<?php

declare(strict_types=1);

use Prism\Harness\Context\SummarisingCompaction;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/*
|--------------------------------------------------------------------------
| Summarising compaction
|--------------------------------------------------------------------------
|
| The happy path is the least interesting thing here. This is the strategy most
| able to lose something that matters — it is the one the governance-decay work
| measures at >40% safety violations — so what is tested is what it does when it
| goes wrong, and the property that stops the summary growing.
|
*/

function turns(int $n): array
{
    $messages = [];

    for ($i = 1; $i <= $n; $i++) {
        $messages[] = new UserMessage("question {$i}");
        $messages[] = new AssistantMessage("answer {$i}");
    }

    return $messages;
}

it('leaves a conversation shorter than the limit alone, and spends nothing', function (): void {
    $fake = Prism::fake([]);

    $outcome = (new SummarisingCompaction(keep: 20))->compact(turns(4));

    expect($outcome->compacted())->toBeFalse();
    $fake->assertCallCount(0);
});

it('replaces the older turns with a summary and evicts what it stood for', function (): void {
    Prism::fake([TextResponseFake::make()->withText('They discussed invoice INV-4471-QK.')]);

    $messages = turns(10);
    $outcome = (new SummarisingCompaction(keep: 4))->compact($messages);

    expect($outcome->kept)->toHaveCount(5)
        ->and($outcome->kept[0])->toBeInstanceOf(UserMessage::class)
        ->and($outcome->kept[0]->content)->toStartWith(SummarisingCompaction::MARKER)
        ->and($outcome->kept[0]->content)->toContain('INV-4471-QK')
        // The turns the summary now stands for go to the sink. Without them
        // the summary is the only record, and a summary cannot be
        // un-summarised.
        ->and($outcome->evicted)->toHaveCount(16);
});

it('REWRITES its own previous summary rather than summarising it again', function (): void {
    // The property that makes "the summary keeps compacting" true rather than
    // merely stated. An append-only précis grows without bound while passing a
    // test that only checks a summary is present.
    $fake = Prism::fake([TextResponseFake::make()->withText('One summary covering everything.')]);

    $messages = [
        new UserMessage(SummarisingCompaction::MARKER.' Earlier: they agreed a refund.'),
        ...turns(8),
    ];

    $outcome = (new SummarisingCompaction(keep: 4))->compact($messages);

    expect($outcome->kept[0]->content)->toContain('One summary covering everything.')
        ->and($outcome->kept[0]->content)->not->toContain('Earlier: they agreed a refund.');

    // And the model was TOLD to rewrite, not to append.
    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->prompt())->toContain('REWRITE')
            ->and($requests[0]->prompt())->toContain('Earlier: they agreed a refund.')
            ->and($requests[0]->prompt())->toContain('Do not append');
    });
});

it('keeps the whole conversation when the summary call fails', function (): void {
    // THE FAILURE THAT MATTERS. Returning the recent slice alone would evict
    // the older turns and put nothing in their place — the agent loses the
    // history AND has no summary standing in for it, which is strictly worse
    // than not compacting. Falling back costs tokens on that turn and loses
    // nothing.
    Prism::fake([fn () => throw new RuntimeException('the provider is down')]);

    $messages = turns(10);
    $outcome = (new SummarisingCompaction(keep: 4))->compact($messages);

    expect($outcome->compacted())->toBeFalse()
        ->and($outcome->kept)->toHaveCount(20);
});

it('keeps the whole conversation when the model returns nothing', function (): void {
    // An empty summary is the same failure wearing a success's clothes: the
    // call worked, and what came back stands in for nothing.
    Prism::fake([TextResponseFake::make()->withText('   ')]);

    $outcome = (new SummarisingCompaction(keep: 4))->compact(turns(10));

    expect($outcome->compacted())->toBeFalse();
});

it('summarises tool results too, not just the prose', function (): void {
    // On an agentic transcript tool traffic is the majority — one consumer
    // measured 93% — so a summary built from prose alone would cover the
    // smaller half of the conversation while appearing to cover all of it.
    $fake = Prism::fake([TextResponseFake::make()->withText('summary')]);

    $messages = [
        new UserMessage('look it up'),
        new AssistantMessage('', [new ToolCall(id: 'c1', name: 'lookup', arguments: [])]),
        new ToolResultMessage([new ToolResult('c1', 'lookup', [], ['address' => '4 Elm Row'])]),
        ...turns(4),
    ];

    (new SummarisingCompaction(keep: 4))->compact($messages);

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->prompt())->toContain('4 Elm Row')
            ->and($requests[0]->prompt())->toContain('Tool lookup returned');
    });
});

it('never summarises the turn the model is about to answer', function (): void {
    // Replacing the newest turn with a paraphrase of itself would replace the
    // question with a description of the question.
    Prism::fake([TextResponseFake::make()->withText('summary')]);

    $messages = [...turns(9), new UserMessage('the actual question')];
    $outcome = (new SummarisingCompaction(keep: 3))->compact($messages);

    // Indexed rather than `end()`: `kept` is a readonly promoted property and
    // `end()` takes its argument by reference.
    $last = $outcome->kept[count($outcome->kept) - 1];

    expect($last->content)->toBe('the actual question');
});

it('refuses a keep count that would summarise everything including the live turn', function (): void {
    expect(fn (): mixed => new SummarisingCompaction(keep: 0))
        ->toThrow(InvalidArgumentException::class);
});
