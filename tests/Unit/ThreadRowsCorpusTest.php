<?php

declare(strict_types=1);

use Prism\Harness\AgentRuntime;
use Prism\Harness\Models\Thread;
use Prism\Harness\Support\MessageMapper;
use Prism\Harness\Support\StepRows;
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
 * Rows AS STORED (0.5.1): each with its `type`, `harness: true` on a row the
 * harness wrote; a `run_id` on a corpus row is carried and read as nothing
 * (0.6.0: a run id is never a trust signal). Positions are 1-based.
 *
 * @param  list<array<string, mixed>>  $stored
 * @return list<array{position: int, type: string, run_id: ?string, harness: bool, payload: array<string, mixed>}>
 */
function corpusStoredRows(array $stored): array
{
    $rows = [];
    foreach ($stored as $index => $row) {
        $type = $row['type'];
        $harness = ($row['harness'] ?? false) === true;
        $runId = is_string($row['run_id'] ?? null) ? $row['run_id'] : null;
        unset($row['type'], $row['harness'], $row['run_id']);
        $rows[] = ['position' => $index + 1, 'type' => $type, 'run_id' => $runId, 'harness' => $harness, 'payload' => $row];
    }

    return $rows;
}

/**
 * @param  array<string, mixed>  $case
 * @return list<array<string, mixed>>
 */
function corpusRows(array $case): array
{
    if (($case['read'] ?? null) === 'call_outcomes') {
        // Whether each call of the step at `step` has an outcome (0.6.0,
        // G-387 round 16): the reader rule, one boolean per call.
        return Thread::callOutcomesIn(corpusStoredRows($case['stored']), $case['step']);
    }
    if (($case['read'] ?? null) === 'approval_reads') {
        // What each approval reads (0.6.0): pending, and the decision that stands.
        return Thread::approvalReadsIn(corpusStoredRows($case['stored']), $case['approvals']);
    }
    if (($case['read'] ?? null) === 'approval_states') {
        // How each approval READS over rows as stored (0.4.0, G-387 round
        // 13): whether a runner start ended its call, by the harness's mark.
        return array_map(fn (string $id): array => ['approval_id' => $id, 'ended_by_start' => Thread::endedByStartIn($case['stored'], $id)],
            $case['approvals']);
    }
    if (isset($case['place'])) {
        // Rows as stored (0.5.1, G-387 round 15): placed as the replay
        // places them, then folded.
        $messages = array_map(static fn (array $row): Message => MessageMapper::fromArray($row['type'], $row['payload']),
            Thread::placedRows(corpusStoredRows($case['place'])));

        return array_map(corpusRow(...), iterator_to_array(ToolResultRuns::fold($messages), false));
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
    if ($input['write'] === 'answer') {
        // The row the runtime stores when it answers the step at `position`
        // (0.6.0, G-387 round 16): only the new results, the mark last.
        $payload = Thread::answerPayload(corpusStoredRows($input['stored']), $input['position'], new ToolResultMessage(
            array_map(fn (array $result): ToolResult => new ToolResult($result['tool_call_id'], $result['tool_name'], $result['args'],
                $result['result'], $result['tool_call_result_id'] ?? null), $input['results']),
            array_map(fn (array $decision): ToolApprovalResponse => new ToolApprovalResponse($decision['approval_id'], $decision['approved'], $decision['reason']), $input['decisions']),
        ));

        return $payload === null ? [] : [['type' => MessageMapper::TYPE_TOOL_RESULT, ...$payload]];
    }
    if ($input['write'] === 'started') {
        // The write-ahead started row (0.5.1, G-387 round 15).
        return [['type' => MessageMapper::TYPE_TOOL_RESULT, ...Thread::startedPayload($input['tool_call_id'], $input['tool_name'], $input['approval_id'], $input['position'])]];
    }
    if (($input['bind'] ?? false) === true) {
        // An assistant step as stored, each request bound to its call (0.5.1).
        $message = new AssistantMessage($input['content'], corpusToolCalls($input['tool_calls']), $input['additional_content'],
            array_map(fn (array $request): ToolApprovalRequest => new ToolApprovalRequest($request['approval_id'], $request['tool_call_id']), $input['approval_requests']));

        return [['type' => MessageMapper::TYPE_ASSISTANT, ...StepRows::withBoundRequests(MessageMapper::toArray($message))]];
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
    expect(threadRowsCorpus()['cases'])->toHaveCount(40);
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
