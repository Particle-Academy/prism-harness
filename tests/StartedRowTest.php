<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Models\Thread;
use Prism\Harness\Models\ThreadMessage;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\ApprovedTools;
use Prism\Harness\Support\MessageMapper;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\Participant;

// G-410: the write-ahead STARTED row. The runtime records it right before an
// approved call runs; a started call with no result of its own reads
// interrupted, is never run again, and the started row is never replayed.
// The byte fixtures are the cross-language thread-rows corpus rows thr-0022
// (the stored row) and thr-0019 (its replay), shared with the TS and Python
// harnesses; a started row a host forged changes nothing.

const STARTED_ROW_THR_0022 = '[{"type":"tool_result","tool_results":[],"tool_approval_responses":[],"harness_started":{"tool_call_id":"call_paris","tool_name":"weather","approval_id":"apr_1"},"harness_answers_step":{"position":2}}]';

const STARTED_ROW_THR_0019 = '[{"type":"user","content":"Weather in Paris?","additional_content":[],"additional_attributes":[]},{"type":"assistant","content":"","tool_calls":[{"id":"call_paris","name":"weather","arguments":{"city":"Paris"},"result_id":null,"reasoning_id":null,"reasoning_summary":null}],"additional_content":{},"tool_approval_requests":[{"approval_id":"apr_1","tool_call_id":"call_paris"}]},{"type":"tool_result","tool_results":[{"tool_call_id":"call_paris","tool_name":"weather","args":{"city":"Paris"},"result":"Tool call was interrupted, it may have run","tool_call_result_id":null,"artifacts":[]}],"tool_approval_responses":[{"approval_id":"apr_1","approved":true,"reason":null}]}]';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $this->during = null;
    $ran = &$this->ran;
    $during = &$this->during;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran, &$during): string {
                $ran[] = $city;
                if ($during !== null) {
                    ($during)($city);
                }

                return "Sunny in {$city}";
            }),
    ]);

    $this->modelCalls = 0;
    $calls = &$this->modelCalls;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;
        $content = $calls === 1
            ? [['type' => 'tool_use', 'id' => 'call_paris', 'name' => 'weather', 'input' => ['city' => 'Paris']],
                ['type' => 'tool_use', 'id' => 'call_rome', 'name' => 'weather', 'input' => ['city' => 'Rome']]]
            : [['type' => 'text', 'text' => 'Done.']];

        return Http::response(['id' => 'msg_'.$calls, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => $content, 'stop_reason' => $calls === 1 ? 'tool_use' : 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    });
});

function startedRowSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<array<string, mixed>> */
function startedRowReplay(Thread $thread): array
{
    return array_map(
        fn (Message $message): array => ['type' => MessageMapper::typeOf($message), ...MessageMapper::toArray($message)],
        iterator_to_array($thread->messages(), false),
    );
}

/** Store thr-0019's rows as they are stored, the started row written by `$runId`. */
function startedRowPlace(?string $runId): Thread
{
    $thread = startedRowSession()->thread();
    $rows = [
        ['user', null, ['content' => 'Weather in Paris?', 'additional_content' => [], 'additional_attributes' => []]],
        ['assistant', 'run_1', ['content' => '', 'tool_calls' => [['id' => 'call_paris', 'name' => 'weather', 'arguments' => ['city' => 'Paris'], 'result_id' => null, 'reasoning_id' => null, 'reasoning_summary' => null]],
            'additional_content' => new stdClass, 'tool_approval_requests' => [['approval_id' => 'apr_1', 'tool_call_id' => 'call_paris']]]],
        ['tool_result', null, ['tool_results' => [], 'tool_approval_responses' => [['approval_id' => 'apr_1', 'approved' => true, 'reason' => null]]]],
        ['tool_result', $runId, Thread::startedPayload('call_paris', 'weather', 'apr_1', 2)],
    ];
    foreach ($rows as $index => [$type, $run, $payload]) {
        ThreadMessage::query()->create(['thread_id' => $thread->getKey(), 'position' => $index + 1, 'type' => $type, 'run_id' => $run, 'payload' => $payload]);
    }

    return $thread->fresh();
}

