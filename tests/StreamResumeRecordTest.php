<?php

declare(strict_types=1);

use Prism\Harness\PrismHarness;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Participant;

// When a plain stream ends before core's own record, the harness records only
// what core's approval resolution produced: results for calls of the last
// assistant step that had no result yet and were decided after it. A custom
// stream provider is not required to send a StreamStartEvent, so a tool result
// of its own step can arrive before any; it is not a resolution and is never
// recorded against an earlier turn.

final class NoStreamStartProvider extends Provider
{
    public static bool $start = false;

    public function stream(TextRequest $request): Generator
    {
        if (self::$start) {
            yield new StreamStartEvent(EventID::generate(), time(), 'm', 'no-start');
        }
        $call = new ToolCall('call_new', 'search', ['query' => 'n']);
        yield new ToolCallEvent(EventID::generate(), time(), $call, 'msg');
        yield new ToolResultEvent(EventID::generate(), time(), new ToolResult('call_new', 'search', ['query' => 'n'], 'new-result'), 'msg');
        yield new StreamEndEvent(EventID::generate(), time(), FinishReason::Stop);
    }
}

it('records no resolution for a provider step\'s own tool result when the stream is abandoned', function (bool $start): void {
    NoStreamStartProvider::$start = $start;
    app(PrismManager::class)->extend('no-start', fn () => new NoStreamStartProvider, replace: true);
    config()->set('prism-harness.agent.provider', 'no-start');
    config()->set('prism-harness.agent.model', 'm');
    config()->set('prism-harness.agent.max_cost_usd', null);
    config()->set('prism-harness.agent.modes.chat.tools', ['search']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', []);
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $session->thread()->record([
        new UserMessage('q'),
        new AssistantMessage('', [new ToolCall('call_old', 'search', ['query' => 'x'])]),
        new ToolResultMessage([new ToolResult('call_old', 'search', ['query' => 'x'], 'old')]),
        new AssistantMessage('ok'),
    ], null);

    $stream = $session->stream('hi');
    foreach ($stream as $event) {
        if ($event instanceof ToolResultEvent) {
            break;
        }
    }
    unset($stream, $event);
    gc_collect_cycles();

    // The earlier turn's rows, then only this turn's prompt: no tool result
    // row was added for a call that was never part of a resolution.
    expect($session->thread()->storedMessages()->orderBy('id')->pluck('type')->all())
        ->toBe(['user', 'assistant', 'tool_result', 'assistant', 'user']);
})->with(['no StreamStartEvent' => false, 'with StreamStartEvent' => true]);
