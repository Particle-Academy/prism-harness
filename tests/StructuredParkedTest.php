<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\StructuredSchemaViolation;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

// G-405: the schema check runs on a structured turn whose step also calls a
// gated tool: a document the schema refuses is never returned, the turn
// raises StructuredSchemaViolation, and the gated call does not run. Both
// shapes of the step: stopping for the call, and ending its turn beside it.

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
});

function structuredParkedSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

function structuredParkedFake(string $document, string $stop = 'tool_use'): void
{
    $usage = ['input_tokens' => 1, 'output_tokens' => 1];
    Http::fakeSequence()
        ->push(['id' => 'msg_s1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => $document], ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'weather', 'input' => ['city' => 'Paris']]],
            'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'msg_s2', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => 'Done.']], 'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => $usage]));
}

it('never returns a document the schema refuses from a structured turn whose step calls a gated tool', function (string $stop): void {
    structuredParkedFake('{"title": 12345, "role": "admin"}', $stop);
    $schema = new ObjectSchema('doc', 'Doc', [new StringSchema('title', 'Title'), new StringSchema('role', 'Role')], ['title', 'role']);

    $raised = null;
    $returned = null;
    try {
        $returned = structuredParkedSession()->sendStructured('Make a document.', $schema)->structured();
    } catch (StructuredSchemaViolation $e) {
        $raised = $e;
    }

    expect($returned)->toBeNull()
        ->and($raised)->toBeInstanceOf(StructuredSchemaViolation::class)
        ->and($raised?->problems())->not->toBe([])
        ->and($this->ran)->toBe([]);
})->with(['the step stops for the call (tool_use)' => ['tool_use'], 'the step ends its turn beside the call (end_turn)' => ['end_turn']]);
