<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Tests\Fixtures\Participant;

// G-415: a host records an assistant note through Thread::record() with no
// tool calls, but carrying a stored step's provider content (OpenAI
// `output_items`, Anthropic `content_blocks`). Core replays that content as
// it is, so the step's function_call / tool_use would be sent again with
// nothing answering it, in every later request. The note's tool items are
// not replayed; its text is. A step the harness recorded replays its
// provider content unchanged.

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
                ? [['type' => 'text', 'text' => 'Let me check.'], ['type' => 'tool_use', 'id' => 'toolu_paris', 'name' => 'weather', 'input' => ['city' => 'Paris']]]
                : [['type' => 'text', 'text' => 'Done '.$n.'.']];

            return Http::response(['id' => 'm'.$n, 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => $content,
                'stop_reason' => $n === 1 ? 'tool_use' : 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
        }
        $output = $n === 1
            ? [['id' => 'fc_paris', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'call_paris', 'name' => 'weather']]
            : [['id' => 'msg_'.$n, 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Done '.$n.'.', 'annotations' => []]]]];

        return Http::response(['id' => 'resp_'.$n, 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o', 'output' => $output,
            'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2]]);
    });
});

function hostNoteSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** The host's note: no tool calls, the stored step's provider content. */
function hostNoteRecord(): void
{
    $thread = hostNoteSession()->thread();
    $step = $thread->storedMessages()->where('type', 'assistant')->reorder('position')->get()
        ->first(fn ($row): bool => ! empty($row->payload['tool_calls']))->toPrismMessage();
    $thread->record([new AssistantMessage('A note.', [], $step->additionalContent)]);
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

it('never sends a host note\'s copied tool calls again', function (string $provider, string $when): void {
    config()->set('prism-harness.agent.provider', $provider);
    config()->set('prism-harness.agent.model', $provider === 'openai' ? 'gpt-4o' : 'claude-sonnet-5-5');

    $id = hostNoteSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    if ($when === 'while parked') {
        hostNoteRecord();
        hostNoteSession()->approve($id);
    } else {
        hostNoteSession()->approve($id);
        hostNoteRecord();
    }
    hostNoteSession()->send('Next question.');
    hostNoteSession()->send('');

    // The note is still replayed: its provider content's text (the step's
    // "Let me check." block, which core prefers to the row's own text), or,
    // when only tool items were copied, its own text.
    $last = json_encode(end($this->requests));

    expect(hostNoteOrphans($this->requests))->toBe([])
        ->and($provider === 'openai' ? substr_count($last, 'A note.') : substr_count($last, 'Let me check.'))->toBe($provider === 'openai' ? 1 : 2)
        ->and($this->ran)->toBe($when === 'while parked' ? 0 : 1);
})->with(['OpenAI' => 'openai', 'Anthropic' => 'anthropic'])
    ->with(['while parked' => 'while parked', 'after the step resolved' => 'after the step resolved']);

it('replays a harness-written step\'s provider content unchanged', function (string $provider): void {
    config()->set('prism-harness.agent.provider', $provider);
    config()->set('prism-harness.agent.model', $provider === 'openai' ? 'gpt-4o' : 'claude-sonnet-5-5');

    $id = hostNoteSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    hostNoteSession()->approve($id);

    $step = collect(iterator_to_array(hostNoteSession()->thread()->messages(), false))
        ->first(fn ($message): bool => $message instanceof AssistantMessage && $message->toolCalls !== []);
    $stored = hostNoteSession()->thread()->storedMessages()->where('type', 'assistant')->reorder('position')->first()->toPrismMessage();

    expect($step->additionalContent)->toBe($stored->additionalContent)
        ->and($this->ran)->toBe(1);
})->with(['OpenAI' => 'openai', 'Anthropic' => 'anthropic']);
