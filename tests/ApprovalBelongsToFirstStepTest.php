<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\Participant;

// G-392: an approval belongs to the step that FIRST asked for it. A later
// row repeating that approval id asks for nothing, so a decision made for the
// first step never decides a call of a later step: a host copy of the parked
// step with a new call (new arguments, possibly the same id) under the old
// approval runs nothing, whether the copy is recorded before or after the
// first step's result, after a refused (spent) resume, or after the model
// failed once the step was resolved.

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
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
});

function belongsFake(bool $failAfter): void
{
    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    $text = ['id' => 'resp_b_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
        'output' => [['id' => 'msg_b', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage];
    $sequence = Http::fakeSequence()
        ->push(['id' => 'resp_b', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'c1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'c1', 'name' => 'weather']],
            'usage' => $usage]);
    if ($failAfter) {
        $sequence->push(['error' => ['message' => 'down', 'type' => 'server_error']], 500);
    }
    $sequence->whenEmpty(Http::response($text));
}

function belongsSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** The host's copy of the parked step: a new call under the old approval. */
function belongsCopy(string $approvalId, string $id): AssistantMessage
{
    return new AssistantMessage('', [new ToolCall($id, 'weather', ['city' => 'Rome'])], [],
        [new ToolApprovalRequest($approvalId, $id, ToolApprovalRequest::ARGUMENTS_AS_SENT)]);
}

function belongsResume(): void
{
    for ($i = 0; $i < 3; $i++) {
        try {
            belongsSession()->send('');
        } catch (Throwable) {
            // A failed resume acts on nothing.
        }
    }
}

it('never runs a later copy\'s call on the approval the first step asked for', function (string $when, string $id, bool $resent): void {
    belongsFake($when === 'after the model failed');
    if ($when === 'after a refused resume') {
        config()->set('prism-harness.agent.modes.chat.max_steps', 1);
    }
    $approvalId = belongsSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    try {
        belongsSession()->decide([new ToolApprovalResponse($approvalId, true)]);
    } catch (Throwable) {
        // Refused (no step left) or the model failed after the resolution.
    }
    belongsSession()->thread()->record([belongsCopy($approvalId, $id)], null, ['weather']);
    if ($when === 'after a refused resume') {
        config()->set('prism-harness.agent.modes.chat.max_steps', 8);
        foreach ([ModeRegistry::class, AgentRuntime::class, PrismHarness::class] as $class) {
            app()->forgetInstance($class);
        }
    }
    if ($resent) {
        // The same approval sent again, after the copy.
        try {
            belongsSession()->decide([new ToolApprovalResponse($approvalId, true)]);
        } catch (Throwable) {
        }
    }
    belongsResume();

    expect($this->ran)->not->toContain('Rome');
})->with(['after the result', 'after a refused resume', 'after the model failed'])->with(['a new id' => ['c2'], 'the same id' => ['c1']])
    ->with(['no decision sent again' => [false], 'the approval sent again' => [true]]);

it('never runs a copy recorded before the decision on the decision for the first step', function (string $id): void {
    belongsFake(false);
    $approvalId = belongsSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    belongsSession()->thread()->record([belongsCopy($approvalId, $id)], null, ['weather']);
    try {
        belongsSession()->decide([new ToolApprovalResponse($approvalId, true)]);
    } catch (Throwable) {
    }
    belongsResume();

    expect($this->ran)->not->toContain('Rome');
})->with(['a new id' => ['c2'], 'the same id' => ['c1']]);
