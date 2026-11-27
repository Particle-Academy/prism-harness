<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Prism\Harness\Models\Thread;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * A thread's stored rows, read once, with everything a reader asks of them
 * worked out in ONE pass (G-387 round 16, item 7): which rows are steps,
 * which step first asked for each approval, which step each result row
 * belongs to ({@see StepRows::owners()}), and which step is paused
 * ({@see PausedStep}). Every reader of a thread reads this, so no reader
 * scans the thread again, and none is quadratic in its length.
 *
 * Pure: built from rows as stored (`harness` is whether the runtime wrote
 * the row, {@see StepRows::isHarnessRow()}), so the cross-language corpora
 * drive it exactly as a stored thread is read.
 *
 * @internal
 */
final class ThreadView
{
    /** @var list<array<array-key, mixed>> each row's payload as every reader sees it ({@see StepRows::honouredPayload()}) */
    public readonly array $honoured;

    /** @var array<int, int> by stored position, the row's index */
    public readonly array $at;

    /** @var list<int> the indexes of the steps, oldest first */
    public readonly array $steps;

    /** @var array<string, int> by approval id, the index of the step that FIRST asked for it */
    public readonly array $firstAsker;

    /** @var array<int, int|null> by result row index, the index of the step it belongs to */
    public readonly array $owners;

    /** @var array<int, list<int>> by step index, the result rows that belong to it, oldest first (no journal row) */
    public readonly array $owned;

    /** The index of the paused step, or null. */
    public readonly ?int $paused;

    /**
     * @param  list<array{position: int, type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}>  $rows  oldest first
     */
    private function __construct(public readonly array $rows)
    {
        $honoured = [];
        $at = [];
        $steps = [];
        $firstAsker = [];
        foreach ($rows as $index => $row) {
            $honoured[$index] = StepRows::honouredPayload($row);
            $at[$row['position']] = $index;
            if (StepRows::isStep($row['type'], $row['payload'])) {
                $steps[] = $index;
                foreach (StepRows::requestsOf($row['payload']) as $request) {
                    $firstAsker[(string) $request['approval_id']] ??= $index;
                }
            }
        }
        $this->honoured = $honoured;
        $this->at = $at;
        $this->steps = $steps;
        $this->firstAsker = $firstAsker;
        $this->owners = StepRows::owners($rows);
        $owned = [];
        foreach ($this->owners as $index => $owner) {
            if ($owner !== null && $owner >= 0 && ! self::isJournal($honoured[$index])) {
                $owned[$owner][] = $index;
            }
        }
        $this->owned = $owned;
        $found = PausedStep::find(array_reverse(array_keys($rows)),
            static fn (int $i): string => PausedStep::kindOfRow($rows[$i]['type'], $rows[$i]['payload']));
        $this->paused = $found['step'] ?? null;
    }

    /**
     * @param  list<array{position: int, type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}>  $rows  oldest first
     */
    public static function of(array $rows): self
    {
        return new self($rows);
    }

    /**
     * The step at `$index` as every reader sees it: only the requests that
     * ask for something (never one whose approval id an EARLIER step issued,
     * round 15), each binding honoured only on a row the runtime wrote.
     *
     * @return array<array-key, mixed>
     */
    public function step(int $index): array
    {
        $payload = $this->honoured[$index];
        if (! is_array($payload['tool_approval_requests'] ?? null) || $payload['tool_approval_requests'] === []) {
            return $payload;
        }
        $payload['tool_approval_requests'] = array_values(array_filter($payload['tool_approval_requests'],
            fn (mixed $request): bool => ! (is_array($request) && is_scalar($request['approval_id'] ?? null)
                && ($this->firstAsker[(string) $request['approval_id']] ?? $index) < $index)));

        return $payload;
    }

    /**
     * The result payloads that belong to the step at `$index`, oldest first.
     * `$exceptStartedBy`: that run's started rows are left out.
     *
     * @return list<array<array-key, mixed>>
     */
    public function ownedPayloads(int $index, ?string $exceptStartedBy = null): array
    {
        $out = [];
        foreach ($this->owned[$index] ?? [] as $i) {
            if ($exceptStartedBy !== null && StepRows::isStarted($this->honoured[$i]) && ($this->rows[$i]['run_id'] ?? null) === $exceptStartedBy) {
                continue;
            }
            $out[] = $this->honoured[$i];
        }

        return $out;
    }

    /** The index of the step that first asked for `$approvalId`, or null. */
    public function askerOf(string $approvalId): ?int
    {
        return $this->firstAsker[$approvalId] ?? null;
    }

