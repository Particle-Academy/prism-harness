<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

// Core expires an approval stored without its marker when the call cannot be
// told apart from one an earlier version stored unparsed: one string under a
// parameter named `input` or `raw`. The harness stores the requests core
// issues, so it must keep the marker, or every such approval it stores would
// expire on resume and the tool could never run.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['translate']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['translate']);

    $this->ran = 0;
    $ran = &$this->ran;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('translate')->for('Translate.')->withStringParameter('input', 'Text')
            ->using(function (string $input) use (&$ran): string {
                $ran++;

                return 'Bonjour';
            }),
    ]);

    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    Http::fakeSequence()
        ->push(['id' => 'resp_1', 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4o', 'usage' => $usage, 'output' => [
            ['id' => 'fc_1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"input":"Hello"}', 'call_id' => 'call_1', 'name' => 'translate'],
        ]])
        ->push(['id' => 'resp_2', 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4o', 'usage' => $usage, 'output' => [
            ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Bonjour', 'annotations' => []]]],
        ]]);
});

function markerSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

it('stores the marker core issues with a pending approval', function (): void {
    $pending = markerSession()->send('Translate hello.')->pendingApprovals();

    $assistant = Thread::query()->firstOrFail()->storedMessages()->where('type', 'assistant')->firstOrFail();
    $requests = $assistant->payload['tool_approval_requests'] ?? [];

    expect($pending)->toHaveCount(1)
        ->and($requests)->toHaveCount(1)
        ->and($requests[0])->toHaveKey('arguments_format');
});

it('runs an approved call on a tool with an input parameter after it is stored and read back', function (): void {
    $pending = markerSession()->send('Translate hello.')->pendingApprovals();

    // A fresh session reads the thread back from the database.
    app()->forgetInstance(PrismHarness::class);
    markerSession()->approve($pending[0]);

    expect($this->ran)->toBe(1);
});
