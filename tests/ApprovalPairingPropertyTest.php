<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentResponse;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Plans\Plans;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\StructuredAgentResponse;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Participant;
use Tests\Support\G360;

// G-392 QC: property tests for the approval pairing invariants, ported from
// prism-harness-ts test/approval-pairing-property.test.ts (1e4ca65): the
// same generator (mulberry32, the same seeds give the same cases), the same
// oracle and the same invariants, and the three seeds that failed on TS main
// as fixed regression cases.
//
// Each example is a generated model step (1-6 tool calls, ids from a small
// pool so repeats and empty ids are common, gated and ungated tools), entered
// where a real caller enters: Session::send / sendStructured / streamSteps /
// streamDecision over Prism's real OpenAI handler on faked HTTP with stray
// requests refused. A second source writes the step as stored rows, as
// another writer could hold them: requests shuffled, missing or repeated,
// prior results present. Then one or more resumes, with later decisions in
// between.
//
// The oracle is a small independent model of the rule, never a call into
// the code under test:
//   a step whose call ids repeat (empty ids count), or whose approval requests
//   repeat a tool_call_id, runs none of its gated calls and gives each the
//   repeated-id denial; otherwise a gated call runs only if its OWN request
//   was approved; no decision is a denial; a call with a result never runs;
//   every gated call gets exactly ONE recorded outcome, a result belongs to
//   the call at its position, a request pairs only with a gated call, and an
//   ungated call never receives an approval or a repeated-id denial.
//
// The PHP mapping of the TS recorders: `recordApproval` records each decision
// on its own and `recordDecisions` records them together, both through the
// path every public decision takes (Session::recordDecisions), then one
// resume; `streamDecision` is Session::streamDecision. On a step whose ids
// repeat PHP RECORDS every decision as a denial (G-392), so its
// decision_recorded steps say `denied` there.
//
// Deterministic: a fixed base seed. PRISM_PROPERTY_EXAMPLES sets the count,
// PRISM_PROPERTY_BASE_SEED the base, PRISM_PROPERTY_SEED replays one example.
// A failure prints the seed, the shrunk case and the full case.

const PP_REPEATED = 'Tool call was not run because another call in this step has the same id';
const PP_NO_RESPONSE = 'No approval response provided';
const PP_DENIED = 'User denied tool execution';
const PP_SUPERSEDED = 'Tool call was not run because a later step replaced it';
const PP_ID_POOL = ['a', 'b', 'c', ''];
const PP_PATHS = ['send', 'structured', 'streamSteps'];

/** The seeds that broke the rule on prism-harness-ts main 63ccf38 (BRIEF-R13). */
const PP_REGRESSION_SEEDS = [2034523272, 232174035, 2060958372];

/** The fixed-seed set normal CI runs; the coverage check is asserted on exactly these seeds. */
const PP_DEFAULT_EXAMPLES = 300;

/**
 * A whole number read from the environment, or `$fallback` when the variable
 * is NOT SET. A value that is set and is not a whole number in range fails:
 * a run never explores a different count, or seed, than the one asked for.
 */
function ppPositive(string|false $value, int $fallback, int $min = 0, int $max = PHP_INT_MAX): int
{
    if ($value === false) {
        return $fallback;
    }
    if (preg_match('/\A(0|[1-9]\d{0,17})\z/', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
        throw new InvalidArgumentException("Property test setting '{$value}' must be a whole number from {$min} to {$max}.");
    }

    return (int) $value;
}

// ---------------------------------------------------------------------------
// Seeded generator (mulberry32), bit for bit the TS one: uint32 arithmetic.
// ---------------------------------------------------------------------------

function ppU32(int $value): int
{
    return $value & 0xFFFFFFFF;
}

/** Math.imul, as an unsigned 32-bit pattern. */
function ppImul(int $a, int $b): int
{
    $a = ppU32($a);
    $b = ppU32($b);
    $low = ($a & 0xFFFF) * $b;
    $high = (($a >> 16) * $b) & 0xFFFF;

    return ppU32($low + ($high << 16));
}

final class PpRng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ppU32($seed);
    }

    public function next(): float
    {
        $this->state = ppU32($this->state + 0x6D2B79F5);
        $t = $this->state;
        $t = ppImul($t ^ ($t >> 15), $t | 1);
        $t = ppU32($t ^ ppU32($t + ppImul($t ^ ($t >> 7), $t | 61)));

        return ppU32($t ^ ($t >> 14)) / 4294967296;
    }

    public function int(int $lo, int $hi): int
    {
        return $lo + (int) floor($this->next() * ($hi - $lo + 1));
    }

    public function chance(float $p): bool
    {
        return $this->next() < $p;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[(int) floor($this->next() * count($items))];
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = (int) floor($this->next() * ($i + 1));
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}

function ppCaseSeed(int $base, int $index): int
{
    $h = ppU32($base ^ ppImul($index + 1, 0x9E3779B1));
    $h = ppImul($h ^ ($h >> 16), 0x85EBCA6B);
    $h = ppImul($h ^ ($h >> 13), 0xC2B2AE35);

    return ppU32($h ^ ($h >> 16));
}

// ---------------------------------------------------------------------------
// The generated case (the TS `Case`, as an array).
// ---------------------------------------------------------------------------

