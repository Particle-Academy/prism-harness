<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// The thread replays each call's own result. In a step whose call ids
// repeat, results that share a call id but not their arguments are different
// calls' results, so a later one never replaces an earlier one.

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
        'id' => 'w', 'type' => 'function_call', 'status' => 'completed',
        'arguments' => json_encode(['city' => $city]), 'call_id' => 'w', 'name' => $name,
    ];
    Http::fakeSequence()
        ->push(['id' => 'resp_r', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [$call('weather', 'Paris'), $call('clock', 'Rome'), $call('clock', 'Oslo')], 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_r_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_r', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function replayOwnSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

it('replays every call\'s own result when the step\'s call ids repeat', function (): void {
    $pending = replayOwnSession()->send('Weather in Paris, time in Rome and Oslo?')->pendingApprovals();
    replayOwnSession()->decide([new ToolApprovalResponse($pending[0]->approvalId, true)]);

    $replayed = [];
    foreach (replayOwnSession()->thread()->messages() as $message) {
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $replayed[] = $result->toolName.' '.($result->args['city'] ?? '?');
            }
        }
    }
    $replayed = array_values(array_unique($replayed));
    sort($replayed);

    expect($replayed)->toBe(['clock Oslo', 'clock Rome', 'weather Paris']);
});
