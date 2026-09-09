<?php

declare(strict_types=1);

use Prism\Harness\Context\Budget\AskOnly;
use Prism\Harness\Context\Budget\TruncateTo;
use Prism\Harness\Context\SummarisingCompaction;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\SummaryBudget;
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

/*
|--------------------------------------------------------------------------
| The word budget, which used to be a suggestion
|--------------------------------------------------------------------------
|
| `summaryWords` reached the model as "in at most N words" inside a prompt and
| nothing looked at the answer. Measured from the Lab across five runs: a stated
| 15 came back at 92 and at 346, and the default 60 came back at 205. These pin
| the check that turns the request into something enforced.
|
*/

it('accepts a summary inside its budget without spending a second call', function (): void {
    $fake = Prism::fake([TextResponseFake::make()->withText('Three words only')]);

    $outcome = (new SummarisingCompaction(keep: 4, summaryWords: 10))->compact(turns(10));

    expect($outcome->kept[0]->content)->toContain('Three words only');

    // The retry is for overshoot. Paying for one on every compacting turn would
    // double the cost of the strategy for nothing.
    $fake->assertCallCount(1);
});

it('sends an over-budget summary back once, and keeps the shorter answer', function (): void {
    $fake = Prism::fake([
        TextResponseFake::make()->withText('one two three four five six seven eight nine ten'),
        TextResponseFake::make()->withText('two words'),
    ]);

    $outcome = (new SummarisingCompaction(keep: 4, summaryWords: 5))->compact(turns(10));

    expect($outcome->kept[0]->content)->toContain('two words')
        ->and($outcome->kept[0]->content)->not->toContain('seven');
    $fake->assertCallCount(2);
});

it('retries ONCE, not until it fits', function (): void {
    // A loop would spend unbounded calls chasing a number the model may simply
    // never hit, on a path that already bills per compacting turn.
    $fake = Prism::fake([
        TextResponseFake::make()->withText('one two three four five six seven eight'),
        TextResponseFake::make()->withText('one two three four five six'),
    ]);

    $outcome = (new SummarisingCompaction(keep: 4, summaryWords: 2))->compact(turns(10));

    // Still over budget, and accepted anyway — shorter is the win available.
    expect($outcome->kept[0]->content)->toContain('one two three four five six')
        ->and($outcome->kept[0]->content)->not->toContain('seven');
    $fake->assertCallCount(2);
});

it('keeps the first summary when the retry comes back LONGER', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('one two three four five six'),
        TextResponseFake::make()->withText('one two three four five six seven eight nine ten eleven'),
    ]);

    $outcome = (new SummarisingCompaction(keep: 4, summaryWords: 3))->compact(turns(10));

    // A retry is allowed to miss. It is not allowed to make things worse.
    expect($outcome->kept[0]->content)->toContain('one two three four five six')
        ->and($outcome->kept[0]->content)->not->toContain('eleven');
});

it('keeps the first summary when the retry FAILS, rather than losing the history', function (): void {
    Prism::fake([
        TextResponseFake::make()->withText('one two three four five six'),
        fn () => throw new RuntimeException('the provider is down'),
    ]);

    $outcome = (new SummarisingCompaction(keep: 4, summaryWords: 3))->compact(turns(10));

    // A summary over budget is a cost problem. No summary at all evicts the
    // history and puts nothing in its place, which is the worse failure.
    expect($outcome->compacted())->toBeTrue()
        ->and($outcome->kept[0]->content)->toContain('one two three four five six');
});

it('counts words the way a reader would, not the way str_word_count does', function (): void {
    // str_word_count() is ASCII-minded: it drops or splits accented letters and
    // non-Latin scripts, so a French or Japanese summary would measure short and
    // sail through a budget it broke. Six words here, whatever the alphabet.
    $fake = Prism::fake([TextResponseFake::make()->withText('rétablissement café naïve 東京 Ünal œuvre')]);

    $outcome = (new SummarisingCompaction(keep: 4, summaryWords: 6))->compact(turns(10));

    expect($outcome->kept[0]->content)->toContain('rétablissement');
    $fake->assertCallCount(1);
});

