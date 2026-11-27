<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\Participant;

// G-415: a host records an assistant row through Thread::record() carrying a
// stored step's provider content (OpenAI `output_items`, Anthropic
// `content_blocks`): a note with no tool calls, or a copy with a call of its
// own. Core sends that content in place of the row's text and calls, so the
// step's function_call / tool_use would go out again with nothing answering
// it, in every later request, and the row's own text would never be sent.
// Provider content is replayed only when it is the row's own; every row the
// harness recorded replays its provider content unchanged.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = 0;
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([(new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$ran): string {
            $ran++;

            return "Sunny in {$city}";
        })]);

    $this->requests = [];
    $requests = &$this->requests;
    Http::fake(function (Request $request) use (&$requests) {
        $body = json_decode((string) $request->body(), true);
        $requests[] = $body;
        $n = count($requests);
        if (str_contains($request->url(), 'anthropic')) {
            $content = $n === 1
                ? [['type' => 'thinking', 'thinking' => 'Paris first.', 'signature' => 'sig_1'], ['type' => 'text', 'text' => 'Let me check.'],
                    ['type' => 'tool_use', 'id' => 'toolu_paris', 'name' => 'weather', 'input' => ['city' => 'Paris']]]
                : [['type' => 'text', 'text' => 'Done '.$n.'.']];

            return Http::response(['id' => 'm'.$n, 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => $content,
                'stop_reason' => $n === 1 ? 'tool_use' : 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
        }
        // A reasoning model's tool step: its reasoning item, then its call.
        $output = $n === 1
            ? [['id' => 'rs_1', 'type' => 'reasoning', 'summary' => []],
                ['id' => 'fc_paris', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'call_paris', 'name' => 'weather']]
            : [['id' => 'msg_'.$n, 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Done '.$n.'.', 'annotations' => []]]]];

        return Http::response(['id' => 'resp_'.$n, 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o', 'output' => $output,
            'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2]]);
    });
});

function hostNoteSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** The host's row carrying the stored step's provider content: a note, or a copy with a call of its own. */
function hostNoteRecord(string $shape): void
{
    $thread = hostNoteSession()->thread();
    $step = $thread->storedMessages()->where('type', 'assistant')->reorder('position')->get()
        ->first(fn ($row): bool => ! empty($row->payload['tool_calls']))->toPrismMessage();
    $thread->record([$shape === 'note'
        ? new AssistantMessage('A note.', [], $step->additionalContent)
        : new AssistantMessage('A note.', [new ToolCall('q', 'weather', ['city' => 'Rome'], 'q')], $step->additionalContent)]);
}

/**
 * Every sent request's tool calls without an answer right after them.
 *
 * @param  list<array<string, mixed>>  $requests
 * @return list<string>
 */
function hostNoteOrphans(array $requests): array
{
    $orphans = [];
    foreach ($requests as $r => $body) {
        if (isset($body['input'])) {
            // OpenAI Responses: a run of function_call items is answered by
            // the function_call_output items right after it.
            $items = $body['input'];
            for ($i = 0, $n = count($items); $i < $n; $i++) {
                if (($items[$i]['type'] ?? null) !== 'function_call') {
                    continue;
                }
                $calls = [];
                for (; $i < $n && ($items[$i]['type'] ?? null) === 'function_call'; $i++) {
                    $calls[] = $items[$i]['call_id'] ?? null;
                }
                $outputs = [];
                for ($j = $i; $j < $n && ($items[$j]['type'] ?? null) === 'function_call_output'; $j++) {
                    $outputs[] = $items[$j]['call_id'] ?? null;
                }
                foreach ($calls as $call) {
                    if (! in_array($call, $outputs, true)) {
                        $orphans[] = "request {$r}: function_call ".json_encode($call).' has no function_call_output after it';
                    }
                }
                $i--;
            }

            continue;
        }
        $messages = $body['messages'] ?? [];
        foreach ($messages as $i => $message) {
            $content = is_array($message['content'] ?? null) ? $message['content'] : [];
            $next = is_array($messages[$i + 1]['content'] ?? null) ? $messages[$i + 1]['content'] : [];
            $answered = array_column(array_filter($next, fn ($b): bool => ($b['type'] ?? null) === 'tool_result'), 'tool_use_id');
            foreach ($content as $block) {
                if (($block['type'] ?? null) === 'tool_use' && ! in_array($block['id'], $answered, true)) {
                    $orphans[] = "request {$r}: tool_use ".json_encode($block['id']).' has no tool_result in the next message';
                }
            }
        }
    }

    return $orphans;
}

/**
 * OpenAI input rules beyond pairing: no item id sent twice, and a reasoning
 * item followed by a function_call or a message.
 *
 * @param  list<array<string, mixed>>  $requests
 * @return list<string>
 */
function hostNoteInputErrors(array $requests): array
{
    $errors = [];
    foreach ($requests as $r => $body) {
        $items = array_values($body['input'] ?? []);
        $ids = array_values(array_filter(array_map(fn ($item) => $item['id'] ?? null, $items), 'is_string'));
        foreach (array_count_values($ids) as $id => $count) {
            if ($count > 1) {
                $errors[] = "request {$r}: item {$id} is sent {$count} times";
            }
        }
        foreach ($items as $i => $item) {
            $next = $items[$i + 1] ?? [];
            if (($item['type'] ?? null) === 'reasoning' && ! in_array($next['type'] ?? null, ['function_call', 'message'], true) && ($next['role'] ?? null) !== 'assistant') {
                $errors[] = "request {$r}: reasoning item {$item['id']} is not followed by its item";
            }
        }
    }

    return $errors;
}

it('never sends a host row\'s copied tool calls, and sends its own text', function (string $provider, string $shape, string $when): void {
    config()->set('prism-harness.agent.provider', $provider);
    config()->set('prism-harness.agent.model', $provider === 'openai' ? 'gpt-4o' : 'claude-sonnet-5-5');

    $id = hostNoteSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    if ($when === 'while parked') {
        hostNoteRecord($shape);
        hostNoteSession()->approve($id);
    } else {
        hostNoteSession()->approve($id);
        hostNoteRecord($shape);
    }
    hostNoteSession()->send('Next question.');
    hostNoteSession()->send('');

    // The row is sent from its own text (and its own call), once; the step's
    // own text block, once, with the step.
    $last = json_encode(end($this->requests));

    expect(hostNoteOrphans($this->requests))->toBe([])
        ->and(hostNoteInputErrors($this->requests))->toBe([])
        ->and(substr_count($last, 'A note.'))->toBe(1)
        ->and(substr_count($last, 'Let me check.'))->toBe($provider === 'openai' ? 0 : 1)
        ->and($this->ran)->toBe($when === 'while parked' ? 0 : 1);
})->with(['OpenAI' => 'openai', 'Anthropic' => 'anthropic'])
    ->with(['a note with no tool calls' => 'note', 'a copy with a call of its own' => 'copy'])
    ->with(['while parked' => 'while parked', 'after the step resolved' => 'after the step resolved']);

it('replays every provider content the harness recorded unchanged', function (string $provider): void {
    config()->set('prism-harness.agent.provider', $provider);
    config()->set('prism-harness.agent.model', $provider === 'openai' ? 'gpt-4o' : 'claude-sonnet-5-5');

    $id = hostNoteSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    hostNoteSession()->approve($id);
    hostNoteSession()->send('Next question.');

    // Every assistant row, as replayed and as stored, in order.
    $replayed = array_values(array_filter(iterator_to_array(hostNoteSession()->thread()->messages(), false), fn ($message): bool => $message instanceof AssistantMessage));
    $stored = hostNoteSession()->thread()->storedMessages()->where('type', 'assistant')->reorder('position')->get()->map(fn ($row) => $row->toPrismMessage())->all();

    expect(array_map(fn (AssistantMessage $message): array => $message->additionalContent, $replayed))
        ->toBe(array_map(fn (AssistantMessage $message): array => $message->additionalContent, $stored))
        ->and(count($stored))->toBe(3)
        ->and($this->ran)->toBe(1);
})->with(['OpenAI' => 'openai', 'Anthropic' => 'anthropic']);

it('never sends the reasoning a host row copied', function (string $provider): void {
    config()->set('prism-harness.agent.provider', $provider);
    config()->set('prism-harness.agent.model', $provider === 'openai' ? 'gpt-4o' : 'claude-sonnet-5-5');

    $id = hostNoteSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    hostNoteSession()->approve($id);
    // A host row whose text and (absent) calls match its provider content,
    // but whose reasoning is the step's: it cannot be checked, so it is not
    // the row's own.
    $thread = hostNoteSession()->thread();
    $step = $thread->storedMessages()->where('type', 'assistant')->reorder('position')->get()
        ->first(fn ($row): bool => ! empty($row->payload['tool_calls']))->toPrismMessage();
    $thread->record([new AssistantMessage('Thinking.', [], $provider === 'openai'
        ? ['output_items' => [$step->additionalContent['output_items'][0], ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Thinking.']]]]]
        : ['content_blocks' => [$step->additionalContent['content_blocks'][0], ['type' => 'text', 'text' => 'Thinking.']]])]);
    hostNoteSession()->send('Next question.');

    $last = json_encode(end($this->requests));

    expect(hostNoteOrphans($this->requests))->toBe([])
        ->and(hostNoteInputErrors($this->requests))->toBe([])
        ->and(substr_count($last, 'Thinking.'))->toBe(1)
        ->and(substr_count($last, $provider === 'openai' ? '"rs_1"' : '"signature":"sig_1"'))->toBe(1)
        ->and(substr_count($last, Thread::HOST_ROW))->toBe(0);
})->with(['OpenAI' => 'openai', 'Anthropic' => 'anthropic']);
