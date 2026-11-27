<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

// An empty prompt records no user row, so it continues the turn at the tail.
// After that turn has spent its step budget, empty sends and empty streams
// must not open a new allowance: the step count and the model-call count
// stay at max_steps, whether or not an approval is waiting at the tail.

const EMPTY_RESUME_MAX = 4;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather', 'clock']);
    config()->set('prism-harness.agent.modes.chat.max_steps', EMPTY_RESUME_MAX);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = 0;
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('clock')->for('Clock.')->using(function () use (&$ran): string {
            $ran++;

            return 'noon';
        }),
        (new Tool)->as('weather')->for('Weather.')->using(function () use (&$ran): string {
            $ran++;

            return 'sunny';
        }),
    ]);

    $this->modelCalls = 0;
    $calls = &$this->modelCalls;
    $this->shape = 'gated';
    $shape = &$this->shape;
    Http::fake(function (Request $request) use (&$calls, &$shape) {
        $calls++;
        $name = $shape === 'gated' || $calls === 1 ? 'weather' : 'clock';

        $id = 'toolu_'.$calls;
        if ((json_decode((string) $request->body(), true)['stream'] ?? false) === true) {
            return Http::response(emptyResumeSse($id, $name), 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response(['id' => 'msg_'.$calls, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => new stdClass]],
            'stop_reason' => 'tool_use', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    });
});

/** One Anthropic streamed step asking for a tool. */
function emptyResumeSse(string $id, string $name): string
{
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_'.$id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => new stdClass]],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null], 'usage' => ['output_tokens' => 1]],
        ['type' => 'message_stop'],
    ];
    $newline = chr(10);

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type'].$newline.'data: '.json_encode($event).$newline.$newline, $events));
}

function emptyResumeSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** Model steps since the turn's user row: one assistant row per step. */
function emptyResumeSteps(): int
{
    $steps = 0;
    foreach (emptyResumeSession()->thread()->storedMessages()->reorder('position', 'desc')->get() as $row) {
        if ($row->type === 'user') {
            break;
        }
        $steps += $row->type === 'assistant' ? 1 : 0;
    }

    return $steps;
}

it('keeps steps and model calls within max_steps across empty sends after the budget is spent', function (string $shape, string $empty): void {
    $this->shape = $shape;
    $response = emptyResumeSession()->send('What is the weather?');
    for ($index = 0; $index < 10 && $response->awaitingApproval(); $index++) {
        try {
            $response = emptyResumeSession()->approve($response->pendingApprovals()[0]);
        } catch (Throwable) {
            break;
        }
    }
    for ($index = 0; $index < 6; $index++) {
        try {
            if ($empty === 'stream') {
                foreach (emptyResumeSession()->stream('') as $event) {
                    // drained
                }
            } else {
                emptyResumeSession()->send('');
            }
        } catch (Throwable) {
            // A refusal is an allowed outcome.
        }
        expect($this->modelCalls)->toBeLessThanOrEqual(EMPTY_RESUME_MAX)
            ->and(emptyResumeSteps())->toBeLessThanOrEqual(EMPTY_RESUME_MAX);
    }

    expect($this->ran)->toBeLessThanOrEqual(EMPTY_RESUME_MAX);
})->with(['every step gated' => 'gated', 'gated, then ungated steps' => 'ungated'])->with(['send' => 'send', 'stream' => 'stream']);

it('keeps an empty stream inside the turn it continues', function (): void {
    $this->shape = 'ungated';
    emptyResumeSession()->send('What is the weather?');
    for ($index = 0; $index < 6; $index++) {
        try {
            foreach (emptyResumeSession()->stream('') as $event) {
                // drained
            }
        } catch (Throwable) {
            // A refusal is an allowed outcome.
        }
    }

    $users = emptyResumeSession()->thread()->storedMessages()->where('type', 'user')->count();

    expect($users)->toBe(1)
        ->and($this->modelCalls)->toBeLessThanOrEqual(EMPTY_RESUME_MAX)
        ->and(emptyResumeSteps())->toBeLessThanOrEqual(EMPTY_RESUME_MAX);
});