/** @return array<string, mixed> */
function ppGenerate(int $seed): array
{
    $rng = new PpRng($seed);
    $source = $rng->chance(0.6) ? 'model' : 'stored';
    $count = $rng->int(1, 6);
    $calls = [];
    for ($i = 0; $i < $count; $i++) {
        $calls[] = ['id' => $rng->pick(PP_ID_POOL), 'tool' => $rng->chance(0.7) ? 'echo' : 'free', 'token' => 100 + $i];
    }
    if (! in_array('echo', array_column($calls, 'tool'), true)) {
        $calls[$rng->int(0, $count - 1)]['tool'] = 'echo';
    }

    $verdict = function () use ($rng): array {
        $v = $rng->pick(['approve', 'approve', 'deny', 'none']);

        return ['call' => -1, 'verdict' => $v, 'reason' => $v === 'deny' && $rng->chance(0.5) ? 'no-'.$rng->int(0, 999) : null];
    };
    $gated = array_keys(array_filter($calls, fn (array $call): bool => $call['tool'] === 'echo'));
    $prior = [];
    if ($source === 'model') {
        $requests = array_map(fn (int $call): array => [...$verdict(), 'call' => $call], $gated);
    } else {
        // As another writer could hold them: some missing, some repeated, any order.
        $requests = [];
        foreach ($gated as $call) {
            if ($rng->chance(0.15)) {
                continue;
            }
            $requests[] = [...$verdict(), 'call' => $call];
            if ($rng->chance(0.15)) {
                $requests[] = [...$verdict(), 'call' => $call];
            }
        }
        $requests = $rng->shuffle($requests);
        // An ungated call ran when its step was taken, so it has its result. A
        // gated call may already have one only where a result can say whose
        // it is: not in a step whose call ids repeat.
        $distinct = count(array_unique(array_column($calls, 'id'))) === count($calls);
        foreach ($calls as $index => $call) {
            if ($call['tool'] === 'free' || ($distinct && $rng->chance(0.25))) {
                $prior[] = $index;
            }
        }
    }

    $again = [];
    for ($i = $rng->int(0, 2); $i > 0; $i--) {
        $again[] = ['approveAll' => $rng->chance(0.7), 'path' => $rng->pick(PP_PATHS)];
    }

    $case = [
        'seed' => $seed,
        'source' => $source,
        'calls' => $calls,
        'requests' => $requests,
        'prior' => $prior,
        'order' => $rng->shuffle(array_keys($requests)),
        'initial' => $rng->pick(PP_PATHS),
        'recorder' => $rng->pick(['recordApproval', 'recordDecisions', 'streamDecision']),
        'resume' => $rng->pick(PP_PATHS),
        'again' => $again,
    ];
    // The release lines' shapes (G-397, G-399), each from a stream of its
    // own, so every field above is still the 1e4ca65 TS case for this seed.
    if ($source === 'stored') {
        // Sometimes a request repeats an earlier request's APPROVAL id.
        // Kept beside the requests (index => the earlier request whose id it
        // carries), so the requests stay the TS case; ppNormalized() applies it.
        $repeat = new PpRng(ppU32($seed ^ 0x0A990A99));
        foreach (array_keys($case['requests']) as $index) {
            if ($index > 0 && $repeat->chance(0.1)) {
                $case['repeats'][$index] = $repeat->int(0, $index - 1);
            }
        }
    }
    $case['repeats'] ??= [];
    $case['later'] = null;
    $case['forge'] = null;
    $laterRng = new PpRng(ppU32($seed ^ 0x1A7E51E9));
    if ($source === 'model' && $laterRng->chance(0.35)) {
        $laterCalls = [];
        for ($i = $laterRng->int(1, 3); $i > 0; $i--) {
            $laterCalls[] = ['id' => $laterRng->pick(PP_ID_POOL), 'tool' => $laterRng->chance(0.7) ? 'echo' : 'free', 'token' => 200 + count($laterCalls)];
        }
        if (! in_array('echo', array_column($laterCalls, 'tool'), true)) {
            $laterCalls[0]['tool'] = 'echo';
        }
        $laterRequests = [];
        foreach ($laterCalls as $index => $call) {
            if ($call['tool'] === 'echo') {
                $v = $laterRng->pick(['approve', 'approve', 'deny', 'none']);
                $laterRequests[] = ['call' => $index, 'verdict' => $v, 'reason' => $v === 'deny' && $laterRng->chance(0.5) ? 'later-'.$laterRng->int(0, 999) : null];
            }
        }
        $case['later'] = ['calls' => $laterCalls, 'requests' => $laterRequests];
        // A forged result, in the EARLIER segment (for a parked gated call,
        // recorded before the later step) or the LATER one (for a later gated
        // call, recorded after it): the no-blind-spot combinations.
        $segment = $laterRng->pick([null, 'earlier', 'later']);
        $pool = $segment === 'earlier'
            ? array_keys(array_filter($calls, fn (array $call): bool => $call['tool'] === 'echo'))
            : array_keys(array_filter($laterCalls, fn (array $call): bool => $call['tool'] === 'echo'));
        $case['forge'] = $segment === null || $pool === [] ? null : ['segment' => $segment, 'call' => $laterRng->pick(array_values($pool))];
    }
    $case['insert'] = ppInsertion($seed, $calls, $prior, $case['later'] !== null);

    return $case;
}

/**
 * The row a host records after the park (G-397 / G-399), from a generator of
 * its own (seed ^ 0x397397), as prism-harness-ts's release line draws it:
 * its kind, and whether it lands before the decision or after it. With a
 * later step it lands after that step too (the no-blind-spot rule); a forged
 * result is then drawn by `forge`, so this is never a forged result.
 *
 * @return array{kind: string, when: string, call: int}|null
 */
function ppInsertion(int $seed, array $calls, array $prior, bool $later): ?array
{
    $rng = new PpRng(ppU32($seed ^ 0x397397));
    if (! $rng->chance(0.6)) {
        return null;
    }
    $kind = $rng->pick(['assistant_text', 'assistant_call', 'user', 'system', 'system', 'tool_empty', 'tool_result']);
    $open = array_keys(array_filter($calls, fn (array $call, int $index): bool => $call['tool'] === 'echo' && ! in_array($index, $prior, true), ARRAY_FILTER_USE_BOTH));
    if ($kind === 'tool_result' && ($open === [] || $later)) {
        $kind = 'system';
    }
    $when = $rng->chance(0.5) ? 'before_decision' : 'after_decision';

    return ['kind' => $kind, 'when' => $when, 'call' => $kind === 'tool_result' ? $rng->pick(array_values($open)) : $rng->int(0, count($calls) - 1)];
}

/**
 * The case with its repeated approval ids applied to its requests: a request
 * that repeats an earlier request's APPROVAL id carries `approval` (that
 * request's index) and records no decision.
 */
function ppNormalized(array $c): array
{
    foreach ($c['repeats'] ?? [] as $index => $of) {
        if (isset($c['requests'][$index])) {
            $c['requests'][$index] = [...$c['requests'][$index], 'approval' => $of, 'verdict' => 'none', 'reason' => null];
        }
    }
    $c['repeats'] = [];

    return $c;
}

/** Kinds that SUPERSEDE the parked step when a host records them (outside every run). */
const PP_SUPERSEDES = ['assistant_text', 'assistant_call', 'user'];
const PP_INSERT_KINDS = ['assistant_text', 'assistant_call', 'user', 'system', 'tool_empty', 'tool_result'];

