<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// G-405 (G-387 round 14): a STRUCTURED turn that stops for a person has NO
// document. Pinned over an ANTHROPIC-shaped fake, whose structured handler
// fills a document on a step that also holds a tool call (the native output
// format on the first step; the structured tool beside a gated call in tool
// mode). An OpenAI fake leaves that document empty on a tool-call step,
// which is how a parked turn returned an unvalidated document unnoticed. The
// document is only ever returned after it passed the schema.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-ant-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    foreach ([ModeRegistry::class, PrismHarness::class, AgentRuntime::class] as $class) {
        app()->forgetInstance($class);
    }
    $this->ran = 0;
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran++;

                return "Sunny in {$city}";
            }),
    ]);
});

/** @param list<array<string, mixed>> $content */
function zsAnthropic(array $content, string $stop): array
{
    return ['id' => 'msg_zs', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null,
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];
}

function zsSchema(): ObjectSchema
{
    return new ObjectSchema('doc', 'A document.', [new StringSchema('title', 'The title.')], ['title']);
}

it('returns no document from a structured turn that parks, whatever the model wrote on that step (ZS1, ZS2)', function (string $strategy): void {
    $weather = ['type' => 'tool_use', 'id' => 'toolu_weather', 'name' => 'weather', 'input' => ['city' => 'Paris']];
    $content = $strategy === 'native output format'
        ? [['type' => 'text', 'text' => '{"title": 12345, "role": "admin"}'], $weather]
        : [$weather, ['type' => 'tool_use', 'id' => 'toolu_doc', 'name' => 'output_structured_data', 'input' => ['title' => 99, 'role' => 'admin']]];
    if ($strategy === 'tool mode') {
        config()->set('prism-harness.agent.modes.chat.provider_options', ['use_tool_calling' => true]);
        app()->forgetInstance(ModeRegistry::class);
        app()->forgetInstance(AgentRuntime::class);
        app()->forgetInstance(PrismHarness::class);
    }
    Http::fake(['api.anthropic.com/*' => Http::sequence()->push(zsAnthropic($content, 'tool_use'))]);
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');

    $response = $session->sendStructured('Go', zsSchema());

    expect($response->awaitingApproval())->toBeTrue()
        ->and($response->pendingApprovals())->toHaveCount(1)
        ->and($response->structured())->toBe([])
        ->and($response->response->structured)->toBeNull()
        ->and($this->ran)->toBe(0);
    // Round 15 (PHP run17 Info 6a): the raw provider steps of a parked turn
    // carry no unchecked document either, as the top-level response does not.
    expect($response->response->steps)->not->toBeEmpty();
    foreach ($response->response->steps as $step) {
        expect($step->structured)->toBe([]);
    }
})->with(['native output format', 'tool mode']);

it('still checks the document of the turn that answers after the person decided', function (): void {
    $weather = ['type' => 'tool_use', 'id' => 'toolu_weather', 'name' => 'weather', 'input' => ['city' => 'Paris']];
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(zsAnthropic([['type' => 'text', 'text' => '{"title": 12345}'], $weather], 'tool_use'))
        ->push(zsAnthropic([['type' => 'text', 'text' => '{"title": "Sunny"}']], 'end_turn'))]);
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $parked = $session->sendStructured('Go', zsSchema());

    $session->decide([new ToolApprovalResponse($parked->pendingApprovals()[0]->approvalId, true)]);

    expect($parked->structured())->toBe([])->and($this->ran)->toBe(1);
});
