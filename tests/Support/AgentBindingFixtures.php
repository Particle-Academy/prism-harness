<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Anthropic\Anthropic;
use Tests\Fixtures\Participant;

/**
 * A second, CUSTOM provider name on the Anthropic wire.
 *
 * Registered through core's public `extend()`, so a run bound to it proves the
 * harness hands any provider string to core rather than assuming the enum. Its
 * host is distinct, which is how a recorded request says which provider it
 * actually went to.
 */
function registerCustomCheapProvider(): void
{
    config()->set('prism.providers.anthropic.api_key', 'synthetic-test-key');
    app(PrismManager::class)->extend('custom-cheap', fn (): Anthropic => new Anthropic(
        apiKey: 'synthetic-cheap-key', apiVersion: '2023-06-01', url: 'https://cheap.test/v1',
    ));
}

/**
 * Fakes the Anthropic wire for a parent that may delegate once.
 *
 * The parent's first request calls `$delegate` (when given) with task `t`; a
 * child answers; the parent then answers. Every request is recorded as the
 * provider it reached (by host), the model it named, and whose request it was.
 *
 * @param  list<array{who: string, provider: string, model: string}>  $requests
 * @param  list<string>|null  $toolResults  every tool_result the requests carried, JSON-encoded
 */
function fakeBindingWire(?string $delegate, array &$requests, ?array &$toolResults = null): void
{
    $toolResults = [];
    Http::fake(function (HttpRequest $wire) use ($delegate, &$requests, &$toolResults) {
        $body = $wire->data();
        $encoded = json_encode($body['messages'] ?? [], JSON_THROW_ON_ERROR);
        $first = $body['messages'][0]['content'] ?? null;
        $firstText = is_string($first) ? $first : (is_array($first) ? ($first[0]['text'] ?? null) : null);
        $who = str_contains($encoded, '"tool_result"') ? 'parent' : ($firstText === 't' ? 'child' : 'parent');
        $requests[] = [
            'who' => $who,
            'provider' => parse_url($wire->url(), PHP_URL_HOST) === 'cheap.test' ? 'custom-cheap' : 'anthropic',
            'model' => (string) ($body['model'] ?? ''),
        ];
        foreach ($body['messages'] ?? [] as $message) {
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (($block['type'] ?? null) === 'tool_result') {
                    $toolResults[] = json_encode($block['content'] ?? null, JSON_THROW_ON_ERROR);
                }
            }
        }
        $call = $who === 'parent' && $delegate !== null && ! str_contains($encoded, '"tool_result"');

        return Http::response([
            'id' => 'binding-'.count($requests), 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'] ?? '',
            'content' => $call
                ? [['type' => 'tool_use', 'id' => 'delegate-call', 'name' => $delegate, 'input' => ['task' => 't']]]
                : [['type' => 'text', 'text' => $who === 'child' ? 'child answer' : 'parent answer']],
            'stop_reason' => $call ? 'tool_use' : 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ]);
    });
}

/**
 * The first parent and the first child request, as the corpus records them.
 *
 * @param  list<array{who: string, provider: string, model: string}>  $requests
 * @return array{parent: array{provider: string, model: string}|null, child: array{provider: string, model: string}|null}
 */
function firstBindings(array $requests): array
{
    $first = function (string $who) use ($requests): ?array {
        foreach ($requests as $request) {
            if ($request['who'] === $who) {
                return ['provider' => $request['provider'], 'model' => $request['model']];
            }
        }

        return null;
    };

    return ['parent' => $first('parent'), 'child' => $first('child')];
}

/**
 * A session in the named agent and mode, through the public harness API.
 */
function bindingSession(?string $agent, string $mode, string $scope = 'binding'): Session
{
    $session = app(PrismHarness::class)->forAgent($agent)
        ->for(Participant::firstOrCreate(['name' => 'Binding']))->session($scope);
    $session->usingMode($mode);

    return $session;
}

/**
 * A mode config with every declared subagent offered as one of its tools.
 *
 * Named agents offer a subagent only when the mode lists it as a tool; the
 * corpus states delegations, not tool lists, so the runner adds them.
 *
 * @param  array<string, mixed>  $mode
 * @return array<string, mixed>
 */
function bindingMode(array $mode): array
{
    $subagents = is_array($mode['subagents'] ?? null) ? array_keys($mode['subagents']) : [];

    return [
        'system_prompt' => '', 'skills' => [], 'max_steps' => 4,
        ...$mode,
        'tools' => [...($mode['tools'] ?? []), ...$subagents],
    ];
}