// ---------------------------------------------------------------------------
// The oracle: an independent model of the rule.
// ---------------------------------------------------------------------------

/**
 * The oracle (the release lines' rule): a step not superseded is decided by
 * its own requests; a SUPERSEDED step (by a later tool-call step, or by a
 * host's assistant text, tool-call or user row) gives each gated call with no
 * result of its own ONE replaced denial and runs nothing; a later step is
 * decided by ITS OWN approvals; a forged result is its call's only outcome.
 *
 * @return array{repeated: bool, runs: list<int>, outcome: array<int, string>}
 */
function ppExpected(array $c): array
{
    $c = ppNormalized($c);
    $insert = $c['insert'] ?? null;
    $later = $c['later'] ?? null;
    $forge = $c['forge'] ?? null;
    $supersedingInsert = $insert !== null && in_array($insert['kind'], PP_SUPERSEDES, true);
    // The forged results, by step.
    $forgedOld = [];
    if ($insert !== null && $insert['kind'] === 'tool_result') {
        $forgedOld[] = $insert['call'];
    }
    if (($forge['segment'] ?? null) === 'earlier') {
        $forgedOld[] = $forge['call'];
    }
    $forgedLater = ($forge['segment'] ?? null) === 'later' ? [$forge['call']] : [];

    if ($later === null) {
        $decided = ppDecideStep($c['calls'], $c['requests'], $c['prior'], $forgedOld);
        if ($supersedingInsert) {
            $decided = ppReplaced($c['calls'], $c['prior'], $forgedOld) + ['repeated' => $decided['repeated']];
        }

        return $decided;
    }
    // A later step replaced the parked one; a superseding insert after it
    // replaces the later step too.
    $old = ppReplaced($c['calls'], $c['prior'], $forgedOld);
    $new = $supersedingInsert
        ? ppReplaced($later['calls'], array_keys(array_filter($later['calls'], fn (array $call): bool => $call['tool'] === 'free')), $forgedLater)
        : ppDecideStep($later['calls'], $later['requests'], array_keys(array_filter($later['calls'], fn (array $call): bool => $call['tool'] === 'free')), $forgedLater, laterFree: true);

    return ['repeated' => $new['repeated'] ?? false, 'runs' => $new['runs'], 'outcome' => $old['outcome'] + $new['outcome']];
}

/**
 * One step decided by its own requests.
 *
 * @param  list<int>  $prior  calls with a result before any decision
 * @param  list<int>  $forged  calls a host forged a result for
 * @return array{repeated: bool, runs: list<int>, outcome: array<int, string>}
 */
function ppDecideStep(array $calls, array $requests, array $prior, array $forged, bool $laterFree = false): array
{
    $dup = fn (array $ids): bool => count(array_unique($ids)) !== count($ids);
    $repeated = $dup(array_column($calls, 'id'))
        || $dup(array_map(fn (array $request): string => $calls[$request['call']]['id'], $requests))
        || $dup(array_map(fn (array $request, int $index): int => $request['approval'] ?? $index, $requests, array_keys($requests)));
    $runs = [];
    $outcome = [];
    foreach ($calls as $index => $call) {
        $token = $call['token'];
        if ($call['tool'] === 'free') {
            $outcome[$token] = 'free:'.$token;
        } elseif (in_array($index, $forged, true)) {
            $outcome[$token] = 'forged:'.$token;
        } elseif (in_array($index, $prior, true)) {
            $outcome[$token] = 'prior:'.$token;
        } elseif ($repeated) {
            $outcome[$token] = PP_REPEATED;
        } else {
            $own = null;
            foreach ($requests as $request) {
                if ($request['call'] === $index) {
                    $own = $request;

                    break;
                }
            }
            if (($own['verdict'] ?? null) === 'approve') {
                $runs[] = $token;
                $outcome[$token] = 'ran:'.$token;
            } elseif (($own['verdict'] ?? null) === 'deny') {
                $outcome[$token] = $own['reason'] ?? PP_DENIED;
            } else {
                $outcome[$token] = PP_NO_RESPONSE;
            }
        }
    }

    return ['repeated' => $repeated, 'runs' => $runs, 'outcome' => $outcome];
}

/**
 * A superseded step: nothing runs; each gated call with no result of its own
 * gets ONE replaced denial.
 *
 * @return array{runs: list<int>, outcome: array<int, string>}
 */
function ppReplaced(array $calls, array $prior, array $forged): array
{
    $outcome = [];
    foreach ($calls as $index => $call) {
        $token = $call['token'];
        $outcome[$token] = match (true) {
            $call['tool'] === 'free' => 'free:'.$token,
            in_array($index, $forged, true) => 'forged:'.$token,
            in_array($index, $prior, true) => 'prior:'.$token,
            default => PP_SUPERSEDED,
        };
    }

    return ['runs' => [], 'outcome' => $outcome];
}

// ---------------------------------------------------------------------------
// The rig: the runtime over Prism's OpenAI handler on faked HTTP.
// ---------------------------------------------------------------------------

final class PpRan
{
    /** @var list<int> */
    public static array $echo = [];

    /** @var list<int> */
    public static array $free = [];

    /** Each run (a shrink candidate included) on a session of its own, as each TS rig has fresh stores. */
    public static int $runs = 0;
}

final class PpViolation extends RuntimeException {}

function ppSession(array $c): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('property-'.$c['seed'].'-'.PpRan::$runs);
}

function ppRig(array $c): void
{
    PpRan::$echo = [];
    PpRan::$free = [];
    PpRan::$runs++;
    // The fake is installed once (beforeEach): Laravel invokes EVERY stub
    // callback on a request, so a fake per case would drain the queue once
    // per case already run. Each case starts on an empty queue.
    G360::$queue = [];
    foreach ([ModeRegistry::class, PrismHarness::class, AgentRuntime::class, SubagentRunner::class, RunTrees::class, Plans::class] as $class) {
        app()->forgetInstance($class);
    }
    if ($c['source'] === 'model') {
        G360::calls(array_map(fn (array $call): array => [$call['tool'], ['n' => $call['token']], $call['id']], $c['calls']));
    }
    // Every later reply is the text `{"ok":true}`, a document for a structured turn.
    for ($i = 0; $i < 12; $i++) {
        G360::text('{"ok":true}');
    }
}

