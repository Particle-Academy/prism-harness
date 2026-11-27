<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Tests\Fixtures\Participant;

// The thread replays each call's result exactly once in its step's rows,
// also when other rows separate the step's result rows, as when a new
// message's user row comes before core's resolution of the parked step.

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather', 'clock']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')->using(fn (string $city): string => "Sunny in {$city}"),
        (new Tool)->as('clock')->for('Clock.')->withStringParameter('city', 'City')->using(fn (string $city): string => "Noon in {$city}"),
    ]);

    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    $call = fn (string $name, string $city): array => [
        'id' => 'call_'.$city, 'type' => 'function_call', 'status' => 'completed',
        'arguments' => json_encode(['city' => $city]), 'call_id' => 'call_'.$city, 'name' => $name,
    ];
    Http::fakeSequence()
        ->push(['id' => 'resp_r', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [$call('weather', 'Paris'), $call('clock', 'Rome'), $call('clock', 'Oslo')], 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_r_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_r', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function replayOnceSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

it('replays each result once when a new message follows a parked step', function (string $path, ?int $steps): void {
    replayOnceSession()->send('Weather in Paris, time in Rome and Oslo?');
    if ($steps !== null) {
        config()->set('prism-harness.agent.modes.chat.max_steps', $steps);
        app()->forgetInstance(ModeRegistry::class);
        app()->forgetInstance(AgentRuntime::class);
        app()->forgetInstance(PrismHarness::class);
    }
    // The new message's own turn: its user row, then the resolution core
    // writes for the parked step.
    match ($path) {
        'stream' => iterator_to_array(replayOnceSession()->stream('Something else.'), false),
        'stream steps' => iterator_to_array(replayOnceSession()->streamSteps('Something else.'), false),
        default => replayOnceSession()->send('Something else.'),
    };

    $replayed = [];
    foreach (replayOnceSession()->thread()->messages() as $message) {
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $replayed[] = $result->toolName.' '.($result->args['city'] ?? '?');
            }
        }
    }
    sort($replayed);

    expect($replayed)->toBe(['clock Oslo', 'clock Rome', 'weather Paris']);
})->with(['send', 'stream', 'stream steps'])->with(['the mode steps' => [null], 'one step a turn' => [1]]);
