<?php

declare(strict_types=1);

use Prism\Harness\AgentRuntime;
use Prism\Harness\Models\Thread;
use Prism\Harness\Support\MessageMapper;
use Prism\Harness\Support\ToolResultRuns;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * The cross-language thread-rows corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file proves the corpus has not drifted
 * from the rows Thread::record() stores and Thread::messages() replays. Without
 * it, both ports would match a snapshot of a shape this package had since
 * changed, and a thread written in one language would read differently in
 * another.
 */
function threadRowsCorpus(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/harness-thread-rows.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/** @return array<string, mixed> */
function corpusRow(Message $message): array
{
    return ['type' => MessageMapper::typeOf($message), ...MessageMapper::toArray($message)];
}

/**
 * @param  list<array<string, mixed>>  $calls
 * @return list<ToolCall>
 */
function corpusToolCalls(array $calls): array
{
    return array_map(fn (array $call): ToolCall => new ToolCall(
        $call['id'],
        $call['name'],
        $call['arguments'],
        $call['result_id'] ?? null,
        $call['reasoning_id'] ?? null,
        $call['reasoning_summary'] ?? null,
    ), $calls);
}

/**
 * @param  array<string, mixed>  $case
 * @return list<array<string, mixed>>
 */
function corpusRows(array $case): array
{
    if (($case['read'] ?? null) === 'approval_states') {
        // How each approval READS over rows as stored (0.4.0, G-387 round
        // 13): whether a runner start ended its call, by the harness's mark.
        return array_map(fn (string $id): array => ['approval_id' => $id, 'ended_by_start' => Thread::endedByStartIn($case['stored'], $id)],
            $case['approvals']);
    }
    if (isset($case['fold'])) {
        $messages = array_map(function (array $stored): Message {
            $type = $stored['type'];
            unset($stored['type']);

            return MessageMapper::fromArray($type, $stored);
        }, $case['fold']);

        return array_map(corpusRow(...), iterator_to_array(ToolResultRuns::fold($messages), false));
    }

    $input = $case['input'];
    if ($input['write'] === 'ended_by_start') {
        // The row a runner start records when it ends the calls a child was
        // parked on (G-387): which calls, with what result, and the mark
        // naming their approvals, all decided by the harness itself.
        $step = new AssistantMessage('', corpusToolCalls($input['tool_calls']), [],
            array_map(fn (array $request): ToolApprovalRequest => new ToolApprovalRequest($request['approval_id'], $request['tool_call_id']), $input['approval_requests']));
        $ended = AgentRuntime::endedCallsOf([$step]);
        expect($ended)->not->toBeNull();

        return [['type' => MessageMapper::typeOf($ended['message']), ...Thread::rowPayload($ended['message'], endedApprovals: $ended['approvals'])]];
    }

    return [corpusRow(match ($input['write']) {
        'assistant' => new AssistantMessage(
            $input['content'],
            corpusToolCalls($input['tool_calls']),
            $input['additional_content'],
            array_map(fn (array $request): ToolApprovalRequest => new ToolApprovalRequest($request['approval_id'], $request['tool_call_id']), $input['approval_requests']),
        ),
        'tool_result' => new ToolResultMessage(
            array_map(fn (array $result): ToolResult => new ToolResult(
                $result['tool_call_id'],
                $result['tool_name'],
                $result['args'],
                $result['result'],
                $result['tool_call_result_id'] ?? null,
            ), $input['results']),
            array_map(fn (array $decision): ToolApprovalResponse => new ToolApprovalResponse($decision['approval_id'], $decision['approved'], $decision['reason']), $input['decisions']),
        ),
    })];
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(threadRowsCorpus()['cases'])->toHaveCount(17);
});

it('stores and replays every row as the corpus records for the reference', function (array $case): void {
    expect(json_encode(corpusRows($case), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        ->toBe($case['rows']['php']);
})->with(fn (): array => array_map(fn (array $case): array => [$case], threadRowsCorpus()['cases']));

it('agrees with both ports on every row', function (): void {
    foreach (threadRowsCorpus()['cases'] as $case) {
        expect([$case['rows']['ts'], $case['rows']['py']])
            ->toBe([$case['rows']['php'], $case['rows']['php']], $case['id'])
            ->and($case['agrees'])->toBeTrue();
    }
});
