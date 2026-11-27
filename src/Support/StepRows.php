<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Prism\Harness\Tools\CanonicalJson;

/**
 * Which step each stored result row belongs to, by ONE rule every reader
 * uses (G-387 round 15): the paused-step readers, the answered lookup, the
 * replaced check, the approval status and the replay.
 *
 * A step is an assistant row whose `tool_calls` is a LIST holding at least
 * one OBJECT, and its calls are those objects (G-408, the rule prism-harness-ts
 * 0.11.x #92 ships). Anything else there (a JSON string, a number, null, a
 * list of strings or of JSON-string calls) is not a step for any reader: it
 * is read, and replayed, as an assistant text row. Non-object entries of a
 * list are never calls.
 *
 * An approval belongs ONLY to the step that FIRST asked for it: a later step
 * whose request repeats an approval id an earlier step issued asks for
 * nothing (a copy of a step that keeps its request never lends the earlier
 * approval to a call nobody approved).
 *
 * A result row belongs to the step whose SEGMENT holds it (the rows after
 * that step and before the next one), unless the HARNESS wrote it with a
 * `harness_answers_step {position}` mark naming an earlier step that has a
 * call with the same id and tool name as every result in the row (or as the
 * call a started row names): then it belongs to that step, wherever it
 * landed. A mark on any other row, or naming anything else, changes nothing:
 * segment ownership applies, which fails closed.
 *
 * ONE TRUST SIGNAL (round 16): a row is the harness's own only when the
 * RUNTIME wrote it, under its private single-use token: the `harness_written`
 * column of a stored row (no public write can set it: it is not
 * mass-assignable and Thread::record() never sets it), the `harness` flag of
 * a corpus row. A run id is NEVER a trust signal. Every mark (see
 * {@see self::MARKS}) on any other row is read as ABSENT, by every reader: a
 * forged mark is exactly as if it were not there.
 *
 * @internal
 */
final class StepRows
{
    /** The mark on every result row the harness writes: the step it answers. */
    public const ANSWERS_STEP = 'harness_answers_step';

    /** The write-ahead row the harness records before it runs an approved call. */
    public const STARTED = 'harness_started';

    /** The binding each stored approval request carries: the call the person was shown. */
    public const CALL_BINDING = 'harness_call';

    /** What a started call that never got a result reads as (never "not run"). */
    public const INTERRUPTED_RESULT = 'Tool call was interrupted, it may have run';

    /**
     * The write-ahead journal row (round 16, PHP only): recorded before an
     * UNGATED call runs inside a model step, whose step is not stored yet,
     * naming the call's tool and arguments; removed by the write that stores
     * the call's result. One still standing is a call that ran, or may have,
     * with no stored outcome: the replay answers it as interrupted.
     */
    public const JOURNAL = 'harness_journal';

    /**
     * Every key only the harness writes on a row: honoured only on a row the
     * runtime wrote ({@see self::isHarnessRow()}), read as absent on any
     * other. `harness_call` is a request's binding, inside each request.
     */
    public const MARKS = [self::ANSWERS_STEP, self::STARTED, self::JOURNAL, 'harness_ended_by_start', 'harness_superseded',
        'harness_replaced', 'harness_steps_used', 'harness_steps_left',
        // Run state a parked step carries (round 17, item 2): the tools its
        // run offered. Read only on the runtime's own step; a host step
        // resumes with the legacy scope (its approved requests' tools).
        'harness_tool_names'];

    /**
     * The calls of an assistant row: the OBJECT entries of its `tool_calls`
     * list, or null when there is none (the row is not a step, G-408).
     *
     * @return list<array<array-key, mixed>>|null
     */
    public static function objectCalls(mixed $calls): ?array
    {
        if (! is_array($calls) || $calls === [] || ! array_is_list($calls)) {
            return null;
        }
        $objects = array_values(array_filter($calls, 'is_array'));

        /** @var list<array<array-key, mixed>> $objects */
        return $objects === [] ? null : $objects;
    }