/**
 * One turn on `$path`.
 *
 * @return array{steps: list<RunStep>|null, response: AgentResponse|StructuredAgentResponse}
 */
function ppTurn(array $c, string $path, string $prompt): array
{
    $session = ppSession($c);
    if ($path === 'send') {
        return ['steps' => null, 'response' => $session->send($prompt)];
    }
    if ($path === 'structured') {
        return ['steps' => null, 'response' => $session->sendStructured($prompt,
            new ObjectSchema('doc', 'A document.', [new BooleanSchema('ok', 'Ok.')], ['ok']))];
    }

    return ppDrain($session->streamSteps($prompt));
}

/** @return array{steps: list<RunStep>, response: mixed} */
function ppDrain(Generator $stream): array
{
    $steps = [];
    foreach ($stream as $step) {
        $steps[] = $step;
    }

    return ['steps' => $steps, 'response' => $stream->getReturn()];
}

/** @return list<array<string, mixed>> the stored rows, with their type */
function ppRows(array $c): array
{
    return ppSession($c)->thread()->storedMessages()->orderBy('position')->get()
        ->map(fn ($row): array => ['type' => $row->type, ...$row->payload])->all();
}

/** @return array<string, mixed> the step's stored assistant row */
function ppStep(array $c): array
{
    foreach (ppRows($c) as $row) {
        if ($row['type'] === 'assistant' && ($row['tool_calls'] ?? []) !== []) {
            return $row;
        }
    }

    throw new PpViolation('the step was stored');
}

/** @return list<array{0: int, 1: string}> every stored result entry after the step's assistant row, as [token, result] */
function ppOutcomes(array $c): array
{
    $out = [];
    $after = false;
    foreach (ppRows($c) as $row) {
        if ($row['type'] === 'assistant' && ($row['tool_calls'] ?? []) !== []) {
            $after = true;

            continue;
        }
        if (! $after || $row['type'] !== 'tool_result') {
            continue;
        }
        // EVERY result row after the first step, to the end of the thread
        // (replaced denials land after later rows); an entry for no call of
        // a step (the inserted call, token 900) is not an outcome of it.
        foreach ($row['tool_results'] ?? [] as $entry) {
            $n = $entry['args']['n'] ?? null;
            if (! is_int($n) || $n === 900) {
                continue;
            }
            $out[] = [$n, (string) $entry['result']];
        }
    }

    return $out;
}

function ppCheck(bool $condition, string $invariant, mixed $detail = null): void
{
    if (! $condition) {
        throw new PpViolation($invariant.($detail === null ? '' : ': '.json_encode($detail)));
    }
}

/** @param list<int> $a @param list<int> $b */
function ppSameSet(array $a, array $b): bool
{
    $a = array_values(array_unique($a));
    $b = array_values(array_unique($b));
    sort($a);
    sort($b);

    return $a === $b;
}

/** Record decisions through the path every public decision takes, without resuming. */
function ppRecord(array $c, array $decisions): void
{
    $session = ppSession($c);
    (fn () => $this->recordDecisions($decisions))->call($session);
}

// ---------------------------------------------------------------------------
// One example: drive it, then check every invariant against the oracle.
// ---------------------------------------------------------------------------