    /**
     * Whether an honoured payload is a standing journal row.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function isJournal(array $payload): bool
    {
        $journal = $payload[StepRows::JOURNAL] ?? null;

        return is_array($journal) && is_string($journal['tool_name'] ?? null);
    }

    /**
     * One stored step paired by position (G-387 round 13, {@see CallPairing}):
     * its calls, whether each has an outcome (a result of its own, or a
     * started row: answered in progress), whether each is interrupted
     * (started, no result of its own), the call each request was issued for,
     * and the decision that STANDS for each approval ({@see StepRows::standing()}).
     *
     * @param  array<array-key, mixed>  $assistant  the step's payload, as read ({@see self::step()})
     * @param  list<array<array-key, mixed>>  $results  its result payloads, oldest first (honoured)
     * @return array{calls: list<mixed>, answered: list<bool>, interrupted: list<bool>, requests: list<array{request: array<array-key, mixed>, call: int|null}>, standing: array<string, array<array-key, mixed>|null>}
     */
    public static function pair(array $assistant, array $results): array
    {
        $stepCalls = StepRows::objectCalls($assistant['tool_calls'] ?? null) ?? [];
        $calls = CallPairing::ofStored($stepCalls, 'id', 'name');
        $rows = [];
        // The calls that ran at once: results before the first decision, and
        // never a row the harness wrote to END calls (its replaced mark, the
        // 0.26.x replaced mark or a start's ended mark, round 14): the calls
        // such a row answers never ran.
        $ranRows = [];
        $ran = null;
        $started = [];
        $timeline = [];
        foreach ($results as $payload) {
            $responses = is_array($payload['tool_approval_responses'] ?? null) ? array_values($payload['tool_approval_responses']) : [];
            if (StepRows::isStarted($payload)) {
                $started[] = [$payload[StepRows::STARTED]['tool_call_id'], $payload[StepRows::STARTED]['tool_name']];
                $timeline[] = ['results' => [], 'started' => [end($started)], 'responses' => $responses];

                continue;
            }
            if ($ran === null && $responses !== []) {
                $ran = CallPairing::answered($calls, $ranRows);
            }
            $row = CallPairing::ofStored($payload['tool_results'] ?? [], 'tool_call_id', 'tool_name');
            $rows[] = $row;
            $timeline[] = ['results' => $row, 'started' => [], 'responses' => $responses];
            if (! self::isMarkedRow($payload)) {
                $ranRows[] = $row;
            }
        }
        $answered = CallPairing::answered($calls, $rows);
        // Each started row names one call (id and tool): the first such call
        // with no outcome. Answered in progress; interrupted while it has no
        // result of its own.
        $interrupted = array_fill(0, count($calls), false);
        foreach ($started as [$id, $name]) {
            foreach ($calls as $i => $call) {
                if (! $answered[$i] && ! $interrupted[$i] && $call[0] === $id && $call[1] === $name) {
                    $interrupted[$i] = true;

                    break;
                }
            }
        }
        $answered = array_map(static fn (bool $a, bool $b): bool => $a || $b, $answered, $interrupted);
        $ran ??= CallPairing::answered($calls, $ranRows);
        $requests = array_values(array_filter(is_array($assistant['tool_approval_requests'] ?? null) ? $assistant['tool_approval_requests'] : [], 'is_array'));
        $paired = CallPairing::requests($calls, array_map(
            static fn (array $request): string => is_scalar($request['tool_call_id'] ?? null) ? (string) $request['tool_call_id'] : '', $requests), $ran);
        $pairs = [];
        foreach ($requests as $k => $request) {
            if (is_string($request['approval_id'] ?? null)) {
                $pairs[$request['approval_id']] ??= $paired[$k];
            }
        }

        return [
            'calls' => $stepCalls,
            'answered' => $answered,
            'interrupted' => $interrupted,
            'requests' => array_map(static fn (array $request, ?int $call): array => ['request' => $request, 'call' => $call], $requests, $paired),
            'standing' => StepRows::standing($calls, $pairs, $timeline),
        ];
    }

