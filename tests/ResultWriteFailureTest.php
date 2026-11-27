<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Models\Thread;
use Prism\Harness\Models\ThreadMessage;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// G-410: an approved call runs, and then the write of its result row fails.
// Its write-ahead started row is stored, so the call reads interrupted and a
// later resume never runs it again.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = 0;
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->using(function () use (&$ran): string {
            $ran++;

            return 'sunny';
        }),
    ]);

    $this->modelCalls = 0;
    $calls = &$this->modelCalls;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;
        $content = $calls === 1
            ? [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'weather', 'input' => new stdClass]]
            : [['type' => 'text', 'text' => 'Done.']];
        $stop = $calls === 1 ? 'tool_use' : 'end_turn';
        if ((json_decode((string) $request->body(), true)['stream'] ?? false) === true) {
            return Http::response(resultWriteSse('msg_'.$calls, $content, $stop), 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response(['id' => 'msg_'.$calls, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    });

    // The store fails once, on the first row that carries the call's result.
    $this->armed = false;
    $armed = &$this->armed;
    ThreadMessage::creating(function (ThreadMessage $row) use (&$armed): void {
        if (! $armed || $row->type !== 'tool_result') {
            return;
        }
        foreach ($row->payload['tool_results'] ?? [] as $result) {
            if (($result['tool_call_id'] ?? null) === 'toolu_1') {
                $armed = false;

                throw new RuntimeException('result write failed');
            }
        }
    });
});

/** @param  list<array<string, mixed>>  $content */
function resultWriteSse(string $id, array $content, string $stop): string
{
    $events = [['type' => 'message_start', 'message' => ['id' => $id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]];
    foreach ($content as $index => $block) {
        if ($block['type'] === 'text') {
            $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']];
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]];
        } else {
            $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => new stdClass]];
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']];
        }
        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop, 'stop_sequence' => null], 'usage' => ['output_tokens' => 1]];
    $events[] = ['type' => 'message_stop'];
    $newline = chr(10);

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type'].$newline.'data: '.json_encode($event).$newline.$newline, $events));
}

function resultWriteSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return array<string, Closure(string): mixed> */
function resultWriteDecisions(): array
{
    $drain = function (iterable $events): void {
        foreach ($events as $event) {
            // drained
        }
    };

    return [
        'approve' => fn (string $id): mixed => resultWriteSession()->approve($id),
        'decide' => fn (string $id): mixed => resultWriteSession()->decide([new ToolApprovalResponse($id, true)]),
        'streamDecision' => fn (string $id): mixed => $drain(resultWriteSession()->streamDecision([new ToolApprovalResponse($id, true)])),
    ];
}

/** @return array<string, Closure(): mixed> */
function resultWriteResumes(): array
{
    return [
        'send' => fn (): mixed => resultWriteSession()->send(''),
        'stream' => function (): void {
            foreach (resultWriteSession()->stream('') as $event) {
                // drained
            }
        },
    ];
}

it('never runs an approved call again after its result write failed', function (string $decision, string $resume): void {
    $id = resultWriteSession()->send('Weather?')->pendingApprovals()[0]->approvalId;

    $this->armed = true;
    $failure = null;
    try {
        resultWriteDecisions()[$decision]($id);
    } catch (Throwable $e) {
        $failure = $e;
    }
    $ranAtFailure = $this->ran;

    $errors = [];
    for ($i = 0; $i < 3; $i++) {
        try {
            resultWriteResumes()[$resume]();
        } catch (Throwable $e) {
            $errors[] = $e::class;
        }
    }

    $replay = json_encode(iterator_to_array(resultWriteSession()->thread()->messages(), false));

    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($ranAtFailure)->toBe(1)
        ->and($this->ran)->toBe(1)
        ->and($errors)->toBe([])
        ->and(substr_count($replay, Thread::INTERRUPTED_RESULT))->toBe(1);
})->with(array_keys(resultWriteDecisions()))->with(array_keys(resultWriteResumes()));