function ppRunCase(array $c): void
{
    $c = ppNormalized($c);
    $oracle = ppExpected($c);
    ppRig($c);
    $gated = array_values(array_filter($c['calls'], fn (array $call): bool => $call['tool'] === 'echo'));
    $frees = array_values(array_filter($c['calls'], fn (array $call): bool => $call['tool'] === 'free'));

    if ($c['source'] === 'model') {
        // Prism's OpenAI STREAM parser refuses a function call with an empty
        // call_id before the harness sees the step (fail closed, in core), so
        // a step with an empty id is taken on the non-streamed path; every
        // other path, and every resume, is as generated.
        $initial = $c['initial'] === 'streamSteps' && in_array('', array_column($c['calls'], 'id'), true) ? 'send' : $c['initial'];
        $parked = ppTurn($c, $initial, 'go');
        $response = $parked['response'];
        if ($parked['steps'] === null) {
            ppCheck($response->awaitingApproval(), 'the step parks for a person', $response->response->finishReason->value);
            // Invariant 6: one pending approval per gated call, in call order,
            // each its own id, each naming its own call.
            $pending = $response->pendingApprovals();
        } else {
            // A stream reports its approvals as steps, and returns nothing.
            $pending = array_map(fn (RunStep $step): ToolApprovalRequest => new ToolApprovalRequest($step->data['approvalId'], $step->data['toolCallId']),
                array_values(array_filter($parked['steps'], fn (RunStep $step): bool => $step->type === RunStep::APPROVAL_REQUEST)));
            ppCheck($pending !== [] && ppSession($c)->thread()->pendingApprovalIds() === array_map(fn (ToolApprovalRequest $p): string => $p->approvalId, $pending),
                'the step parks for a person');
        }
        ppCheck(count($pending) === count($gated), '6: one pending approval per gated call', count($pending));
        $ids = array_map(fn (ToolApprovalRequest $p): string => $p->approvalId, $pending);
        ppCheck(count(array_unique($ids)) === count($ids), '6: pending approval ids are distinct');
        $thread = ppSession($c)->thread();
        foreach ($pending as $i => $p) {
            $call = $thread->approvalCall($p->approvalId);
            $arguments = is_string($call['arguments'] ?? null) ? json_decode($call['arguments'], true) : ($call['arguments'] ?? null);
            ppCheck($p->toolCallId === $gated[$i]['id'] && ($call['name'] ?? null) === 'echo' && (int) ($arguments['n'] ?? -1) === $gated[$i]['token'],
                '6: pending approval names its own call', ['pending' => $p->toolCallId, 'call' => $call, 'want' => $gated[$i]]);
        }
        ppCheck(array_column(ppStep($c)['tool_approval_requests'] ?? [], 'approval_id') === $ids, '6: the stored requests are the reported approvals');
        // Ungated calls ran at once, each exactly once.
        ppCheck(ppSameSet(PpRan::$free, array_column($frees, 'token')) && count(PpRan::$free) === count($frees),
            'ungated calls run once when the step is taken', PpRan::$free);
        ppCheck(PpRan::$echo === [], '1: no gated call runs before a decision', PpRan::$echo);
        $approvalIds = $ids;
    } else {
        $approvalIds = array_map(fn (array $request, int $index): string => 'apr_'.$c['seed'].'_'.($request['approval'] ?? $index), $c['requests'], array_keys($c['requests']));
        $calls = array_map(fn (array $call): ToolCall => new ToolCall($call['id'], $call['tool'], ['n' => $call['token']]), $c['calls']);
        $requests = array_map(fn (array $request, int $index): ToolApprovalRequest => new ToolApprovalRequest(
            $approvalIds[$index], $c['calls'][$request['call']]['id'], ToolApprovalRequest::ARGUMENTS_AS_SENT), $c['requests'], array_keys($c['requests']));
        $messages = [new UserMessage('go'), new AssistantMessage('', $calls, [], $requests)];
        if ($c['prior'] !== []) {
            $messages[] = new ToolResultMessage(array_map(function (int $index) use ($c): ToolResult {
                $call = $c['calls'][$index];

                return new ToolResult($call['id'], $call['tool'], ['n' => $call['token']], ($call['tool'] === 'free' ? 'free:' : 'prior:').$call['token']);
            }, $c['prior']));
        }
        ppSession($c)->thread()->record($messages, toolNames: ['echo', 'free']);
    }

    $later = $c['later'] ?? null;
    $insert = $c['insert'] ?? null;
    $forge = $c['forge'] ?? null;
    $thread = fn () => ppSession($c)->thread();
    $forged = function (array $call): ToolResultMessage {
        return new ToolResultMessage([new ToolResult($call['id'], $call['tool'], ['n' => $call['token']], 'forged:'.$call['token'])]);
    };
    // The no-blind-spot shapes: a forged result in the earlier segment, then
    // a later step (another writer's), then a forged result in the later one.
    if (($forge['segment'] ?? null) === 'earlier') {
        $thread()->record([$forged($c['calls'][$forge['call']])]);
    }
    $laterIds = [];
    if ($later !== null) {
        $laterIds = array_map(fn (int $index): string => 'apr_later_'.$c['seed'].'_'.$index, array_keys($later['requests']));
        $calls = array_map(fn (array $call): ToolCall => new ToolCall($call['id'], $call['tool'], ['n' => $call['token']]), $later['calls']);
        $requests = array_map(fn (array $request, int $index): ToolApprovalRequest => new ToolApprovalRequest(
            $laterIds[$index], $later['calls'][$request['call']]['id'], ToolApprovalRequest::ARGUMENTS_AS_SENT), $later['requests'], array_keys($later['requests']));
        $rows = [new AssistantMessage('', $calls, [], $requests)];
        $laterFrees = array_values(array_filter($later['calls'], fn (array $call): bool => $call['tool'] === 'free'));
        if ($laterFrees !== []) {
            $rows[] = new ToolResultMessage(array_map(fn (array $call): ToolResult => new ToolResult($call['id'], $call['tool'], ['n' => $call['token']], 'free:'.$call['token']), $laterFrees));
        }
        $thread()->record($rows, toolNames: ['echo', 'free']);
    }
    if (($forge['segment'] ?? null) === 'later') {
        $thread()->record([$forged($later['calls'][$forge['call']])]);
    }
    $inserted = $insert === null ? null : match ($insert['kind']) {
        'assistant_text' => new AssistantMessage('A note.'),
        'assistant_call' => new AssistantMessage('', [new ToolCall($c['calls'][$insert['call']]['id'], 'free', ['n' => 900])]),
        'user' => new UserMessage('Something else.'),
        'system' => new SystemMessage('A system note.'),
        'tool_empty' => new ToolResultMessage([]),
        'tool_result' => $forged($c['calls'][$insert['call']]),
    };
    if ($inserted !== null && $insert['when'] === 'before_decision') {
        $thread()->record([$inserted]);
    }

    // The decisions, in a shuffled order: the parked step's, then a later step's.
    $decisions = [];
    foreach ($c['order'] as $index) {
        $request = $c['requests'][$index];
        if ($request['verdict'] !== 'none') {
            $decisions[] = new ToolApprovalResponse($approvalIds[$index], $request['verdict'] === 'approve', $request['reason']);
        }
    }
    $laterDecisions = [];
    foreach ($later['requests'] ?? [] as $index => $request) {
        if ($request['verdict'] !== 'none') {
            $laterDecisions[] = new ToolApprovalResponse($laterIds[$index], $request['verdict'] === 'approve', $request['reason']);
        }
    }
    $supersedingBefore = $inserted !== null && $insert['when'] === 'before_decision' && in_array($insert['kind'], PP_SUPERSEDES, true);
    // A decision for a step superseded BEFORE it is refused with
    // approval_not_pending, all or nothing per batch.
    $batches = [];
    if ($decisions !== []) {
        $batches[] = ['decisions' => $decisions, 'refused' => $later !== null || $supersedingBefore, 'repeated' => $oracle['repeated'] && $later === null];
    }
    if ($laterDecisions !== []) {
        $batches[] = ['decisions' => $laterDecisions, 'refused' => $supersedingBefore, 'repeated' => $oracle['repeated']];
    }
    $first = null;
    foreach ($batches as $batch) {
        $refused = false;
        try {
            if ($c['recorder'] === 'streamDecision' && $first === null && ! $batch['refused'] && ($insert['when'] ?? null) !== 'after_decision') {
                $first = ppDrain(ppSession($c)->streamDecision($batch['decisions']));
                $recorded = array_values(array_filter($first['steps'], fn (RunStep $step): bool => $step->type === RunStep::DECISION_RECORDED));
                ppCheck(array_map(fn (RunStep $step): array => [$step->data['approvalId'], $step->data['verdict']], $recorded)
                    === array_map(fn (ToolApprovalResponse $d): array => [$d->approvalId, ! $batch['repeated'] && $d->approved ? 'approved' : 'denied'], $batch['decisions']),
                    '6: one decision_recorded step per decision, as recorded');
            } elseif ($c['recorder'] === 'recordApproval') {
                foreach ($batch['decisions'] as $decision) {
                    ppRecord($c, [$decision]);
                }
            } else {
                ppRecord($c, $batch['decisions']);
            }
        } catch (ApprovalNotPending) {
            $refused = true;
        }
        ppCheck($refused === $batch['refused'], 'G-397: a decision is refused exactly when its step was superseded first', ['refused' => $refused, 'expected' => $batch['refused']]);
    }
    if ($inserted !== null && $insert['when'] === 'after_decision') {
        $thread()->record([$inserted]);
    }
    $first ??= ppTurn($c, $c['resume'], '');
    $superseded = $later !== null || ($insert !== null && in_array($insert['kind'], PP_SUPERSEDES, true));

    // Invariants 1-4, then full equality with the oracle.
    $ran = PpRan::$echo;
    $all = [...array_map(fn (array $q): array => [$q, $c['calls'][$q['call']]['token']], $c['requests']),
        ...array_map(fn (array $q): array => [$q, $later['calls'][$q['call']]['token']], $later['requests'] ?? [])];
    ppCheck(count($ran) === count(array_unique($ran)), '3: a call runs at most once', $ran);
    $denied = array_map(fn (array $pair): int => $pair[1], array_values(array_filter($all, fn (array $pair): bool => $pair[0]['verdict'] === 'deny')));
    ppCheck(array_intersect($ran, $denied) === [], '1: a call a person denied never runs', $ran);
    $approves = array_map(fn (array $pair): int => $pair[1], array_values(array_filter($all, fn (array $pair): bool => $pair[0]['verdict'] === 'approve')));
    if ($later !== null) {
        ppCheck(array_filter($ran, fn (int $token): bool => $token < 200) === [], "a replaced step's approvals never run anything", $ran);
    }
    ppCheck(array_diff($ran, $approves) === [], '2: each run call has its own approve', $ran);
    ppCheck(count($ran) <= count($approves), '2: one decision decides at most one call', ['ran' => count($ran), 'approves' => count($approves)]);
    if ($oracle['repeated']) {
        ppCheck($ran === [], '4: a step with repeated ids runs none of its gated calls', $ran);
    }
    ppCheck(ppSameSet($ran, $oracle['runs']), "the calls that ran are exactly the oracle's", ['ran' => $ran, 'expected' => $oracle['runs']]);

    // Invariant 6 on the resume itself (a streamed one reports its steps):
    // what it says it ran is what ran.
    if ($first['steps'] !== null) {
        $results = array_values(array_filter($first['steps'], fn (RunStep $step): bool => $step->type === RunStep::TOOL_RESULT));
        $ok = [];
        foreach ($results as $step) {
            if ($step->data['ok'] === true) {
                $ok[] = (int) preg_replace('/^ran:/', '', (string) ($step->data['result'] ?? ''));
            }
        }
        ppCheck(ppSameSet($ok, $ran) && count($ok) === count($ran), '6: the ok tool_result steps are the calls that ran', $ok);
        $forgedTokens = array_keys(array_filter(ppExpected($c)['outcome'], fn (string $o): bool => str_starts_with($o, 'forged:')));
        $resolving = $later === null ? $c['calls'] : $later['calls'];
        $resolvingPrior = $later === null ? $c['prior'] : [];
        $open = $superseded && ! ($later !== null && ! ($insert !== null && in_array($insert['kind'], PP_SUPERSEDES, true)))
            ? []
            : array_filter($resolving, fn (array $call, int $index): bool => $call['tool'] === 'echo' && ! in_array($index, $resolvingPrior, true)
                && ! in_array($call['token'], $forgedTokens, true), ARRAY_FILTER_USE_BOTH);
        ppCheck(count($results) === count($open), '6: one tool_result step per open gated call', ['steps' => count($results), 'open' => count($open)]);
    }

    ppSettledOutcomes($c, $oracle, '5');

    // Repeat resumes, with later decisions in between: nothing runs twice and
    // no outcome changes.
    $before = PpRan::$echo;
    foreach ($c['again'] as $again) {
        if ($again['approveAll']) {
            foreach ([...$approvalIds, ...$laterIds] as $id) {
                try {
                    ppRecord($c, [new ToolApprovalResponse($id, true)]);
                } catch (Throwable) {
                    // Refused: nothing pending any more.
                }
            }
        }
        $next = ppTurn($c, $again['path'], '');
        if ($next['steps'] !== null) {
            ppCheck(array_filter($next['steps'], fn (RunStep $step): bool => $step->type === RunStep::TOOL_RESULT) === [],
                '3: a repeat resume answers no call again');
        }
    }
    ppCheck(PpRan::$echo === $before, '3: a call runs at most once across any resume sequence', ['before' => $before, 'after' => PpRan::$echo]);
    if ($c['source'] === 'stored') {
        ppCheck(PpRan::$free === [], 'a stored ungated call with a result never runs', PpRan::$free);
    } else {
        // The parked step's ungated calls ran when it was taken; a later
        // step another writer recorded already holds its results.
        ppCheck(count(PpRan::$free) === count($frees), '3: an ungated call runs once', PpRan::$free);
    }
    ppSettledOutcomes($c, $oracle, '5 (after repeat resumes)');
}