    /**
     * `$payload` (an assistant step's) with every approval request bound to
     * the call it was issued for (round 15): `harness_call {tool_name,
     * args_digest}` as the request's LAST key, the digest being
     * CanonicalJson::digest of the call's arguments (`sha256:` + the hex
     * sha256 of their canonical JSON). The call is the request's own, paired
     * by position. A request with no call of its own is bound to nothing.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function withBoundRequests(array $payload): array
    {
        $calls = self::objectCalls($payload['tool_calls'] ?? null);
        $requests = $payload['tool_approval_requests'] ?? null;
        if ($calls === null || ! is_array($requests) || $requests === []) {
            return $payload;
        }
        $paired = CallPairing::requests(CallPairing::ofStored($calls, 'id', 'name'), array_map(
            static fn (mixed $request): string => is_array($request) && is_scalar($request['tool_call_id'] ?? null) ? (string) $request['tool_call_id'] : '',
            array_values($requests)));
        foreach (array_values($requests) as $k => $request) {
            $call = $paired[$k] === null ? null : $calls[$paired[$k]];
            if (is_array($request) && is_array($call)) {
                $requests[$k] = [...$request, self::CALL_BINDING => [
                    'tool_name' => is_string($call['name'] ?? null) ? $call['name'] : '',
                    'args_digest' => self::argsDigest($call['arguments'] ?? []),
                ]];
            }
        }
        $payload['tool_approval_requests'] = array_values($requests);

        return $payload;
    }

    /** The digest a binding holds for a call's arguments (a JSON string decoded first). */
    public static function argsDigest(mixed $arguments): string
    {
        // As stored: a JSON string is decoded, and an object (a message's
        // arguments map, before it is stored) read as the map it encodes.
        $arguments = is_string($arguments) ? json_decode($arguments, true)
            : (is_object($arguments) ? json_decode((string) json_encode($arguments), true) : $arguments);

        return CanonicalJson::digest(is_array($arguments) ? $arguments : []);
    }

    /**
     * Whether a request may decide this call: its binding names the call's
     * tool and the digest of its arguments. A request stored before bindings
     * were kept has none (and a binding on a row the runtime did not write is
     * read as absent), and binds to its own step's call, paired by position
     * (the documented upgrade: the request is its FIRST asker's, so that call
     * is the one the person was shown).
     *
     * @param  array<array-key, mixed>  $request
     * @param  array<array-key, mixed>  $call
     */
    public static function binds(array $request, array $call): bool
    {
        if (! array_key_exists(self::CALL_BINDING, $request)) {
            return true;
        }
        $binding = $request[self::CALL_BINDING];

        return is_array($binding) && ($binding['tool_name'] ?? null) === ($call['name'] ?? null)
            && ($binding['args_digest'] ?? null) === self::argsDigest($call['arguments'] ?? []);
    }

    /**
     * The approval requests of the step in `$rows` at `$index` that ask for
     * something: never one whose approval id an EARLIER step already issued.
     *
     * @param  list<array{type: string, payload: array<array-key, mixed>}>  $rows
     * @return list<array<array-key, mixed>>
     */
    public static function ownRequests(array $rows, int $index): array
    {
        $earlier = [];
        for ($i = 0; $i < $index; $i++) {
            if (self::isStep($rows[$i]['type'], $rows[$i]['payload'])) {
                foreach (self::requestsOf($rows[$i]['payload']) as $request) {
                    $earlier[(string) $request['approval_id']] = true;
                }
            }
        }

        return array_values(array_filter(self::requestsOf($rows[$index]['payload']),
            static fn (array $request): bool => ! isset($earlier[(string) $request['approval_id']])));
    }

    /**
     * A step's approval requests that name an approval id.
     *
     * @param  array<array-key, mixed>  $payload
     * @return list<array<array-key, mixed>>
     */
    public static function requestsOf(array $payload): array
    {
        $requests = $payload['tool_approval_requests'] ?? [];

        return array_values(array_filter(is_array($requests) ? $requests : [],
            static fn (mixed $request): bool => is_array($request) && is_scalar($request['approval_id'] ?? null)));
    }