/*
|--------------------------------------------------------------------------
| Whose decision the budget is
|--------------------------------------------------------------------------
|
| The operator's call: allow developer discretion on compact size AND on how it
| is enforced. Size is a number in config; enforcement is a contract. These pin
| the seam, and that the shipped answers differ in the way their names promise.
|
*/

it('lets an application swap enforcement without touching anything else', function (): void {
    $fake = Prism::fake([TextResponseFake::make()->withText('one two three four five six seven eight')]);

    $outcome = (new SummarisingCompaction(
        keep: 4,
        summaryWords: 3,
        budget: new AskOnly,
    ))->compact(turns(10));

    // AskOnly takes what it was given, so nothing is re-asked and the summary
    // stays over budget. That is the point of choosing it.
    expect($outcome->kept[0]->content)->toContain('one two three four five six seven eight');
    $fake->assertCallCount(1);
});

it('truncates at a sentence boundary rather than mid-clause', function (): void {
    Prism::fake([TextResponseFake::make()->withText(
        'The customer agreed to the refund. It was approved by finance provided the invoice matched.'
    )]);

    $outcome = (new SummarisingCompaction(keep: 4, summaryWords: 8, budget: new TruncateTo))->compact(turns(10));

    // Eight words lands inside the second sentence. Cutting there would leave
    // "It was approved by finance provided" — which reads as complete and says
    // something the conversation did not. The whole first sentence is kept
    // instead.
    expect($outcome->kept[0]->content)->toContain('The customer agreed to the refund.')
        ->and($outcome->kept[0]->content)->not->toContain('provided');
});

it('marks a truncation that had no sentence boundary to fall back on', function (): void {
    Prism::fake([TextResponseFake::make()->withText('one two three four five six seven eight nine ten')]);

    $outcome = (new SummarisingCompaction(keep: 4, summaryWords: 4, budget: new TruncateTo))->compact(turns(10));

    // A reader who can see it was cut can go to the sink. One who cannot reads
    // a fragment as a finished thought.
    expect($outcome->kept[0]->content)->toContain('one two three four …');
});

it('never lets a budget return nothing and lose the conversation', function (): void {
    $emptying = new class implements SummaryBudget
    {
        public function apply(string $summary, int $limit, callable $rewrite): string
        {
            return '';
        }
    };

    Prism::fake([TextResponseFake::make()->withText('a real summary')]);

    $outcome = (new SummarisingCompaction(keep: 4, summaryWords: 5, budget: $emptying))->compact(turns(10));

    // A badly-written budget must not be able to do worse than the strategy's
    // own failure path. An empty summary is treated exactly like a failed call:
    // keep everything rather than evict turns with nothing standing in for
    // them.
    expect($outcome->compacted())->toBeFalse()
        ->and($outcome->kept)->toHaveCount(20);
});

it('defaults to RetryOnce when nothing is bound', function (): void {
    $fake = Prism::fake([
        TextResponseFake::make()->withText('one two three four five six'),
        TextResponseFake::make()->withText('two words'),
    ]);

    (new SummarisingCompaction(keep: 4, summaryWords: 2))->compact(turns(10));

    // The constructor default is reachable and is the retrying one — the choice
    // that improves the result without being able to damage it.
    $fake->assertCallCount(2);
});

it('uses a SummaryBudget bound in the container', function (): void {
    config()->set('prism-harness.context.summarise_with', 'claude-haiku-4-5-20251001');
    config()->set('prism-harness.context.summary_words', 3);
    app()->bind(SummaryBudget::class, fn (): SummaryBudget => new AskOnly);

    $strategy = app(CompactionStrategy::class);

    $fake = Prism::fake([TextResponseFake::make()->withText('one two three four five six seven')]);
    $strategy->compact(turns(20));

    // Bound, so the provider hands it over and nothing is re-asked. Without the
    // wiring this would spend a second call and the assertion would fail.
    $fake->assertCallCount(1);
});