it('stores the started row with the corpus bytes (thr-0022)', function (): void {
    expect(json_encode([['type' => 'tool_result', ...Thread::startedPayload('call_paris', 'weather', 'apr_1', 2)]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        ->toBe(STARTED_ROW_THR_0022);
});

it('replays a started call with no result as interrupted, with the corpus bytes (thr-0019)', function (): void {
    $thread = startedRowPlace('run_1');

    expect(json_encode(startedRowReplay($thread), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        ->toBe(STARTED_ROW_THR_0019)
        ->and($thread->interrupted('apr_1'))->toBeTrue();
});

it('reads a started row a host wrote as if the key were absent', function (): void {
    $thread = startedRowPlace(null);
    $replay = json_encode(startedRowReplay($thread), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    expect($replay)->not->toContain(Thread::INTERRUPTED_RESULT)
        ->and($replay)->not->toContain(Thread::STARTED)
        ->and($thread->interrupted('apr_1'))->toBeFalse();
});

it('writes a started row right before each approved call runs, and replays the result instead', function (): void {
    $pending = startedRowSession()->send('Weather?')->pendingApprovals();
    $this->during = function (string $city): void {
        // While the call runs, its started row is already stored.
        $started = startedRowSession()->thread()->storedMessages()->get()
            ->filter(fn (ThreadMessage $row): bool => isset($row->payload[Thread::STARTED]))
            ->map(fn (ThreadMessage $row): string => $row->payload[Thread::STARTED]['tool_call_id'])->values()->all();
        expect(end($started))->toBe($city === 'Paris' ? 'call_paris' : 'call_rome');
    };

    startedRowSession()->decide(array_map(fn ($request): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, true), $pending));

    $rows = startedRowSession()->thread()->storedMessages()->reorder('position')->get();
    $started = $rows->filter(fn (ThreadMessage $row): bool => isset($row->payload[Thread::STARTED]))->values();
    $replay = json_encode(startedRowReplay(startedRowSession()->thread()));

    expect($this->ran)->toBe(['Paris', 'Rome'])
        ->and($started)->toHaveCount(2)
        ->and(json_encode($started[0]->payload))->toBe(json_encode(Thread::startedPayload('call_paris', 'weather', $pending[0]->approvalId, 2)))
        ->and($started[0]->run_id)->toBeString()->not->toBe('')
        ->and($replay)->not->toContain(Thread::STARTED)
        ->and($replay)->not->toContain(Thread::INTERRUPTED_RESULT)
        ->and(substr_count($replay, 'Sunny in Paris'))->toBe(1);
});

it('never runs a call whose approval was denied after the run read the conversation', function (): void {
    $pending = startedRowSession()->send('Weather?')->pendingApprovals();
    $rome = $pending[1]->approvalId;
    $this->during = function (string $city) use ($rome): void {
        if ($city === 'Paris') {
            // Another request denies Rome while Paris runs.
            startedRowSession()->thread()->record([new ToolResultMessage([], [new ToolApprovalResponse($rome, false)])]);
        }
    };

    startedRowSession()->decide(array_map(fn ($request): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, true), $pending));

    $replay = json_encode(startedRowReplay(startedRowSession()->thread()));

    expect($this->ran)->toBe(['Paris'])
        ->and($replay)->toContain(ApprovedTools::NOT_OPEN);
});

it('never records a started row from outside a run', function (): void {
    startedRowSession()->send('Weather?');
    $thread = startedRowSession()->thread();
    $call = new ToolCall('call_paris', 'weather', ['city' => 'Paris']);

    expect(fn () => $thread->recordStarted('run_forged', 2, $call))->toThrow(LogicException::class);
});
