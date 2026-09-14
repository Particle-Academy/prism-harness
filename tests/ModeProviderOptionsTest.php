<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

it('passes a mode\'s provider options to every run, sent or streamed', function (): void {
    config()->set('prism-harness.agent.modes.chat.provider_options', ['thinking' => ['type' => 'adaptive'], 'effort' => 'medium']);
    $fake = Prism::fake([TextResponseFake::make()->withText('a'), TextResponseFake::make()->withText('b')]);
    $ada = Participant::create(['name' => 'Ada']);

    app(PrismHarness::class)->for($ada)->session('chat')->send('first');
    foreach (app(PrismHarness::class)->for($ada->fresh())->session('chat')->stream('second') as $event) {
    }

    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(2);

        foreach ($requests as $request) {
            expect($request->providerOptions())->toBe(['thinking' => ['type' => 'adaptive'], 'effort' => 'medium']);
        }
    });
});

it('sends no provider options when a mode declares none', function (): void {
    $fake = Prism::fake([TextResponseFake::make()->withText('a')]);
    $ada = Participant::create(['name' => 'Ada']);

    app(PrismHarness::class)->for($ada)->session('chat')->send('hello');

    $fake->assertRequest(fn (array $requests) => expect($requests[0]->providerOptions())->toBe([]));
});

it('refuses provider options that are not a map, rather than running without them', function (mixed $declared): void {
    config()->set('prism-harness.agent.modes.chat.provider_options', $declared);
    $ada = Participant::create(['name' => 'Ada']);

    expect(fn () => app(PrismHarness::class)->for($ada)->session('chat')->send('hello'))
        ->toThrow(InvalidArgumentException::class, 'provider_options');
})->with([
    'a list' => [['thinking']],
    'a string' => ['thinking'],
]);

it('replays extended thinking with its signature on the next turn from a stored thread', function (array $options, array $expectedThinking, ?array $expectedOutputConfig): void {
    // Anthropic rejects a later tool-use turn whose earlier thinking blocks come
    // back without their signatures. The signature rides in the assistant
    // message's additionalContent, so it has to survive storage: recorded, read
    // back through MessageMapper, and mapped into the NEXT request. Driven
    // through the real Anthropic handler, so what is asserted is the request
    // body that would have gone over the wire.
    //
    // BOTH thinking shapes. Current Claude models refuse `thinking.enabled` with
    // a 400 and take `thinking.type: adaptive` plus `effort` instead. fancy found
    // that live, from the example this package's own README shipped. The replay
    // must hold under the shape people actually use, not only the older one.
    config()->set('prism.providers.anthropic.api_key', 'sk-ant-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-4-6');
    config()->set('prism-harness.agent.modes.chat.provider_options', $options);
    config()->set('prism-harness.agent.modes.chat.tools', ['weather', 'search']);
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')->using(fn (string $city): string => 'The weather will be 75 and sunny'),
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
    ]);

    $fixture = fn (string $name): string => (string) file_get_contents(__DIR__.'/Fixtures/anthropic/'.$name.'.json');
    Http::fakeSequence()
        ->push($fixture('thinking-tools-1'))
        ->push($fixture('thinking-tools-2'))
        ->push($fixture('thinking-tools-3'))
        ->push($fixture('text-reply'));

    $ada = Participant::create(['name' => 'Ada']);
    app(PrismHarness::class)->for($ada)->session('chat')->send('What time is the tigers game today and should I wear a coat?');
    app(PrismHarness::class)->for($ada->fresh())->session('chat')->send('Thanks');

    $signature = json_decode($fixture('thinking-tools-1'), true)['content'][0]['signature'];
    $nextTurn = json_decode((string) Http::recorded()[3][0]->body(), true);

    $replayedThinking = collect($nextTurn['messages'])
        ->where('role', 'assistant')
        ->flatMap(fn (array $message): array => is_array($message['content']) ? $message['content'] : [])
        ->where('type', 'thinking');

    expect($replayedThinking)->not->toBeEmpty()
        ->and($replayedThinking->pluck('signature')->all())->toContain($signature)
        ->and(Arr::only($nextTurn['thinking'] ?? [], array_keys($expectedThinking)))->toBe($expectedThinking)
        ->and($nextTurn['output_config'] ?? null)->toBe($expectedOutputConfig);
})->with([
    'adaptive, for current models' => [
        ['thinking' => ['type' => 'adaptive'], 'effort' => 'medium'],
        ['type' => 'adaptive'],
        ['effort' => 'medium'],
    ],
    'enabled with a budget, for older models' => [
        ['thinking' => ['enabled' => true, 'budgetTokens' => 2048]],
        ['type' => 'enabled', 'budget_tokens' => 2048],
        null,
    ],
]);