    /** Whether a stored row is a tool-call step. */
    public static function isStep(string $type, mixed $payload): bool
    {
        return $type === MessageMapper::TYPE_ASSISTANT && is_array($payload) && self::objectCalls($payload['tool_calls'] ?? null) !== null;
    }

    /**
     * Whether a row is the harness's own (see the class note).
     *
     * @param  array{type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}  $row
     */
    public static function isHarnessRow(array $row): bool
    {
        return ($row['harness'] ?? false) === true;
    }

    /**
     * The payload as every reader sees it: a host row's harness keys are
     * dropped, so a forged mark is read exactly as if it were absent.
     *
     * @param  array{type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}  $row
     * @return array<array-key, mixed>
     */
    public static function honouredPayload(array $row): array
    {
        $payload = $row['payload'];
        if (self::isHarnessRow($row)) {
            return $payload;
        }
        foreach (self::MARKS as $mark) {
            unset($payload[$mark]);
        }
        if (is_array($payload['tool_approval_requests'] ?? null)) {
            foreach ($payload['tool_approval_requests'] as $k => $request) {
                if (is_array($request)) {
                    unset($payload['tool_approval_requests'][$k][self::CALL_BINDING]);
                }
            }
        }

        return $payload;
    }

    /**
     * Whether an honoured payload is a started row.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function isStarted(array $payload): bool
    {
        $started = $payload[self::STARTED] ?? null;

        return is_array($started) && is_string($started['tool_call_id'] ?? null) && is_string($started['tool_name'] ?? null);
    }

    /**
     * The position a harness row's mark names, if it names one.
     *
     * @param  array<array-key, mixed>  $payload  honoured
     */
    public static function markedPosition(array $payload): ?int
    {
        $mark = $payload[self::ANSWERS_STEP] ?? null;
        $position = is_array($mark) ? ($mark['position'] ?? null) : null;

        return is_int($position) ? $position : null;
    }