    /**
     * Whether a result row carries one of the harness's own marks that END
     * calls (honoured).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function isMarkedRow(array $payload): bool
    {
        return array_key_exists(Thread::SUPERSEDED, $payload) || array_key_exists('harness_replaced', $payload)
            || array_key_exists(Thread::ENDED_BY_START, $payload) || StepRows::isStarted($payload);
    }

    /**
     * The rows in replay order: what a provider is sent, before the fold
     * (G-387 rounds 15 and 16):
     *
     *  - a started row is never sent; a harness result row whose mark names
     *    an earlier step is placed with that step's own result rows;
     *  - every call of a step that is no longer paused and has no outcome
     *    gets one: `replaced` when a request of the step was issued for it
     *    (an earlier version replaced it without one), else `interrupted`
     *    (it was not gated, so it ran, or may have, when the step was taken);
     *    a started call with no result is `interrupted` wherever it is;
     *  - a journal row still standing (an ungated call that ran inside a
     *    model step whose rows were never stored) is sent as that call,
     *    answered `interrupted`, after the segment it sits in;
     *  - every decision is the one that STOOD for its approval when its call
     *    was resolved ({@see StepRows::standing()}); one that never stood is
     *    not sent.
     *
     * @return list<array{type: string, payload: array<array-key, mixed>}>
     */
    public function placed(): array
    {
        $rows = $this->rows;
        $segment = null;
        $segmentOf = [];
        $ends = [];
        $last = null;
        foreach ($rows as $index => $row) {
            if (StepRows::isStep($row['type'], $row['payload'])) {
                $segment = $index;
                $last = $index;
                $ends[$index] = $index;

                continue;
            }
            $segmentOf[$index] = $segment;
            if ($last !== null && ($row['type'] === MessageMapper::TYPE_TOOL_RESULT || $row['type'] === MessageMapper::TYPE_SYSTEM)) {
                $ends[$last] = $index;
            } else {
                $last = null;
            }
        }
        // Each step's moved rows (a harness mark placing them with it).
        $moved = [];
        foreach ($this->owned as $owner => $indexes) {
            foreach ($indexes as $index) {
                if ($owner !== ($segmentOf[$index] ?? null) && ! StepRows::isStarted($this->honoured[$index])) {
                    $moved[$owner][] = $index;
                }
            }
        }
        $extras = [];
        $standing = [];
        foreach ($this->steps as $step) {
            $paired = self::pair($this->step($step), $this->ownedPayloads($step));
            foreach ($paired['standing'] as $id => $response) {
                if (($this->firstAsker[$id] ?? null) === $step) {
                    $standing[$id] = $response;
                }
            }
            $requested = [];
            foreach ($paired['requests'] as ['call' => $at]) {
                if ($at !== null) {
                    $requested[$at] = true;
                }
            }
            $answers = [];
            foreach ($paired['calls'] as $at => $call) {
                if ($paired['interrupted'][$at]) {
                    $answers[$at] = self::endedResult($call, StepRows::INTERRUPTED_RESULT);
                } elseif ($step !== $this->paused && ! $paired['answered'][$at]) {
                    $answers[$at] = self::endedResult($call, isset($requested[$at]) ? Thread::SUPERSEDED_RESULT : StepRows::INTERRUPTED_RESULT);
                }
            }
            ksort($answers);
            $extra = array_map(static fn (int $i): array => ['type' => $rows[$i]['type'], 'payload' => $rows[$i]['payload']], $moved[$step] ?? []);
            if ($answers !== []) {
                $extra[] = ['type' => MessageMapper::TYPE_TOOL_RESULT, 'payload' => self::asStored(new ToolResultMessage(array_values($answers)))];
            }
            if ($extra !== []) {
                $extras[$ends[$step] ?? $step][] = $extra;
            }
        }
        // A standing journal row: the call it names, answered interrupted,
        // after the segment it sits in.
        foreach ($rows as $index => $row) {
            if ($row['type'] !== MessageMapper::TYPE_TOOL_RESULT || ! self::isJournal($this->honoured[$index])) {
                continue;
            }
            $journal = $this->honoured[$index][StepRows::JOURNAL];
            $call = ['id' => 'harness_journal_'.$row['position'], 'name' => $journal['tool_name'],
                'arguments' => is_array($journal['args'] ?? null) ? $journal['args'] : [], 'result_id' => null];
            $owner = $segmentOf[$index] ?? null;
            $extras[$owner === null ? $index : ($ends[$owner] ?? $index)][] = [
                ['type' => MessageMapper::TYPE_ASSISTANT, 'payload' => ['content' => '', 'tool_calls' => [[...$call, 'reasoning_id' => null, 'reasoning_summary' => null]],
                    'additional_content' => [], 'tool_approval_requests' => []]],
                ['type' => MessageMapper::TYPE_TOOL_RESULT, 'payload' => self::asStored(new ToolResultMessage([self::endedResult($call, StepRows::INTERRUPTED_RESULT)]))],
            ];
        }
        // An approval no step asked for: a denial over an approval, as before.
        $legacy = [];
        foreach ($rows as $index => $row) {
            if ($row['type'] !== MessageMapper::TYPE_TOOL_RESULT) {
                continue;
            }
            foreach (is_array($row['payload']['tool_approval_responses'] ?? null) ? $row['payload']['tool_approval_responses'] : [] as $response) {
                if (is_array($response) && is_string($response['approval_id'] ?? null) && ! isset($this->firstAsker[$response['approval_id']])) {
                    $current = $legacy[$response['approval_id']] ?? null;
                    if ($current === null || (($current['approved'] ?? false) === true && ($response['approved'] ?? false) !== true)) {
                        $legacy[$response['approval_id']] = $response;
                    }
                }
            }
        }
        $out = [];
        foreach ($rows as $index => $row) {
            $payload = $this->honoured[$index];
            $owner = $this->owners[$index] ?? null;
            $skip = $row['type'] === MessageMapper::TYPE_TOOL_RESULT
                && (StepRows::isStarted($payload) || self::isJournal($payload) || ($owner !== null && $owner >= 0 && $owner !== ($segmentOf[$index] ?? null)));
            if (! $skip) {
                $out[] = ['type' => $row['type'], 'payload' => $this->sent($index, $row, $standing, $legacy)];
            }
            foreach ($extras[$index] ?? [] as $extra) {
                foreach ($extra as $placed) {
                    $out[] = ['type' => $placed['type'], 'payload' => self::withStanding($placed, $standing, $legacy)];
                }
            }
        }

        return $out;
    }

