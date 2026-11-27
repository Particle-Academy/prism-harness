<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Support\MessageMapper;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\Participant;

// While a call runs, the host records a row of its own (a user row or an
// assistant text row) through Thread::record(). Every tool call must still be
// replayed with its result directly after its own step, and every provider
// request after it must pair each tool_use with a tool_result in the very
// next message: a provider rejects one that does not, on every later turn.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather', 'clock']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->hostRow = null;
    $this->ran = [];
    $hostRow = &$this->hostRow;
    $ran = &$this->ran;
    $during = function (string $name) use (&$hostRow, &$ran): string {
        $ran[] = $name;
        if ($hostRow !== null) {
            hostRowCallSession()->thread()->record([$hostRow === 'user' ? new UserMessage('A note from the host.') : new AssistantMessage('A note from the host.')]);
        }

        return "{$name} ok";
    };
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->using(fn (): string => $during('weather')),
        (new Tool)->as('clock')->for('Clock.')->using(fn (): string => $during('clock')),
    ]);

    $this->requests = [];
    $this->tool = 'weather';
    $requests = &$this->requests;
    $tool = &$this->tool;
    Http::fake(function (Request $request) use (&$requests, &$tool) {
        $body = json_decode((string) $request->body(), true);
        $requests[] = $body['messages'] ?? [];
        $n = count($requests);
        $content = $n === 1
            ? [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $tool, 'input' => new stdClass]]
            : [['type' => 'text', 'text' => 'Done '.$n.'.']];
        $stop = $n === 1 ? 'tool_use' : 'end_turn';
        if (($body['stream'] ?? false) === true) {
            $nl = chr(10);
            $events = [['type' => 'message_start', 'message' => ['id' => 'm'.$n, 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]];
            foreach ($content as $i => $block) {
                $events[] = $block['type'] === 'text'
                    ? ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']]
                    : ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => new stdClass]];
                $events[] = $block['type'] === 'text'
                    ? ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]]
                    : ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']];
                $events[] = ['type' => 'content_block_stop', 'index' => $i];
            }
            $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop, 'stop_sequence' => null], 'usage' => ['output_tokens' => 1]];
            $events[] = ['type' => 'message_stop'];

            return Http::response(implode('', array_map(fn (array $e): string => 'event: '.$e['type'].$nl.'data: '.json_encode($e).$nl.$nl, $events)), 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response(['id' => 'm'.$n, 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => $content,
            'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    });
});

function hostRowCallSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/**
 * Where the replay breaks the pairing rule: a step with tool calls not
 * followed at once by a tool result message holding a result for each call.
 *
 * @return list<string>
 */
function hostRowCallReplayOrphans(): array
{
    $messages = iterator_to_array(hostRowCallSession()->thread()->messages(), false);
    $orphans = [];
    foreach ($messages as $i => $message) {
        if (! $message instanceof AssistantMessage || $message->toolCalls === []) {
            continue;
        }
        $next = $messages[$i + 1] ?? null;
        $answered = $next instanceof ToolResultMessage ? array_map(fn ($result): string => $result->toolCallId, $next->toolResults) : [];
        foreach ($message->toolCalls as $call) {
            if (! in_array($call->id, $answered, true)) {
                $orphans[] = "replay {$i}: {$call->id} is followed by ".($next === null ? 'nothing' : MessageMapper::typeOf($next));
            }
        }
    }

    return $orphans;
}

/**
 * The same rule on every provider request sent: each tool_use block's id has
 * a tool_result block in the very next message.
 *
 * @param  list<list<array<string, mixed>>>  $requests
 * @return list<string>
 */
function hostRowCallRequestOrphans(array $requests): array
{
    $orphans = [];
    foreach ($requests as $r => $messages) {
        foreach ($messages as $i => $message) {
            $uses = array_filter(is_array($message['content'] ?? null) ? $message['content'] : [], fn ($block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_use');
            if ($uses === []) {
                continue;
            }
            $next = $messages[$i + 1]['content'] ?? [];
            $results = array_map(fn ($block) => $block['tool_use_id'] ?? null, array_filter(is_array($next) ? $next : [], fn ($block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_result'));
            foreach ($uses as $use) {
                if (! in_array($use['id'], $results, true)) {
                    $orphans[] = "request {$r} message {$i}: {$use['id']} has no tool_result in the next message";
                }
            }
        }
    }

    return $orphans;
}

it('replays every result directly after its own step when the host records a row while the call runs', function (string $tool, string $row, string $entry): void {
    $this->tool = $tool;
    $start = fn (string $prompt) => $entry === 'stream' ? iterator_to_array(hostRowCallSession()->stream($prompt), false) : hostRowCallSession()->send($prompt);

    if ($tool === 'weather') {
        $pending = hostRowCallSession()->send('Weather?')->pendingApprovals();
        $this->hostRow = $row;
        hostRowCallSession()->approve($pending[0]->approvalId);
    } else {
        $this->hostRow = $row;
        $start('What time?');
    }
    $this->hostRow = null;
    $replay = hostRowCallReplayOrphans();

    $failures = [];
    foreach (['Next question.', ''] as $prompt) {
        try {
            $start($prompt);
        } catch (Throwable $e) {
            $failures[] = class_basename($e);
        }
    }

    expect($this->ran)->toBe([$tool])
        ->and($replay)->toBe([])
        ->and(hostRowCallRequestOrphans($this->requests))->toBe([])
        ->and($failures)->toBe([]);
})->with(['approved call' => 'weather', 'ungated call' => 'clock'])
    ->with(['user row' => 'user', 'assistant text row' => 'assistant'])
    ->with(['send' => 'send', 'stream' => 'stream']);