    /**
     * Whether a mark naming `$step` is valid for this row: every result (or
     * the started call) has a call of that step with the same id and name.
     *
     * @param  array<array-key, mixed>  $payload  honoured
     * @param  array<array-key, mixed>  $step  the named step's payload
     */
    public static function fits(array $payload, array $step): bool
    {
        $calls = self::objectCalls($step['tool_calls'] ?? null);
        if ($calls === null) {
            return false;
        }
        $names = [];
        foreach ($calls as $call) {
            $names[self::key($call['id'] ?? null, $call['name'] ?? null)] = true;
        }
        $entries = self::isStarted($payload) ? [[$payload[self::STARTED]['tool_call_id'], $payload[self::STARTED]['tool_name']]] : [];
        foreach (is_array($payload['tool_results'] ?? null) ? $payload['tool_results'] : [] as $result) {
            $entries[] = is_array($result) ? [$result['tool_call_id'] ?? null, $result['tool_name'] ?? null] : [null, null];
        }
        if ($entries === []) {
            return false;
        }
        foreach ($entries as [$id, $name]) {
            if (! isset($names[self::key($id, $name)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The owning step of every result row in `$rows` (oldest first, each
     * with its 1-based `position`), by row index: the index of its step in
     * `$rows`, or null (no step before it). Rows that are not results map to
     * nothing. Pure: the corpora drive it.
     *
     * A window that starts after the thread's first row passes the steps
     * before it that marks in the window name (`$earlier`, by position): a
     * row owned by one of those maps to -1 (a step before the window).
     *
     * @param  list<array{position: int, type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}>  $rows
     * @param  array<int, array<array-key, mixed>>  $earlier  step payloads before the window, by position
     * @return array<int, int|null>
     */
    public static function owners(array $rows, array $earlier = []): array
    {
        $owners = [];
        $segment = null;
        $steps = [];
        foreach ($rows as $index => $row) {
            if (self::isStep($row['type'], $row['payload'])) {
                $segment = $index;
                $steps[$row['position']] = $index;

                continue;
            }
            if ($row['type'] !== MessageMapper::TYPE_TOOL_RESULT) {
                continue;
            }
            $payload = self::honouredPayload($row);
            $named = self::markedPosition($payload);
            $owner = $segment;
            if ($named !== null && $named < $row['position'] && isset($steps[$named])
                && self::fits($payload, $rows[$steps[$named]]['payload'])) {
                $owner = $steps[$named];
            } elseif ($named !== null && ! isset($steps[$named]) && isset($earlier[$named])
                && self::isStep(MessageMapper::TYPE_ASSISTANT, $earlier[$named]) && self::fits($payload, $earlier[$named])) {
                $owner = -1;
            }
            $owners[$index] = $owner;
        }

        return $owners;
    }

    /**
     * The decision that STANDS for each approval decided in one step's rows
     * (G-387 round 16, items 1 and 10; G-401, decided: DENIAL WINS), by ONE
     * rule every reader, the start gate and the replay use:
     *
     *  - a decision COUNTS only while its call has no outcome: no result and
     *    no started row among the step's rows BEFORE the row that carries it
     *    (a decision in the same row as the call's result counts). One
     *    recorded after the call started, ran or was answered never counts,
     *    whoever wrote it;
     *  - of the decisions that count, a DENIAL stands over an approval,
     *    before or after it; else the first approval stands;
     *  - an approval no request of the step pairs with a call counts always.
     *
     * Each decided id maps to the standing decision as stored, or to null
     * when decisions were recorded for it and none counted (the call's
     * outcome came first; nothing a person decided stood for it).
     *
     * @param  list<array{0: string, 1: string, 2?: ?string}>  $calls  the step's calls ({@see CallPairing::ofStored()})
     * @param  array<string, int|null>  $pairs  approval id => the index of the call its request was issued for
     * @param  list<array{results: list<array{0: string, 1: string, 2?: ?string}>, started: list<array{0: string, 1: string}>, responses: list<mixed>}>  $rows  oldest first
     * @return array<string, array<array-key, mixed>|null>
     */
    public static function standing(array $calls, array $pairs, array $rows): array
    {
        $standing = [];
        $seen = [];
        $resultRows = [];
        $started = [];
        foreach ($rows as $row) {
            $answered = self::outcomes($calls, $resultRows, $started);
            foreach ($row['responses'] as $response) {
                if (! is_array($response) || ! is_string($response['approval_id'] ?? null)) {
                    continue;
                }
                $id = $response['approval_id'];
                $seen[$id] = true;
                $at = $pairs[$id] ?? null;
                if ($at !== null && ($answered[$at] ?? false)) {
                    continue;
                }
                $current = $standing[$id] ?? null;
                if ($current === null || (($current['approved'] ?? false) === true && ($response['approved'] ?? false) !== true)) {
                    $standing[$id] = $response;
                }
            }
            if ($row['results'] !== []) {
                $resultRows[] = $row['results'];
            }
            foreach ($row['started'] as $call) {
                $started[] = $call;
            }
        }
        foreach (array_keys($seen) as $id) {
            $standing[$id] ??= null;
        }

        return $standing;
    }

    /**
     * Which calls have an outcome: a result of their own, or a started row
     * (the first such call with no outcome of its id and tool).
     *
     * @param  list<array{0: string, 1: string, 2?: ?string}>  $calls
     * @param  list<list<array{0: string, 1: string, 2?: ?string}>>  $resultRows
     * @param  list<array{0: string, 1: string}>  $started
     * @return list<bool>
     */
    public static function outcomes(array $calls, array $resultRows, array $started): array
    {
        $answered = CallPairing::answered($calls, $resultRows);
        foreach ($started as [$id, $name]) {
            foreach ($calls as $i => $call) {
                if (! $answered[$i] && $call[0] === $id && $call[1] === $name) {
                    $answered[$i] = true;

                    break;
                }
            }
        }

        return $answered;
    }

    private static function key(mixed $id, mixed $name): string
    {
        $id = is_scalar($id) ? (string) $id : '';
        $name = is_string($name) ? $name : '';

        return strlen($id).':'.$id.'|'.$name;
    }
}
