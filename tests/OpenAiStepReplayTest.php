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
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// G-415: an OpenAI step the harness records is replayed exactly as stored,
// streamed or not, with or without a reasoning item: a streamed step that
// parked is stored with no provider content (core keeps its output items
// only for steps it continues), and its calls name their reasoning item, so
// core sends that reasoning item before the call. The harness never
// rewrites such a row. And a write through recordForRun() from outside the
// run that holds the session is a host's write, marked as record() marks it.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = 0;
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([(new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$ran): string {
            $ran++;

            return "Sunny in {$city}";
        })]);

    $this->reasoning = true;
    $this->requests = [];
    $requests = &$this->requests;
    $reasoning = &$this->reasoning;
    Http::fake(function (Request $request) use (&$requests, &$reasoning) {
        $body = json_decode((string) $request->body(), true);
        $requests[] = $body;
        $n = count($requests);
        $output = $n === 1
            ? [
                ...($reasoning ? [['id' => 'rs_1', 'type' => 'reasoning', 'summary' => []]] : []),
                ['id' => 'msg_1', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Let me check.', 'annotations' => []]]],
                ['id' => 'fc_p', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'call_p', 'name' => 'weather'],
            ]
            : [['id' => 'msg_'.$n, 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Done '.$n.'.', 'annotations' => []]]]];
        $response = ['id' => 'resp_'.$n, 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-5', 'output' => $output,
            'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2]];
        if (($body['stream'] ?? false) !== true) {
            return Http::response($response);
        }

        return Http::response(openAiStepSse($response), 200, ['Content-Type' => 'text/event-stream']);
    });
});

/**
 * The Responses API's stream of `$response`, item by item.
 *
 * @param  array<string, mixed>  $response
 */
function openAiStepSse(array $response): string
{
    $events = [['type' => 'response.created', 'response' => [...$response, 'status' => 'in_progress', 'output' => []]]];
    foreach ($response['output'] as $index => $item) {
        if ($item['type'] === 'reasoning') {
            $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => $item];
            $events[] = ['type' => 'response.output_item.done', 'output_index' => $index, 'item' => $item];
        } elseif ($item['type'] === 'message') {
            $text = $item['content'][0]['text'];
            $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => [...$item, 'status' => 'in_progress', 'content' => []]];
            $events[] = ['type' => 'response.output_text.delta', 'output_index' => $index, 'item_id' => $item['id'], 'content_index' => 0, 'delta' => $text];
            $events[] = ['type' => 'response.output_text.done', 'output_index' => $index, 'item_id' => $item['id'], 'content_index' => 0, 'text' => $text];
            $events[] = ['type' => 'response.output_item.done', 'output_index' => $index, 'item' => $item];
        } else {
            $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => [...$item, 'status' => 'in_progress', 'arguments' => '']];
            $events[] = ['type' => 'response.function_call_arguments.delta', 'output_index' => $index, 'item_id' => $item['id'], 'delta' => $item['arguments']];
            $events[] = ['type' => 'response.function_call_arguments.done', 'output_index' => $index, 'item_id' => $item['id'], 'arguments' => $item['arguments']];
            $events[] = ['type' => 'response.output_item.done', 'output_index' => $index, 'item' => $item];
        }
    }
    $events[] = ['type' => 'response.completed', 'response' => $response];
    $newline = chr(10);

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type'].$newline.'data: '.json_encode($event).$newline.$newline, $events));
}

function openAiStepSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<AssistantMessage> */
function openAiStepReplayed(): array
{
    return array_values(array_filter(iterator_to_array(openAiStepSession()->thread()->messages(), false), fn ($message): bool => $message instanceof AssistantMessage));
}

it('replays the OpenAI step the harness recorded exactly as stored', function (string $entry, bool $reasoning): void {
    $this->reasoning = $reasoning;
    if ($entry === 'stream') {
        iterator_to_array(openAiStepSession()->stream('Weather in Paris?'), false);
        $id = openAiStepSession()->thread()->storedMessages()->where('type', 'assistant')->first()->payload['tool_approval_requests'][0]['approval_id'];
        iterator_to_array(openAiStepSession()->streamDecision([new ToolApprovalResponse($id, true)]), false);
    } else {
        $id = openAiStepSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
        openAiStepSession()->approve($id);
    }
    openAiStepSession()->send('Next question.');

    $stored = openAiStepSession()->thread()->storedMessages()->where('type', 'assistant')->reorder('position')->get()->map(fn ($row) => $row->toPrismMessage())->all();
    $replayed = openAiStepReplayed();

    // Byte for byte: the provider content and the calls of every row.
    expect(json_encode(array_map(fn (AssistantMessage $m): array => [$m->content, $m->additionalContent, array_map(fn ($c) => [$c->id, $c->resultId, $c->reasoningId], $m->toolCalls)], $replayed)))
        ->toBe(json_encode(array_map(fn (AssistantMessage $m): array => [$m->content, $m->additionalContent, array_map(fn ($c) => [$c->id, $c->resultId, $c->reasoningId], $m->toolCalls)], $stored)))
        ->and($this->ran)->toBe(1);

    // In the last request: the call's reasoning item once, before the call,
    // and the call's output after it.
    $input = array_values(end($this->requests)['input']);
    $at = fn (string $type, string $key, string $value): array => array_keys(array_filter($input, fn ($item): bool => ($item['type'] ?? null) === $type && ($item[$key] ?? null) === $value));
    $calls = $at('function_call', 'call_id', 'call_p');
    $outputs = $at('function_call_output', 'call_id', 'call_p');
    $reasonings = $at('reasoning', 'id', 'rs_1');

    expect($calls)->toHaveCount(1)
        ->and($outputs)->toHaveCount(1)
        ->and($outputs[0])->toBeGreaterThan($calls[0])
        ->and(count($reasonings))->toBe($reasoning ? 1 : 0);
    if ($reasoning) {
        expect($reasonings[0])->toBeLessThan($calls[0]);
    }
})->with(['streamed' => 'stream', 'not streamed' => 'send'])
    ->with(['with a reasoning item' => true, 'without one' => false]);

it('marks an assistant row written through recordForRun() from outside the run as a host row', function (): void {
    $id = openAiStepSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $thread = openAiStepSession()->thread();
    $step = $thread->storedMessages()->where('type', 'assistant')->first()->toPrismMessage();

    // A full copy of the step, written by a caller that does not hold the session.
    $thread->recordForRun([new AssistantMessage($step->content, $step->toolCalls, $step->additionalContent)], 'run_outside');
    $copy = $thread->storedMessages()->where('type', 'assistant')->reorder('position', 'desc')->first();

    expect($copy->payload['additional_content'][Thread::HOST_ROW] ?? null)->toBeTrue();

    openAiStepSession()->approve($id);
    openAiStepSession()->send('Next question.');
    $input = array_values(end($this->requests)['input']);
    $reasonings = array_filter($input, fn ($item): bool => ($item['type'] ?? null) === 'reasoning');
    $unanswered = [];
    foreach ($input as $i => $item) {
        if (($item['type'] ?? null) === 'function_call') {
            $outputs = array_filter(array_slice($input, $i + 1), fn ($later): bool => ($later['type'] ?? null) === 'function_call_output' && ($later['call_id'] ?? null) === ($item['call_id'] ?? null));
            if ($outputs === []) {
                $unanswered[] = $item['call_id'] ?? null;
            }
        }
    }

    expect(count($reasonings))->toBe(1)
        ->and($unanswered)->toBe([]);
});
