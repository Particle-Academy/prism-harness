<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

// Approvals stored by earlier releases carry no marker. On a tool with a
// parameter named `input` or `raw`, core cannot tell a real call from one an
// earlier version stored unparsed under that name, so the approval expires:
// the call does not run, and the model is told to call again.
//
// The rows in Fixtures/legacy-approvals were written by the RELEASED
// prism-harness 0.26.4 (on prism 0.151.1) and 0.26.5 (on prism 0.152.0),
// through Session::send() and Session::stream(). Each test enters through
// Session::approve() or deny(), as an application resuming them would.

const LEGACY_EXPIRED = 'was recorded by an earlier version and has expired';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.modes.chat.tools', ['translate']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['translate']);

    $this->received = [];
    $received = &$this->received;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('translate')->for('Translate.')->withStringParameter('input', 'Text')
            ->using(function (string $input) use (&$received): string {
                $received[] = $input;

                return 'Bonjour';
            }),
    ]);
});

/**
 * @return array{approval_id: string, provider: string, rows: list<array{type: string, payload: array<string, mixed>}>}
 */
function legacyFixture(string $name): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/Fixtures/legacy-approvals/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $fixture['provider'] = str_contains((string) ($fixture['transport'] ?? ''), 'anthropic') ? 'anthropic' : 'openai';

    return $fixture;
}

/**
 * A fresh session whose thread holds the fixture's rows exactly as stored.
 *
 * @param  array{provider: string, rows: list<array{type: string, payload: array<string, mixed>}>}  $fixture
 * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $edit
 */
function legacySession(array $fixture, ?callable $edit = null): Session
{
    config()->set('prism-harness.agent.provider', $fixture['provider']);
    config()->set('prism-harness.agent.model', $fixture['provider'] === 'anthropic' ? 'claude-test' : 'gpt-4o');

    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    foreach ($fixture['rows'] as $index => $row) {
        $payload = $row['type'] === 'assistant' && $edit !== null ? $edit($row['payload']) : $row['payload'];
        $session->thread()->storedMessages()->create(['position' => $index + 1, 'type' => $row['type'], 'payload' => $payload]);
    }

    // As another worker would: the thread is read back from the database.
    app()->forgetInstance(PrismHarness::class);

    return app(PrismHarness::class)->for(Participant::query()->firstOrFail())->session('chat');
}

/** @return array<string, mixed> */
function legacyUsage(): array
{
    return ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
}

/** @return array<string, mixed> */
function openAiText(string $id): array
{
    return ['id' => $id, 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4o', 'usage' => legacyUsage(), 'output' => [
        ['id' => 'msg_'.$id, 'type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]],
    ]];
}

/** @return array<string, mixed> */
function openAiCall(string $id, string $callId, string $arguments): array
{
    return ['id' => $id, 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4o', 'usage' => legacyUsage(), 'output' => [
        ['id' => 'fc_'.$id, 'type' => 'function_call', 'status' => 'completed', 'arguments' => $arguments, 'call_id' => $callId, 'name' => 'translate'],
    ]];
}

/** @return array<string, mixed> */
function anthropicText(): array
{
    return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-test', 'content' => [['type' => 'text', 'text' => 'Done.']],
        'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
}

/** The text of every tool result the thread now stores. */
function legacyToolResults(Session $session): string
{
    return (string) json_encode($session->thread()->storedMessages()->where('type', 'tool_result')->get()->pluck('payload')->all());
}

it('expires the unparsed call 0.26.4 stored instead of running it with that text', function (): void {
    $fixture = legacyFixture('v0.26.4-streamed-truncated-input');
    Http::fakeSequence()->push(anthropicText());

    $session = legacySession($fixture);
    $session->approve($fixture['approval_id']);

    expect($this->received)->toBe([])
        ->and(legacyToolResults($session))->toContain(LEGACY_EXPIRED)
        ->and(legacyToolResults($session))->not->toContain('Par');
});

it('expires once an approval an earlier release stored on a real call, then asks again and runs the new approval once', function (string $name): void {
    $fixture = legacyFixture($name);
    Http::fakeSequence()
        ->push(openAiCall('resp_2', 'call_2', '{"input":"Hello"}'))
        ->push(openAiText('resp_3'));

    $session = legacySession($fixture);
    $paused = $session->approve($fixture['approval_id']);

    expect($this->received)->toBe([])
        ->and(legacyToolResults($session))->toContain(LEGACY_EXPIRED)
        ->and($paused->pendingApprovals())->toHaveCount(1);

    app()->forgetInstance(PrismHarness::class);
    $again = app(PrismHarness::class)->for(Participant::query()->firstOrFail())->session('chat');
    $again->approve($paused->pendingApprovals()[0]);

    expect($this->received)->toBe(['Hello']);
})->with(['v0.26.4-genuine-input', 'v0.26.5-genuine-input']);

it('treats a marker it does not recognise as none', function (mixed $marker): void {
    $fixture = legacyFixture('v0.26.5-genuine-input');
    Http::fakeSequence()->push(openAiText('resp_2'));

    $session = legacySession($fixture, function (array $payload) use ($marker): array {
        $payload['tool_approval_requests'][0]['arguments_format'] = $marker;

        return $payload;
    });
    $session->approve($fixture['approval_id']);

    expect($this->received)->toBe([])
        ->and(legacyToolResults($session))->toContain(LEGACY_EXPIRED);
    // No float: the payload column is JSON written with json_encode(), which
    // stores 1.0 as 1, so a float never reaches the stored row.
})->with(['a string' => '1', 'zero' => 0, 'a future version' => 99, 'true' => true]);

it('leaves a denied legacy approval denied', function (): void {
    $fixture = legacyFixture('v0.26.4-streamed-truncated-input');
    Http::fakeSequence()->push(anthropicText());

    $session = legacySession($fixture);
    $session->deny($fixture['approval_id']);

    expect($this->received)->toBe([])
        ->and(legacyToolResults($session))->not->toContain(LEGACY_EXPIRED);
});
