<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\Participant;

// G-412: a host records an assistant step with an approval request through
// Thread::record() without its tool offer ($toolNames). The step is offered
// the tools its own approval requests name, so the next turn resolves it: an
// undecided call gets its denial, an approved one runs. A call naming a tool
// that is not offered gets exactly one outcome, its denial. Either way every
// sent request answers each tool call in the next message.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    // `clock` is offered and takes no approval; `phantom` is listed and never registered.
    config()->set('prism-harness.agent.modes.chat.tools', ['weather', 'clock', 'phantom']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([(new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$ran): string {
            $ran[] = $city;

            return "Sunny in {$city}";
        }),
        (new Tool)->as('clock')->for('Clock.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran[] = 'clock:'.$city;

                return 'noon';
            })]);

    $this->requests = [];
    $this->rejected = 0;
    $requests = &$this->requests;
    $rejected = &$this->rejected;
    Http::fake(function (Request $request) use (&$requests, &$rejected) {
        $body = json_decode((string) $request->body(), true);
        $requests[] = $body;
        $n = count($requests);
        // Strict, as the providers are: a request with a tool call that is
        // not answered right after it is refused.
        if (hostOfferOrphans([$body]) !== []) {
            $rejected++;

            return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'tool call without its result']], 400);
        }
        if (str_contains($request->url(), 'anthropic')) {
            $content = $n === 1
                ? [['type' => 'tool_use', 'id' => 'toolu_paris', 'name' => 'weather', 'input' => ['city' => 'Paris']]]
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

function hostOfferSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<string> sent tool calls with no answer right after them, on either provider */
function hostOfferOrphans(array $requests): array
{
    $orphans = [];
    foreach ($requests as $r => $body) {
        if (isset($body['input'])) {
            $items = array_values($body['input']);
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
                        $orphans[] = "request {$r}: function_call ".json_encode($call);
                    }
                }
                $i--;
            }

            continue;
        }
        $messages = $body['messages'] ?? [];
        foreach ($messages as $i => $message) {
            $next = is_array($messages[$i + 1]['content'] ?? null) ? $messages[$i + 1]['content'] : [];
            $answered = array_column(array_filter($next, fn ($b): bool => ($b['type'] ?? null) === 'tool_result'), 'tool_use_id');
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (($block['type'] ?? null) === 'tool_use' && ! in_array($block['id'], $answered, true)) {
                    $orphans[] = "request {$r}: tool_use ".json_encode($block['id']);
                }
            }
        }
    }

    return $orphans;
}

/** The host's step, recorded with no tool offer. */
function hostOfferRecord(string $shape): string
{
    $thread = hostOfferSession()->thread();
    if ($shape === 'a copy of the resolved step') {
        $step = $thread->storedMessages()->where('type', 'assistant')->reorder('position')->get()
            ->first(fn ($row): bool => ! empty($row->payload['tool_calls']))->toPrismMessage();
        $thread->record([$step]);

        return $step->toolApprovalRequests[0]->approvalId;
    }
    $tool = match ($shape) {
        'a step naming a tool not offered' => 'ghost',
        'a step naming a listed tool that is not registered' => 'phantom',
        'a step requesting a tool that takes no approval' => 'clock',
        default => 'weather',
    };
    $thread->record([new AssistantMessage('', [new ToolCall('h1', $tool, ['city' => 'Rome'], 'h1')], [], [new ToolApprovalRequest('apr_host', 'h1')])]);

    return 'apr_host';
}

it('answers every call of a host step recorded without its tool offer', function (string $provider, string $shape, string $resume): void {
    config()->set('prism-harness.agent.provider', $provider);
    config()->set('prism-harness.agent.model', $provider === 'openai' ? 'gpt-4o' : 'claude-sonnet-5-5');

    $id = hostOfferSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    hostOfferSession()->approve($id);
    $hostApproval = hostOfferRecord($shape);
    if ($resume === 'approve') {
        hostOfferSession()->approve($hostApproval);
    } else {
        hostOfferSession()->send('');
    }
    hostOfferSession()->send('Next question.');

    // Paris ran on its own approval. The host's call runs only when its own
    // request is approved and its tool is offered; a copy never runs again.
    $expected = $shape === 'a new step with its own request' && $resume === 'approve' ? ['Paris', 'Rome'] : ['Paris'];

    expect(hostOfferOrphans($this->requests))->toBe([])
        ->and($this->rejected)->toBe(0)
        ->and($this->ran)->toBe($expected);
})->with(['OpenAI' => 'openai', 'Anthropic' => 'anthropic'])
    ->with(['a new step with its own request', 'a copy of the resolved step', 'a step naming a tool not offered',
        'a step naming a listed tool that is not registered', 'a step requesting a tool that takes no approval'])
    ->with(['an empty resume' => 'empty', 'an approval' => 'approve']);