/** Invariants 5 and 6 on the thread rows: every call has exactly one outcome, the oracle's. */
function ppSettledOutcomes(array $c, array $oracle, string $label): void
{
    $seen = [];
    foreach (ppOutcomes($c) as [$token, $result]) {
        $seen[$token][$result] = true;
    }
    foreach ($oracle['outcome'] as $token => $want) {
        $got = array_keys($seen[$token] ?? []);
        ppCheck(count($got) === 1, "{$label}: every call gets exactly one recorded outcome", ['token' => $token, 'got' => $got]);
        ppCheck($got[0] === $want, '6: the recorded outcome matches what ran', ['token' => $token, 'got' => $got[0], 'want' => $want]);
    }
    ppCheck(array_diff(array_keys($seen), array_keys($oracle['outcome'])) === [], "{$label}: no outcome for a call that is not in the step", array_keys($seen));
}

// ---------------------------------------------------------------------------
// Shrinking: drop calls, requests and repeat resumes while it still fails.
// ---------------------------------------------------------------------------

function ppWithoutCall(array $c, int $drop): ?array
{
    if (count($c['calls']) <= 1) {
        return null;
    }
    $calls = array_values(array_filter($c['calls'], fn (array $call, int $index): bool => $index !== $drop, ARRAY_FILTER_USE_BOTH));
    if (! in_array('echo', array_column($calls, 'tool'), true)) {
        return null;
    }
    $remap = fn (int $index): int => $index > $drop ? $index - 1 : $index;
    $kept = array_filter($c['requests'], fn (array $request): bool => $request['call'] !== $drop);
    $position = array_flip(array_keys($kept));

    return [
        ...$c,
        'calls' => $calls,
        'requests' => array_values(array_map(fn (array $request): array => [...$request, 'call' => $remap($request['call'])], $kept)),
        'repeats' => [],
        'insert' => ($c['insert'] ?? null) === null || $c['insert']['call'] === $drop ? null : [...$c['insert'], 'call' => $remap($c['insert']['call'])],
        'forge' => ($c['forge']['segment'] ?? null) === 'earlier' ? ($c['forge']['call'] === $drop ? null : [...$c['forge'], 'call' => $remap($c['forge']['call'])]) : ($c['forge'] ?? null),
        'prior' => array_values(array_map($remap, array_filter($c['prior'], fn (int $index): bool => $index !== $drop))),
        'order' => array_values(array_map(fn (int $index): int => $position[$index], array_filter($c['order'], fn (int $index): bool => isset($position[$index])))),
    ];
}

