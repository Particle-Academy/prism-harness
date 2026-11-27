<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

// Resolving a resumed run's approvals before the generation (G-380) decides
// whether anything was resolved from what the resolver did, never from the
// message list: core rebuilds an answered call with unparseable arguments on
// every read, and a list comparison would record a resolution that never
// happened, every turn.

function g380Usage(): array
{
    return ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
}

/** @return array<string, mixed> */
function g380Text(string $id): array
{
    return ['id' => $id, 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4o', 'usage' => g380Usage(), 'output' => [
        ['id' => 'msg_'.$id, 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Done '.$id, 'annotations' => []]]],
    ]];
}

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    Http::preventStrayRequests();
});

it('records nothing more on later turns after a streamed call whose arguments could not be parsed', function (string $gate): void {
    config()->set('prism.providers.openai.api_format', 'chat_completions');
    config()->set('prism-harness.agent.modes.chat.tools', ['transfer']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', $gate === 'gated' ? ['transfer'] : []);
    $runs = 0;
    app(ToolRegistry::class)->register((new Tool)->as('transfer')->for('Transfer.')->withStringParameter('amount', 'Amount', false)
        ->using(function (string ...$amount) use (&$runs): string {
            $runs++;

            return 'sent';
        }));
    $line = fn (array $choice): string => 'data: '.json_encode(['id' => 'c1', 'model' => 'gpt-4o', 'choices' => [$choice]])."\n\n";
    $chat = fn (string $text): array => ['id' => 'c2', 'object' => 'chat.completion', 'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]];
    Http::fakeSequence()
        ->push($line(['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'transfer', 'arguments' => '{"amount": 5']]]], 'finish_reason' => null])
            .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'tool_calls'])."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])
        ->push($line(['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'done'], 'finish_reason' => null])
            .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop'])."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])
        ->push($chat('a'))->push($chat('b'));
    $fresh = function () {
        app()->forgetInstance(PrismHarness::class);

        return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    };
    iterator_to_array($fresh()->streamSteps('Send it.'), false);
    $fresh()->send('two');
    $fresh()->send('three');

    // The rows v0.26.7 records for the same turns: one per message, and no
    // resolution recorded on a turn that resolved nothing. Round 16: the
    // rebuilt step core hands back (row 5) is no longer the paused one, and
    // the replay gives its call, which has no stored outcome, one (G-387,
    // item 3), so the request stays valid and the last turn has nothing
    // left to resolve: the gated case no longer records the resolution
    // v0.26.7 recorded for it.
    $expected = ['user', 'assistant', 'tool_result', 'assistant', 'assistant', 'user', 'assistant', 'assistant', 'user', 'assistant'];
    expect($runs)->toBe(0)
        ->and($fresh()->thread()->storedMessages()->orderBy('position')->pluck('type')->all())->toBe($expected);
})->with(['gated', 'ungated']);

it('runs nothing when a refused call with unparseable arguments is approved twice', function (): void {
    config()->set('prism-harness.agent.modes.chat.tools', ['ping']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['ping']);
    $ran = [];
    app(ToolRegistry::class)->registerMany([(new Tool)->as('ping')->for('Ping.')
        ->using(function () use (&$ran): string {
            $ran[] = 'ping';

            return 'pong';
        })]);
    Http::fakeSequence()->push(g380Text('a'))->push(g380Text('b'))->push(g380Text('c'));
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $rows = [
        ['type' => 'user', 'payload' => ['content' => 'first', 'additional_content' => [], 'additional_attributes' => []]],
        ['type' => 'assistant', 'payload' => ['content' => '', 'tool_calls' => [['id' => 'fc_1', 'name' => 'ping', 'arguments' => '{"x": ', 'result_id' => 'call_1', 'reasoning_id' => null, 'reasoning_summary' => null]],
            'additional_content' => [], 'tool_approval_requests' => [['approval_id' => 'apr_01M85RRRT8C3VQENF8ZWNQ4DYS', 'tool_call_id' => 'fc_1']], 'harness_tool_names' => ['ping']]],
    ];
    foreach ($rows as $i => $row) {
        $session->thread()->storedMessages()->create(['position' => $i + 1, 'type' => $row['type'], 'payload' => $row['payload']]);
    }
    $fresh = function () {
        app()->forgetInstance(PrismHarness::class);

        return app(PrismHarness::class)->for(Participant::query()->firstOrFail())->session('chat');
    };
    foreach ([1, 2] as $attempt) {
        try {
            $fresh()->approve('apr_01M85RRRT8C3VQENF8ZWNQ4DYS');
        } catch (Throwable) {
        }
    }

    expect($ran)->toBe([]);
});