    /**
     * A row as it is sent: an assistant row's requests only when it is a step
     * and they are its own; a result row's decisions, each the one that stands.
     *
     * @param  array{type: string, payload: array<array-key, mixed>}  $row
     * @param  array<string, array<array-key, mixed>|null>  $standing
     * @param  array<string, array<array-key, mixed>>  $legacy
     * @return array<array-key, mixed>
     */
    private function sent(int $index, array $row, array $standing, array $legacy): array
    {
        $payload = $row['payload'];
        if ($row['type'] === MessageMapper::TYPE_ASSISTANT) {
            // Only a step asks for anything (G-408), and only for what it
            // asked first (round 15).
            $payload['tool_approval_requests'] = StepRows::isStep($row['type'], $payload)
                ? ($this->step($index)['tool_approval_requests'] ?? []) : [];

            return $payload;
        }

        return self::withStanding(['type' => $row['type'], 'payload' => $payload], $standing, $legacy);
    }

    /**
     * @param  array{type: string, payload: array<array-key, mixed>}  $row
     * @param  array<string, array<array-key, mixed>|null>  $standing
     * @param  array<string, array<array-key, mixed>>  $legacy
     * @return array<array-key, mixed>
     */
    private static function withStanding(array $row, array $standing, array $legacy): array
    {
        $payload = $row['payload'];
        if ($row['type'] !== MessageMapper::TYPE_TOOL_RESULT || ! is_array($payload['tool_approval_responses'] ?? null)) {
            return $payload;
        }
        $responses = [];
        foreach ($payload['tool_approval_responses'] as $response) {
            $id = is_array($response) && is_string($response['approval_id'] ?? null) ? $response['approval_id'] : null;
            if ($id === null) {
                $responses[] = $response;
            } elseif (array_key_exists($id, $standing)) {
                // The decision that stood; one that never stood is not sent.
                if ($standing[$id] !== null) {
                    $responses[] = $standing[$id];
                }
            } elseif (isset($legacy[$id])) {
                $responses[] = $legacy[$id];
            }
            // An approval a step asked for, decided only outside that
            // step's rows: not a decision on it, not sent.
        }
        $payload['tool_approval_responses'] = $responses;

        return $payload;
    }

    /**
     * The result a call that never ran on its own is read as: interrupted
     * (it was started, or ran ungated, and may have run) or replaced.
     *
     * @param  mixed  $call  the stored call
     */
    public static function endedResult(mixed $call, string $text): ToolResult
    {
        $call = is_array($call) ? $call : [];
        $arguments = $call['arguments'] ?? [];
        $arguments = is_string($arguments) ? (json_decode($arguments, true) ?? []) : $arguments;

        return new ToolResult(
            toolCallId: is_scalar($call['id'] ?? null) ? (string) $call['id'] : '',
            toolName: is_string($call['name'] ?? null) ? $call['name'] : '',
            args: is_array($arguments) ? $arguments : [],
            result: $text,
            toolCallResultId: is_string($call['result_id'] ?? null) ? $call['result_id'] : null,
        );
    }

    /**
     * A message as a stored row reads (decoded JSON), so its arguments survive the mapper.
     *
     * @return array<array-key, mixed>
     */
    private static function asStored(ToolResultMessage $message): array
    {
        return json_decode((string) json_encode(MessageMapper::toArray($message), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