function ppWithoutRequest(array $c, int $drop): ?array
{
    if ($c['source'] === 'model') {
        return null;
    }

    return [
        ...$c,
        'requests' => array_values(array_filter($c['requests'], fn (array $request, int $index): bool => $index !== $drop, ARRAY_FILTER_USE_BOTH)),
        'repeats' => [],
        'order' => array_values(array_map(fn (int $index): int => $index > $drop ? $index - 1 : $index,
            array_filter($c['order'], fn (int $index): bool => $index !== $drop))),
    ];
}

/** @return list<array<string, mixed>> */
function ppSmaller(array $c): array
{
    $out = [];
    if ($c['again'] !== []) {
        $out[] = [...$c, 'again' => array_slice($c['again'], 0, -1)];
    }
    foreach (['insert', 'later', 'forge', 'repeats'] as $key) {
        if (($c[$key] ?? null) !== null && $c[$key] !== []) {
            $out[] = [...$c, $key => $key === 'repeats' ? [] : null, ...($key === 'later' ? ['forge' => null] : [])];
        }
    }
    foreach (array_keys($c['calls']) as $i) {
        if (($s = ppWithoutCall($c, $i)) !== null) {
            $out[] = $s;
        }
    }
    foreach (array_keys($c['requests']) as $i) {
        if (($s = ppWithoutRequest($c, $i)) !== null) {
            $out[] = $s;
        }
    }

    return $out;
}

/** Run one case on a clean database; null when every invariant holds. */
function ppFailure(array $c): ?string
{
    DB::beginTransaction();
    try {
        ppRunCase($c);

        return null;
    } catch (Throwable $error) {
        return $error::class.': '.$error->getMessage();
    } finally {
        DB::rollBack();
    }
}

function ppInvariant(string $reason): string
{
    return explode(': [', explode(': {', $reason)[0])[0];
}

/** @return array{c: array<string, mixed>, reason: string} */
function ppShrink(array $c, string $reason): array
{
    $best = ['c' => $c, 'reason' => $reason];
    for ($budget = 200; $budget > 0;) {
        $improved = false;
        foreach (ppSmaller($best['c']) as $candidate) {
            $budget--;
            $why = ppFailure($candidate);
            // Only a candidate that breaks the SAME invariant.
            if ($why !== null && ppInvariant($why) === ppInvariant($reason)) {
                $best = ['c' => $candidate, 'reason' => $why];
                $improved = true;

                break;
            }
            if ($budget <= 0) {
                break;
            }
        }
        if (! $improved) {
            break;
        }
    }

    return $best;
}

/** @param list<int> $seeds */
function ppHolds(array $seeds): void
{
    foreach ($seeds as $seed) {
        $c = ppGenerate($seed);
        $why = ppFailure($c);
        if ($why === null) {
            continue;
        }
        $small = ppShrink($c, $why);
        $report = implode("\n", [
            "Property failed at seed {$seed} (replay: PRISM_PROPERTY_SEED={$seed}).",
            'Invariant: '.$small['reason'],
            'Shrunk case: '.json_encode($small['c']),
            'Original failure: '.$why,
            'Full case: '.json_encode($c),
        ]);
        fwrite(STDERR, $report."\n");

        throw new PpViolation($report);
    }
}

// ---------------------------------------------------------------------------

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['echo', 'free']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['echo']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 6);
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('echo')->for('Echo.')->withNumberParameter('n', 'A number')
            ->using(function (int|float $n): string {
                PpRan::$echo[] = (int) $n;

                return 'ran:'.(int) $n;
            }),
        (new Tool)->as('free')->for('Free.')->withNumberParameter('n', 'A number')
            ->using(function (int|float $n): string {
                PpRan::$free[] = (int) $n;

                return 'free:'.(int) $n;
            }),
    ]);
});

it('reproduces the TS generator bit for bit', function (): void {
    // The TS implementation's own output for these seeds (1e4ca65, run under
    // node): the same seed is the same case in both languages.
    $ts = [
        '{"seed":2034523272,"source":"stored","calls":[{"id":"b","tool":"free","token":100},{"id":"a","tool":"echo","token":101},{"id":"b","tool":"free","token":102},{"id":"c","tool":"echo","token":103},{"id":"a","tool":"free","token":104}],"requests":[{"call":3,"verdict":"none","reason":null}],"prior":[0,2,4],"order":[0],"initial":"streamSteps","recorder":"streamDecision","resume":"streamSteps","again":[]}',
        '{"seed":232174035,"source":"stored","calls":[{"id":"b","tool":"echo","token":100},{"id":"","tool":"echo","token":101},{"id":"a","tool":"echo","token":102}],"requests":[{"call":0,"verdict":"approve","reason":null},{"call":0,"verdict":"approve","reason":null},{"call":1,"verdict":"deny","reason":"no-48"},{"call":2,"verdict":"approve","reason":null}],"prior":[1],"order":[1,2,3,0],"initial":"structured","recorder":"recordDecisions","resume":"send","again":[{"approveAll":false,"path":"send"},{"approveAll":true,"path":"send"}]}',
        '{"seed":2060958372,"source":"stored","calls":[{"id":"c","tool":"echo","token":100},{"id":"a","tool":"echo","token":101},{"id":"c","tool":"free","token":102},{"id":"a","tool":"echo","token":103}],"requests":[{"call":1,"verdict":"deny","reason":"no-134"},{"call":0,"verdict":"deny","reason":"no-504"},{"call":3,"verdict":"approve","reason":null},{"call":0,"verdict":"deny","reason":"no-611"}],"prior":[2],"order":[0,3,2,1],"initial":"send","recorder":"recordDecisions","resume":"send","again":[{"approveAll":true,"path":"structured"}]}',
    ];
    foreach ($ts as $json) {
        $case = json_decode($json, true);
        $php = ppGenerate($case['seed']);
        unset($php['insert'], $php['later'], $php['forge'], $php['repeats']);
        expect(json_encode($php))->toBe($json);
    }
    expect(array_map(fn (int $index): int => ppCaseSeed(0x6392, $index), [0, 1, 2, 149]))->toBe([2483776628, 2972160347, 2366528728, 3841045332]);
});

