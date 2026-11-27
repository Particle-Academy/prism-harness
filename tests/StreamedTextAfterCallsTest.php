<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// G-392/G-397: a streamed step whose text arrives AFTER its tool calls is the
// harness's own row, written inside the run, so it never supersedes the step
// it parked on: the approved call runs. Every row a harness turn writes
// carries that turn's run id.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran[] = $city;

                return "Sunny in {$city}";
            }),
    ]);

    $requests = 0;
    Http::fake(['*' => function () use (&$requests) {
        $event = fn (string $type, array $data): string => "event: {$type}\ndata: ".json_encode(['type' => $type, ...$data])."\n\n";
        if (++$requests === 1) {
            return Http::response($event('message_start', ['message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
                'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]])
                .$event('content_block_start', ['index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'weather', 'input' => (object) []]])
                .$event('content_block_delta', ['index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city":"Paris"}']])
                .$event('content_block_stop', ['index' => 0])
                .$event('content_block_start', ['index' => 1, 'content_block' => ['type' => 'text', 'text' => '']])
                .$event('content_block_delta', ['index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'I will check the weather.']])
                .$event('content_block_stop', ['index' => 1])
                .$event('message_delta', ['delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null], 'usage' => ['output_tokens' => 1]])
                .$event('message_stop', []), 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response(['id' => 'msg_2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => 'Done.']], 'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    }]);
});

function streamedTextSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

it('runs the approved call of a streamed step whose text follows its calls, and attributes every harness row to its run', function (): void {
    $pending = [];
    foreach (streamedTextSession()->stream('Weather in Paris?') as $event) {
        if ($event instanceof ToolApprovalRequestEvent) {
            $pending[] = $event->approvalId;
        }
    }

    expect($pending)->toHaveCount(1)
        ->and(streamedTextSession()->thread()->storedMessages()->whereNull('run_id')->count())->toBe(0);

    streamedTextSession()->decide([new ToolApprovalResponse($pending[0], true)]);

    expect($this->ran)->toBe(['Paris'])
        ->and(streamedTextSession()->thread()->storedMessages()->whereNull('run_id')->whereIn('type', ['assistant', 'user'])->count())->toBe(0);
});
