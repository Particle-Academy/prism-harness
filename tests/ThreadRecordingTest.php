<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Context\CompactionStrategy;
use Prism\Harness\Context\KeepRecentTurns;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

// Driven through Prism's REAL OpenAI handler over a faked HTTP transport, not
// through Prism::fake(). The fake returns whatever messages a test hands it,
// and every other test here handed it exactly one user and one assistant
// message, which is precisely what hid the defect below: a real handler's
// `$response->messages` begins with the whole history it was sent.

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
});

function openAiFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/openai/'.$name.'.json');
}

/** @return list<array{type: string, content: mixed}> */
function storedTurns(string $scope): array
{
    return Thread::query()->where('scope', $scope)->firstOrFail()->storedMessages()->orderBy('position')->get()
        ->map(fn ($row): array => ['type' => $row->type, 'content' => $row->payload['content'] ?? null])
        ->all();
}

it('records each turn once, however long the conversation gets', function (): void {
    // Every send() used to record `$response->messages`, which from a real
    // handler is the history it was sent PLUS the new exchange. Each turn
    // re-appended the entire conversation, so a thread doubled per turn: the
    // Lab's commentary thread reached 2,046 rows holding 20 distinct messages
    // after 10 runs, 2^11 - 2 exactly.
    Http::fake(['*' => Http::response(openAiFixture('text-reply'))]);
    $ada = Participant::create(['name' => 'Ada']);

    foreach (['first', 'second', 'third'] as $prompt) {
        app(PrismHarness::class)->for($ada->fresh())->session('chat')->send($prompt);
    }

    $turns = storedTurns('chat');

    expect(array_column($turns, 'type'))->toBe(['user', 'assistant', 'user', 'assistant', 'user', 'assistant'])
        ->and(array_column(array_filter($turns, fn (array $turn): bool => $turn['type'] === 'user'), 'content'))
        ->toBe(['first', 'second', 'third']);
});

it('records a tool loop once, and the turn after it once', function (): void {
    // A multi-step turn carries its tool calls and results inside the response
    // messages as well, so this is the shape most likely to be sliced wrong: too
    // little drops the tool exchange, too much duplicates the history.
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(fn (string $query): string => 'The tigers game is today at 3pm in detroit'),
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(fn (string $city): string => 'The weather will be 75 and sunny'),
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);

    Http::fakeSequence()
        ->push(openAiFixture('tool-calls'))
        ->push(openAiFixture('after-tools'))
        ->push(openAiFixture('text-reply'));

    $ada = Participant::create(['name' => 'Ada']);
    app(PrismHarness::class)->for($ada)->session('chat')->send('What time is the game, and do I need a coat?');
    $afterToolTurn = array_column(storedTurns('chat'), 'type');

    app(PrismHarness::class)->for($ada->fresh())->session('chat')->send('Thanks');
    $afterNextTurn = array_column(storedTurns('chat'), 'type');

    expect($afterToolTurn)->toBe(['user', 'assistant', 'tool_result', 'assistant'])
        ->and($afterNextTurn)->toBe([...$afterToolTurn, 'user', 'assistant']);
});

it('records each turn once when the window is compacted, too', function (): void {
    // With a bounded window the history sent is a SUBSET of what is stored, so
    // the doubling was smaller here but still there: each turn re-appended the
    // kept window. The Lab runs this configuration.
    app()->instance(CompactionStrategy::class, new KeepRecentTurns(1));
    Http::fake(['*' => Http::response(openAiFixture('text-reply'))]);
    $ada = Participant::create(['name' => 'Ada']);

    foreach (['first', 'second', 'third', 'fourth'] as $prompt) {
        app(PrismHarness::class)->for($ada->fresh())->session('chat')->send($prompt);
    }

    $turns = storedTurns('chat');

    expect($turns)->toHaveCount(8)
        ->and(array_column(array_filter($turns, fn (array $turn): bool => $turn['type'] === 'user'), 'content'))
        ->toBe(['first', 'second', 'third', 'fourth']);
});