it('holds for the seeds that broke the rule on TS main', function (int $seed): void {
    ppHolds([$seed]);
    expect(true)->toBeTrue();
})->with(PP_REGRESSION_SEEDS);

it('holds over generated steps', function (): void {
    $replay = getenv('PRISM_PROPERTY_SEED');
    $examples = ppPositive(getenv('PRISM_PROPERTY_EXAMPLES'), PP_DEFAULT_EXAMPLES, 1, 100000);
    $base = ppPositive(getenv('PRISM_PROPERTY_BASE_SEED'), 0x6392);
    $seeds = $replay !== false
        ? [ppU32(ppPositive($replay, 0))]
        : array_map(fn (int $index): int => ppCaseSeed($base, $index), range(0, $examples - 1));
    $started = microtime(true);
    ppHolds($seeds);
    fwrite(STDERR, sprintf("G-392 property: %d examples passed in %d ms (base seed %d).\n", count($seeds), (int) ((microtime(true) - $started) * 1000), $base));
    expect(true)->toBeTrue();
});

it('the generator reaches every shape the brief asks for', function (): void {
    $examples = max(ppPositive(getenv('PRISM_PROPERTY_EXAMPLES'), 150, 1, 100000), 150);
    $base = ppPositive(getenv('PRISM_PROPERTY_BASE_SEED'), 0x6392);
    $cases = array_map(fn (int $index): array => ppGenerate(ppCaseSeed($base, $index)), range(0, $examples - 1));
    $has = fn (Closure $predicate): bool => array_filter($cases, $predicate) !== [];
    expect($has(fn (array $c): bool => ppExpected($c)['repeated'] && $c['source'] === 'model'))->toBeTrue()
        ->and($has(fn (array $c): bool => ! ppExpected($c)['repeated'] && ppExpected($c)['runs'] !== []))->toBeTrue()
        ->and($has(fn (array $c): bool => count(array_filter($c['calls'], fn (array $call): bool => $call['id'] === '')) > 1))->toBeTrue()
        ->and($has(fn (array $c): bool => $c['source'] === 'stored' && count(array_unique(array_column($c['requests'], 'call'))) < count($c['requests'])))->toBeTrue()
        ->and($has(fn (array $c): bool => $c['source'] === 'stored' && array_filter($c['prior'], fn (int $index): bool => $c['calls'][$index]['tool'] === 'echo') !== []))->toBeTrue()
        ->and($has(fn (array $c): bool => array_filter($c['again'], fn (array $a): bool => $a['approveAll']) !== []))->toBeTrue()
        ->and($has(fn (array $c): bool => $c['recorder'] === 'streamDecision'))->toBeTrue();
    foreach (PP_PATHS as $path) {
        expect($has(fn (array $c): bool => $c['resume'] === $path))->toBeTrue();
    }
});

// NO BLIND SPOT: the default fixed-seed set must generate every combination
// of a later step with inserted rows and with forged results in BOTH
// segments, every inserted kind before and after the decision, and repeated
// approval ids with an approval; a run that missed one fails here.
it('the default set covers every combination the property must check', function (): void {
    $cases = array_map(fn (int $index): array => ppGenerate(ppCaseSeed(0x6392, $index)), range(0, PP_DEFAULT_EXAMPLES - 1));
    $has = fn (Closure $predicate): bool => array_filter($cases, $predicate) !== [];
    $missing = [];
    foreach (PP_INSERT_KINDS as $kind) {
        foreach (['before_decision', 'after_decision'] as $when) {
            if (! $has(fn (array $c): bool => ($c['insert']['kind'] ?? null) === $kind && $c['insert']['when'] === $when)) {
                $missing[] = "{$kind} {$when}";
            }
        }
    }
    foreach (['superseding' => true, 'transparent' => false] as $label => $supersedes) {
        if (! $has(fn (array $c): bool => $c['later'] !== null && $c['insert'] !== null && in_array($c['insert']['kind'], PP_SUPERSEDES, true) === $supersedes)) {
            $missing[] = "later step + {$label} insert";
        }
    }
    foreach (['earlier', 'later'] as $segment) {
        if (! $has(fn (array $c): bool => ($c['forge']['segment'] ?? null) === $segment)) {
            $missing[] = "later step + forged result in the {$segment} segment";
        }
        if (! $has(fn (array $c): bool => ($c['forge']['segment'] ?? null) === $segment && $c['insert'] !== null)) {
            $missing[] = "later step + forged result in the {$segment} segment + insert";
        }
    }
    if (! $has(fn (array $c): bool => $c['later'] !== null && array_filter($c['later']['requests'], fn (array $q): bool => $q['verdict'] === 'approve') !== []
        && array_filter($c['requests'], fn (array $q): bool => $q['verdict'] === 'approve') !== [])) {
        $missing[] = 'later step with approvals for both steps';
    }
    if (! $has(fn (array $c): bool => $c['repeats'] !== []
        && array_filter(ppNormalized($c)['requests'], fn (array $q): bool => $q['verdict'] === 'approve') !== [])) {
        $missing[] = 'repeated approval id with an approve';
    }
    expect($missing)->toBe([]);
});

it('refuses a property setting that is set but is not a whole number in range', function (string $value): void {
    expect(fn () => ppPositive($value, 150, 1, 100000))->toThrow(InvalidArgumentException::class);
})->with(['', '0', '-1', '1.5', '1e3', 'abc', ' 5', '+5', '007', '100001', '99999999999999999999']);

it('reads a property setting only when it is set', function (): void {
    expect(ppPositive(false, 150, 1, 100000))->toBe(150)
        ->and(ppPositive('2000', 150, 1, 100000))->toBe(2000)
        ->and(ppPositive('25490', 0))->toBe(25490);
});
