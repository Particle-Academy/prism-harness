<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentResponse;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Plans\Plans;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\StructuredAgentResponse;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Support\CallPairing;
use Prism\Harness\Tools\CanonicalJson;
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
use Tests\Support\SentRequests;

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
        // SEGMENT OWNERSHIP (round 13b), from a stream of its own so every
        // draw above is unchanged: a result recorded in the LATER segment
        // whose id, tool and arguments are exactly an EARLIER gated call's.
        // It counts only for the later step: it answers the later call it
        // matches there (id and tool, then id), or nothing.
        $crossRng = new PpRng(ppU32($seed ^ 0x5E6A0B13));
        $earlierGated = array_keys(array_filter($calls, fn (array $call): bool => $call['tool'] === 'echo'));
        if ($crossRng->chance(0.5)) {
            $case['cross'] = ['call' => $crossRng->pick($earlierGated)];
        }
    }
    $case['cross'] ??= null;
    $case['insert'] = ppInsertion($seed, $calls, $prior, $case['later'] !== null);
    // Round 15, from streams of their own so every draw above is unchanged:
    // an IDENTICAL duplicate of one call (same id, tool and token: a multiset
    // of outcomes), and a copy of the parked step a host records that keeps
    // its approval requests but alters the calls' arguments (the approval
    // binds to the call the person was shown, so nothing of it runs).
    $twinRng = new PpRng(ppU32($seed ^ 0x7D0B1E5));
    $case['twin'] = $case['later'] === null && $case['forge'] === null && $case['cross'] === null
        && ($case['insert']['kind'] ?? null) !== 'tool_result' && $twinRng->chance(0.4) ? $twinRng->int(0, $count - 1) : null;
    $copyRng = new PpRng(ppU32($seed ^ 0xC0B1A5ED));
    $case['copy'] = $source === 'model' && $case['later'] === null && $case['insert'] === null && $case['twin'] === null
        && $copyRng->chance(0.25) ? ['when' => $copyRng->pick(['before_decision', 'after_decision']), 'both' => $copyRng->chance(0.3)] : null;
    // Round 16 (item 8), from streams of their own so every draw above is
    // unchanged: a copy written RAW (as another writer with table access
    // can), its tool_calls a JSON string (G-408), non-objects MIXED with the
    // copy's calls (#92), or the objects themselves; and a RAW host row
    // carrying a harness mark under no run id, the parked run's id or
    // another run's (a run id is never a trust signal: it changes nothing).
    $rawRng = new PpRng(ppU32($seed ^ 0x2A3C0F16));
    if ($case['copy'] !== null && $rawRng->chance(0.5)) {
        $case['copy']['raw'] = $rawRng->pick(['json_string', 'mixed', 'objects']);
    }
    $markRng = new PpRng(ppU32($seed ^ 0x16F0A9ED));
    $case['forged_mark'] = $case['copy'] === null && $markRng->chance(0.3) ? [
        'kind' => $markRng->pick(['started', 'ended_by_start', 'superseded', 'replaced', 'journal']),
        'run' => $markRng->pick(['none', 'parked', 'other']),
        'call' => $markRng->pick(array_values($gated)),
    ] : null;

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
    $twin = $c['twin'] ?? null;
    if ($twin !== null && isset($c['calls'][$twin])) {
        $c['calls'][] = $c['calls'][$twin];
        $copy = count($c['calls']) - 1;
        // A gated call has a prior result only where a result can say whose
        // it is: never in a step whose ids repeat. An ungated call ran.
        $c['prior'] = array_values(array_filter($c['prior'], fn (int $index): bool => $c['calls'][$index]['tool'] === 'free'));
        if ($c['source'] === 'stored' && $c['calls'][$copy]['tool'] === 'free') {
            $c['prior'][] = $copy;
        }
    }
    $c['twin'] = null;

    return ppClosedDecisions($c);
}

/**
 * Round 16 (item 2): a decision on an approval whose call already has an
 * OUTCOME (a result recorded before the decision) is refused, all or nothing
 * per batch, so nothing of that batch is recorded (one by one, everything
 * from the first such decision on). The case keeps the decisions as given
 * (`given`) for the driver, and its requests carry only what is recorded.
 */
function ppClosedDecisions(array $c): array
{
    if (isset($c['given'])) {
        return $c;
    }
    $c['given'] = ['requests' => $c['requests'], 'later' => $c['later']['requests'] ?? null];
    $c['closed_batch'] = false;
    $c['closed_later'] = false;
    $insert = $c['insert'] ?? null;
    $forge = $c['forge'] ?? null;
    $later = $c['later'] ?? null;
    $supersedingBefore = ($insert !== null && $insert['when'] === 'before_decision' && in_array($insert['kind'], PP_SUPERSEDES, true))
        || (($c['copy'] ?? null) !== null && ($c['copy']['when'] ?? null) === 'before_decision');
    if ($later === null && ! $supersedingBefore) {
        $closed = $c['prior'];
        if (($forge['segment'] ?? null) === 'earlier') {
            $closed[] = $forge['call'];
        }
        if ($insert !== null && $insert['kind'] === 'tool_result' && $insert['when'] === 'before_decision') {
            $closed[] = $insert['call'];
        }
        [$c['requests'], $c['closed_batch']] = ppWithoutClosed($c, $c['calls'], $c['requests'], $closed);
    }
    if ($later !== null && ! $supersedingBefore) {
        $closed = array_keys(array_filter($later['calls'], fn (array $call): bool => $call['tool'] === 'free'));
        if (($forge['segment'] ?? null) === 'later') {
            $closed[] = $forge['call'];
        }
        if (($c['cross'] ?? null) !== null) {
            $earlierCall = $c['calls'][$c['cross']['call']];
            $target = ppSegmentMatch($later['calls'], $closed, $earlierCall['id'], $earlierCall['tool']);
            if ($target !== null) {
                $closed[] = $target;
            }
        }
        [$c['later']['requests'], $c['closed_later']] = ppWithoutClosed($c, $later['calls'], $later['requests'], $closed, array_keys($later['requests']));
    }

    return $c;
}

/**
 * `$requests` as recorded when a decision on a closed call is refused, and
 * whether one was. A request pairs with the first call with its id that has
 * no outcome before the decisions; only an id no other call shares may pair
 * with a call that has one (the pairing by id).
 *
 * `$order`: the order the driver records these decisions in (the parked
 * step's are shuffled by `order`; a later step's go in index order, round 17:
 * the oracle used the parked step's order for both, seed 1508513416).
 *
 * @param  list<int>  $closed
 * @param  list<int>|null  $order
 * @return array{0: list<array<string, mixed>>, 1: bool}
 */
function ppWithoutClosed(array $c, array $calls, array $requests, array $closed, ?array $order = null): array
{
    $taken = [];
    $paired = [];
    foreach ($requests as $k => $request) {
        $want = $calls[$request['call']]['id'];
        $sharing = count(array_filter($calls, fn (array $call): bool => $call['id'] === $want));
        $at = null;
        foreach ($sharing === 1 ? [true, false] : [true] as $waitingOnly) {
            foreach ($calls as $i => $call) {
                if (! isset($taken[$i]) && $call['id'] === $want && (! $waitingOnly || ! in_array($i, $closed, true))) {
                    $at = $i;

                    break 2;
                }
            }
        }
        if ($at !== null) {
            $taken[$at] = true;
        }
        $paired[$k] = $at;
    }
    $hits = fn (int $k): bool => $requests[$k]['verdict'] !== 'none' && $paired[$k] !== null && in_array($paired[$k], $closed, true);
    $order = array_values(array_filter($order ?? $c['order'], fn (int $k): bool => isset($requests[$k])));
    if (count($order) !== count($requests)) {
        $order = array_keys($requests);
    }
    $refused = false;
    foreach ($order as $k) {
        $refused = $refused || $hits($k);
        if ($refused && $c['recorder'] === 'recordApproval') {
            // One at a time: everything from the first refused one on is not recorded.
            $requests[$k] = [...$requests[$k], 'verdict' => 'none', 'reason' => null];
        }
    }
    if ($refused && $c['recorder'] !== 'recordApproval') {
        foreach ($requests as $k => $request) {
            $requests[$k] = [...$request, 'verdict' => 'none', 'reason' => null];
        }
    }

    return [$requests, $refused];
}

/**
 * The host's copy of the parked step (round 15): each gated call again under
 * its id and tool, its arguments altered (token + 500), the requests kept
 * (the same approval ids); with `both`, the original calls too.
 *
 * @return list<array{id: string, tool: string, token: int}>
 */
function ppCopyCalls(array $c): array
{
    $gated = array_values(array_filter($c['calls'], fn (array $call): bool => $call['tool'] === 'echo'));
    $altered = array_map(fn (array $call): array => [...$call, 'token' => $call['token'] + 500], $gated);

    return ($c['copy']['both'] ?? false) ? [...$gated, ...$altered] : $altered;
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
        $forgedOld[$insert['call']] = 'forged:'.$c['calls'][$insert['call']]['token'];
    }
    if (($forge['segment'] ?? null) === 'earlier') {
        $forgedOld[$forge['call']] = 'forged:'.$c['calls'][$forge['call']]['token'];
    }
    $forgedLater = ($forge['segment'] ?? null) === 'later' ? [$forge['call'] => 'forged:'.$later['calls'][$forge['call']]['token']] : [];
    // A later-segment result carrying an EARLIER call's id, tool and
    // arguments answers the later call it matches (SEGMENT OWNERSHIP), never
    // the earlier one: that call keeps its own outcome.
    $cross = $c['cross'] ?? null;
    if ($later !== null && $cross !== null) {
        $answered = [...array_keys(array_filter($later['calls'], fn (array $call): bool => $call['tool'] === 'free')), ...array_keys($forgedLater)];
        $earlierCall = $c['calls'][$cross['call']];
        $target = ppSegmentMatch($later['calls'], $answered, $earlierCall['id'], $earlierCall['tool']);
        if ($target !== null) {
            $forgedLater[$target] = 'forged:'.$earlierCall['token'];
        }
    }

    if (($c['copy'] ?? null) !== null) {
        // The copy replaced the parked step (its open calls: one replaced
        // denial each). Its requests ask for nothing (each approval id is the
        // parked step's), so its calls are denied with no approval, never run.
        $old = ppReplaced($c['calls'], $c['prior'], []);
        $copy = ppDecideStep(ppCopyCalls($c), [], [], []);
        // The copy's own copy of an original call is a call of the COPY: its
        // outcome is keyed apart (token + 1000).
        $rekeyed = [];
        foreach ($copy['outcome'] as $token => $outcome) {
            $rekeyed[$token < 500 ? $token + 1000 : $token] = $outcome;
        }
        $copy['outcome'] = $rekeyed;

        return ['repeated' => $copy['repeated'], 'runs' => [], 'outcome' => $old['outcome'] + $copy['outcome'], 'copy' => true];
    }
    if ($later === null) {
        $decided = ppDecideStep($c['calls'], $c['requests'], $c['prior'], $forgedOld);
        if ($supersedingInsert) {
            $decided = ppReplaced($c['calls'], $c['prior'], $forgedOld) + ['repeated' => $decided['repeated']];
        }

        return ppConcurrently($c, $decided, $c['calls'], $c['prior'], $forgedOld);
    }
    // A later step replaced the parked one; a superseding insert after it
    // replaces the later step too.
    $old = ppReplaced($c['calls'], $c['prior'], $forgedOld);
    $new = $supersedingInsert
        ? ppReplaced($later['calls'], array_keys(array_filter($later['calls'], fn (array $call): bool => $call['tool'] === 'free')), $forgedLater)
        : ppDecideStep($later['calls'], $later['requests'], array_keys(array_filter($later['calls'], fn (array $call): bool => $call['tool'] === 'free')), $forgedLater, laterFree: true);

    $laterPrior = array_keys(array_filter($later['calls'], fn (array $call): bool => $call['tool'] === 'free'));

    return ppConcurrently($c, ['repeated' => $new['repeated'] ?? false, 'runs' => $new['runs'], 'outcome' => $old['outcome'] + $new['outcome']],
        $later['calls'], $laterPrior, $forgedLater);
}

/**
 * The oracle under a CONCURRENT supersede (round 15): a host row recorded
 * while the FIRST approved call runs. That call has its started row, so it is
 * answered-in-progress and keeps its real result; every other open gated call
 * of the step being resolved gets ONE replaced denial and never runs.
 *
 * @param  array{repeated: bool, runs: list<int>, outcome: array<int, string>}  $decided
 * @param  array<int, string>  $forged
 * @return array{repeated: bool, runs: list<int>, outcome: array<int, string>}
 */
function ppConcurrently(array $c, array $decided, array $calls, array $prior, array $forged): array
{
    // A resume a streamDecision batch already ran has no concurrent row.
    if (ppConcurrent($c) === null || $decided['runs'] === [] || ($c['streamed'] ?? false)) {
        return $decided;
    }
    $first = $decided['runs'][0];
    foreach ($calls as $index => $call) {
        if ($call['tool'] === 'echo' && $call['token'] !== $first && ! in_array($index, $prior, true) && ! isset($forged[$index])) {
            $decided['outcome'][$call['token']] = PP_SUPERSEDED;
        }
    }
    $decided['runs'] = [$first];

    return $decided;
}

/**
 * One step decided by its own requests.
 *
 * @param  list<int>  $prior  calls with a result before any decision
 * @param  array<int, string>  $forged  calls a host forged a result for (index => the result)
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
        } elseif (isset($forged[$index])) {
            $outcome[$token] = $forged[$index];
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
 * The call of a step that a result in ITS segment answers when its arguments
 * are no call's of that step: the first call with its id and tool that has
 * no result yet (none, if every such call has one); else the first call with
 * its id that has no result yet; else none.
 *
 * @param  list<int>  $answered  calls that already have a result
 */
function ppSegmentMatch(array $calls, array $answered, string $id, string $tool): ?int
{
    foreach ([fn (array $call): bool => $call['id'] === $id && $call['tool'] === $tool, fn (array $call): bool => $call['id'] === $id] as $matches) {
        $candidates = array_keys(array_filter($calls, $matches));
        if ($candidates !== []) {
            $open = array_values(array_diff($candidates, $answered));

            return $open[0] ?? null;
        }
    }

    return null;
}

/**
 * A superseded step: nothing runs; each gated call with no result of its own
 * gets ONE replaced denial.
 *
 * @param  array<int, string>  $forged  calls a host forged a result for (index => the result)
 * @return array{runs: list<int>, outcome: array<int, string>}
 */
function ppReplaced(array $calls, array $prior, array $forged): array
{
    $outcome = [];
    foreach ($calls as $index => $call) {
        $token = $call['token'];
        $outcome[$token] = match (true) {
            $call['tool'] === 'free' => 'free:'.$token,
            isset($forged[$index]) => $forged[$index],
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

    /**
     * Run once inside the next gated call's body (G-387 round 14, G-404): a
     * host row that would supersede the step while the approved call runs.
     *
     * @var (Closure(): void)|null
     */
    public static ?Closure $during = null;

    /** The number of requests sent before this case (round 17, invariant 8). */
    public static int $sentFrom = 0;

    /** What that host write did, per case: `locked`, or `recorded` when it was let through. */
    public static ?string $concurrent = null;

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
    PpRan::$during = null;
    PpRan::$concurrent = null;
    PpRan::$runs++;
    PpRan::$sentFrom = SentRequests::count();
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
        ->map(fn ($row): array => ['type' => $row->type, ...$row->payload, '__position' => (int) $row->position, '__run' => $row->run_id])->all();
}

/**
 * The segment name each stored row belongs to, by row index: the step whose
 * segment holds it, or, for a row the HARNESS wrote (a run id) whose
 * `harness_answers_step` names an earlier step, that step (round 15).
 *
 * @param  list<array<string, mixed>>  $rows
 * @param  Closure(array<string, mixed>, ?string): string  $classify  a step row => its segment name
 * @return array<int, string|null>
 */
function ppSegments(array $rows, Closure $classify): array
{
    $segment = null;
    $byPosition = [];
    $out = [];
    foreach ($rows as $index => $row) {
        if ($row['type'] === 'assistant' && array_filter(is_array($row['tool_calls'] ?? null) ? $row['tool_calls'] : [], 'is_array') !== []) {
            $segment = $classify($row, $segment);
            $byPosition[$row['__position']] = $segment;
            $out[$index] = null;

            continue;
        }
        $named = is_string($row['__run'] ?? null) && is_int($row['harness_answers_step']['position'] ?? null) ? $row['harness_answers_step']['position'] : null;
        $out[$index] = $named !== null && isset($byPosition[$named]) ? $byPosition[$named] : $segment;
    }

    return $out;
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

/**
 * Every stored result entry after the first step, attributed by SEGMENT
 * OWNERSHIP (round 13b), as [token, result]: an entry counts only for a call
 * of the step whose segment it sits in (the rows after that step's assistant
 * row and before the next assistant row with tool calls). Within a segment
 * it is its call's by exact arguments; an entry whose arguments are no call's
 * of that step is matched by id and tool, then id ({@see ppSegmentMatch()}),
 * or counts for nothing. An entry the harness wrote in the wrong segment is
 * reported as such, so it fails invariant 5.
 *
 * @return list<array{0: int, 1: string}>
 */
function ppOutcomes(array $c): array
{
    $out = [];
    $c = ppNormalized($c);
    $steps = ['first' => $c['calls'], 'later' => $c['later']['calls'] ?? [], 'copy' => ($c['copy'] ?? null) === null ? [] : ppCopyCalls($c), 'other' => []];
    $answered = ['first' => [], 'later' => [], 'copy' => [], 'other' => []];
    $repeats = [];
    $rows = ppRows($c);
    // Which step: the first one, the later step (tokens 200+), the altered
    // copy (tokens 500+, its last call's), or an inserted call (token 900)
    // whose segment holds no call of ours.
    $segments = ppSegments($rows, function (array $row, ?string $segment): string {
        $calls = $row['tool_calls'];
        $arguments = $calls[count($calls) - 1]['arguments'] ?? [];
        $n = (is_string($arguments) ? json_decode($arguments, true) : $arguments)['n'] ?? null;

        return $segment === null ? 'first' : ($n === 900 ? 'other' : ($n >= 500 ? 'copy' : ($n >= 200 ? 'later' : 'other')));
    });
    foreach ($rows as $index => $row) {
        $segment = $segments[$index];
        if ($segment === null || $row['type'] !== 'tool_result') {
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
            $own = array_search($n, array_column($steps[$segment], 'token'), true);
            if ($own !== false) {
                $answered[$segment][] = $own;
                $out[] = [$segment === 'copy' && $n < 500 ? $n + 1000 : $n, (string) $entry['result']];

                continue;
            }
            // Not a call of this segment's step: the test's own later-segment
            // result naming an earlier call answers the later call it matches.
            if ($segment === 'later' && (string) $entry['result'] === 'forged:'.$n) {
                // A resume that records every result of the step again
                // repeats it: the same entry is the same outcome.
                $key = json_encode([$entry['tool_call_id'], $entry['tool_name'], $n]);
                if (isset($repeats[$key])) {
                    continue;
                }
                $repeats[$key] = true;
                $at = ppSegmentMatch($steps['later'], $answered['later'], (string) $entry['tool_call_id'], (string) $entry['tool_name']);
                if ($at !== null) {
                    $answered['later'][] = $at;
                    $out[] = [$steps['later'][$at]['token'], (string) $entry['result']];
                }

                continue;
            }
            $out[] = [$n, 'outside its segment: '.$entry['result']];
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
    unset($c['given'], $c['closed_batch'], $c['closed_later']);
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
    if ($later !== null && ($c['cross'] ?? null) !== null) {
        $thread()->record([$forged($c['calls'][$c['cross']['call']])]);
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
    $copyStep = null;
    if (($c['copy'] ?? null) !== null) {
        // Every request kept, under the same approval ids, now naming the altered calls.
        $copyCalls = ppCopyCalls($c);
        $copyStep = new AssistantMessage('', array_map(fn (array $call): ToolCall => new ToolCall($call['id'], $call['tool'], ['n' => $call['token']]), $copyCalls), [],
            array_map(fn (string $id, int $index): ToolApprovalRequest => new ToolApprovalRequest($id, $copyCalls[$index % count($copyCalls)]['id'], ToolApprovalRequest::ARGUMENTS_AS_SENT),
                $approvalIds, array_keys($approvalIds)));
        if ($c['copy']['when'] === 'before_decision') {
            ppWriteCopy($c, $copyStep, $copyCalls, $approvalIds);
        }
    }
    ppForgedMark($c, $approvalIds);

    // The decisions, in a shuffled order: the parked step's, then a later step's.
    // As GIVEN: a decision on a call that already has an outcome is made,
    // and refused (round 16, item 2).
    $decisions = [];
    foreach ($c['order'] as $index) {
        $request = $c['given']['requests'][$index];
        if ($request['verdict'] !== 'none') {
            $decisions[] = new ToolApprovalResponse($approvalIds[$index], $request['verdict'] === 'approve', $request['reason']);
        }
    }
    $laterDecisions = [];
    foreach ($c['given']['later'] ?? [] as $index => $request) {
        if ($request['verdict'] !== 'none') {
            $laterDecisions[] = new ToolApprovalResponse($laterIds[$index], $request['verdict'] === 'approve', $request['reason']);
        }
    }
    $supersedingBefore = ($inserted !== null && $insert['when'] === 'before_decision' && in_array($insert['kind'], PP_SUPERSEDES, true))
        || ($c['copy']['when'] ?? null) === 'before_decision';
    // A decision for a step superseded BEFORE it is refused with
    // approval_not_pending, all or nothing per batch.
    $batches = [];
    if ($decisions !== []) {
        $batches[] = ['decisions' => $decisions, 'refused' => $later !== null || $supersedingBefore || $c['closed_batch'], 'repeated' => $oracle['repeated'] && $later === null];
    }
    if ($laterDecisions !== []) {
        $batches[] = ['decisions' => $laterDecisions, 'refused' => $supersedingBefore || $c['closed_later'], 'repeated' => $oracle['repeated']];
    }
    $first = null;
    foreach ($batches as $batch) {
        $refused = false;
        try {
            if ($c['recorder'] === 'streamDecision' && $first === null && ! $batch['refused'] && ($insert['when'] ?? null) !== 'after_decision'
                && ($c['copy']['when'] ?? null) !== 'after_decision') {
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
        ppCheck($refused === $batch['refused'], 'G-397: a decision is refused exactly when its step was superseded first, or its call already had an outcome (round 16)', ['refused' => $refused, 'expected' => $batch['refused']]);
    }
    if ($inserted !== null && $insert['when'] === 'after_decision') {
        $thread()->record([$inserted]);
    }
    if ($copyStep !== null && $c['copy']['when'] === 'after_decision') {
        ppWriteCopy($c, $copyStep, ppCopyCalls($c), $approvalIds);
    }
    // A CONCURRENT SUPERSEDE (G-387 round 14, G-404; round 15), derived from
    // the seed so no earlier draw changes: on two cases in three a host
    // records a user row, or a tool-call step, while the first approved call
    // is running. Round 15: it is RECORDED, never refused; the running call
    // has its started row (answered-in-progress) and keeps its one outcome,
    // and the step's other open calls are replaced ({@see ppConcurrently()}).
    if ($first !== null) {
        // The resume ran inside the streamed decision, before any concurrent
        // row could be armed: the oracle without it.
        $c['streamed'] = true;
        $oracle = ppExpected($c);
    }
    if (($kind = ppConcurrent($c)) !== null && $first === null) {
        PpRan::$during = function () use ($c, $kind): void {
            try {
                ppSession($c)->thread()->record($kind === 'user' ? match (ppConcurrentRows($c)) {
                    'user' => [new UserMessage('A host row while a call runs.')],
                    'text' => [new AssistantMessage('A host note while a call runs.')],
                    default => [new UserMessage('A host row while a call runs.'), new AssistantMessage('A host note while a call runs.')],
                } : [new AssistantMessage('', [new ToolCall('host', 'free', ['n' => 900])])]);
                PpRan::$concurrent = 'recorded';
            } catch (SessionLocked) {
                PpRan::$concurrent = 'locked';
            }
        };
    }
    $first ??= ppTurn($c, $c['resume'], '');
    PpRan::$during = null;
    ppCheck(PpRan::$concurrent === null || PpRan::$concurrent === 'recorded',
        'round 15: a superseding host write while an approved call runs is recorded, never refused', PpRan::$concurrent);
    $superseded = $later !== null || ($insert !== null && in_array($insert['kind'], PP_SUPERSEDES, true)) || $copyStep !== null
        || PpRan::$concurrent === 'recorded';

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
    // Round 15: a call runs only under a request whose id, tool and argument
    // digest match its own: never an altered copy's call (tokens 500+).
    ppCheck(array_filter($ran, fn (int $token): bool => $token >= 500 && $token < 900) === [],
        '2b: a call runs only under its own request (id, tool and arguments)', $ran);
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
        if (PpRan::$concurrent === null && $copyStep === null) {
            ppCheck(count($results) === count($open), '6: one tool_result step per open gated call', ['steps' => count($results), 'open' => count($open)]);
        }
    }

    if (($c['copy']['raw'] ?? null) === null) {
        ppSettledOutcomes($c, $oracle, '5');
    }
    ppReplayAnswersEveryCall($c, '5 (replay)');

    // Repeat resumes, with later decisions in between: nothing runs twice, no
    // outcome changes, and no resume adds a result entry (round 15).
    $before = PpRan::$echo;
    $entriesBefore = ppEntryCount($c);
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
    ppCheck(ppEntryCount($c) === $entriesBefore, 'round 15: a repeat resume writes no result entry', ['before' => $entriesBefore, 'after' => ppEntryCount($c)]);
    if ($c['source'] === 'stored') {
        ppCheck(PpRan::$free === [], 'a stored ungated call with a result never runs', PpRan::$free);
    } else {
        // The parked step's ungated calls ran when it was taken; a later
        // step another writer recorded already holds its results.
        ppCheck(count(PpRan::$free) === count($frees), '3: an ungated call runs once', PpRan::$free);
    }
    if (($c['copy']['raw'] ?? null) === null) {
        ppSettledOutcomes($c, $oracle, '5 (after repeat resumes)');
    }
    ppReplayAnswersEveryCall($c, '5 (replay, after repeat resumes)');
    ppReplayOnce($c);

    // Invariant 8 (round 17, item 1): every request the provider was SENT
    // answers every call of every step it carries, exactly once, in that
    // step's own result run (the multiset; ids as generated, an empty one
    // included). One more turn re-reads the stored thread, so a result
    // stored after a row written while its call ran is sent too.
    try {
        ppTurn($c, 'send', 'One more.');
    } catch (Throwable) {
        // A refusal sends nothing; the requests already sent are checked.
    }
    ppCheck(SentRequests::since(PpRan::$sentFrom, ids: false) === [], '8: every sent request answers every call in its own step',
        ['problems' => SentRequests::since(PpRan::$sentFrom, ids: false), 'requests' => array_map(fn (int $k): string => $k.': '.SentRequests::outline($k), SentRequests::invalid(PpRan::$sentFrom, ids: false))]);
}

/**
 * Store the host's copy of the parked step (round 15): through record(), or
 * RAW (round 16) as another writer with table access can, its tool_calls a
 * JSON string, non-objects mixed with the copy's calls, or the objects.
 *
 * @param  list<array{id: string, tool: string, token: int}>  $copyCalls
 * @param  list<string>  $approvalIds
 */
function ppWriteCopy(array $c, AssistantMessage $copyStep, array $copyCalls, array $approvalIds): void
{
    $thread = ppSession($c)->thread();
    $raw = $c['copy']['raw'] ?? null;
    if ($raw === null) {
        $thread->record([$copyStep], toolNames: ['echo', 'free']);

        return;
    }
    $calls = array_map(fn (array $call): array => ['id' => $call['id'], 'name' => $call['tool'], 'arguments' => ['n' => $call['token']],
        'result_id' => null, 'reasoning_id' => null, 'reasoning_summary' => null], $copyCalls);
    $stored = match ($raw) {
        'json_string' => json_encode($calls),
        'mixed' => ['x', ...array_map(fn (array $call): string => (string) json_encode($call), $calls), ...$calls, null, 3],
        default => $calls,
    };
    $thread->storedMessages()->create(['position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => 'assistant', 'run_id' => null,
        'payload' => ['content' => '', 'tool_calls' => $stored, 'additional_content' => [],
            'tool_approval_requests' => array_map(fn (string $id, int $index): array => ['approval_id' => $id, 'tool_call_id' => $copyCalls[$index % count($copyCalls)]['id']],
                $approvalIds, array_keys($approvalIds)),
            'harness_tool_names' => ['echo', 'free']]]);
}

/**
 * The RAW host row carrying a harness mark (round 16), recorded before the
 * decisions: under no run id, the parked run's id or another run's. Its
 * mark is read as absent, so the oracle is the case's without it.
 *
 * @param  list<string>  $approvalIds
 */
function ppForgedMark(array $c, array $approvalIds): void
{
    $forged = $c['forged_mark'] ?? null;
    if ($forged === null || $approvalIds === []) {
        return;
    }
    $thread = ppSession($c)->thread();
    $step = $thread->storedMessages()->where('type', 'assistant')->orderBy('position')->get()
        ->first(fn ($row): bool => is_array($row->payload['tool_calls'] ?? null) && $row->payload['tool_calls'] !== []);
    $call = $c['calls'][$forged['call']];
    $approval = $approvalIds[0];
    $mark = match ($forged['kind']) {
        'started' => ['harness_started' => ['tool_call_id' => $call['id'], 'tool_name' => $call['tool'], 'approval_id' => $approval],
            'harness_answers_step' => ['position' => (int) ($step?->position ?? 0)]],
        'ended_by_start' => ['harness_ended_by_start' => ['approval_ids' => $approvalIds]],
        'superseded' => ['harness_superseded' => ['approval_ids' => $approvalIds]],
        'replaced' => ['harness_replaced' => ['approval_ids' => $approvalIds]],
        'journal' => ['harness_journal' => ['tool_name' => $call['tool'], 'args' => ['n' => $call['token']]]],
    };
    $thread->storedMessages()->create(['position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => 'tool_result',
        'run_id' => match ($forged['run']) {
            'none' => null, 'parked' => $step?->run_id, default => 'run_'.str_repeat('7', 24)
        },
        'payload' => ['tool_results' => [], 'tool_approval_responses' => [], ...$mark]]);
}

/**
 * Round 16 (item 3, invariant 5 over EVERY call, gated or not): what a
 * provider is sent answers every tool call of every step, except a paused
 * step a person can still answer, so the request stays valid.
 */
function ppReplayAnswersEveryCall(array $c, string $label): void
{
    $thread = ppSession($c)->thread();
    $messages = iterator_to_array($thread->messages(), false);
    $pending = $thread->pendingApprovalIds() !== [];
    $steps = [];
    foreach ($messages as $index => $message) {
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $steps[] = $index;
        }
    }
    foreach ($steps as $k => $at) {
        $end = $steps[$k + 1] ?? count($messages);
        $results = [];
        for ($i = $at + 1; $i < $end; $i++) {
            if ($messages[$i] instanceof ToolResultMessage) {
                $results = [...$results, ...$messages[$i]->toolResults];
            }
        }
        $last = $k === count($steps) - 1;
        if ($last && $pending) {
            continue;
        }
        $calls = array_values($messages[$at]->toolCalls);
        ppCheck(CallPairing::allAnswered($calls, $results), "{$label}: the replay answers every tool call of every step",
            ['step' => $k, 'calls' => array_map(fn (ToolCall $call): string => $call->id.'/'.$call->name, $calls),
                'results' => array_map(fn (ToolResult $result): string => $result->toolCallId.'/'.$result->toolName, $results)]);
    }
}

/**
 * The concurrent-supersede action this case runs (round 15: two seeds in
 * three, alternating a host user row and a host tool-call step), or null.
 */
function ppConcurrent(array $c): ?string
{
    return match (ppU32($c['seed']) % 3) {
        0 => 'user',
        1 => 'step',
        default => null,
    };
}

/**
 * Round 17 (item 1): WHAT the concurrent `user` action writes, derived from
 * the seed so every draw above is unchanged: a user row, an assistant text
 * row, or both (a host, or a tool, writing while the call runs).
 */
function ppConcurrentRows(array $c): string
{
    // Not a fresh stream's first draw: that is tied to the seed's residue
    // mod 3, which already chose `user`. The next base-3 digit is not.
    return ['user', 'text', 'user_text'][intdiv(ppU32($c['seed']), 3) % 3];
}

/** Every stored tool result entry, counted. */
function ppEntryCount(array $c): int
{
    $count = 0;
    foreach (ppRows($c) as $row) {
        $count += $row['type'] === 'tool_result' ? count($row['tool_results'] ?? []) : 0;
    }

    return $count;
}

/** The replay key of one result: its segment, call id, tool name and canonical arguments. */
function ppReplayKey(int $segment, string $id, string $tool, mixed $args): string
{
    return $segment.'|'.strlen($id).':'.$id.'|'.strlen($tool).':'.$tool.'|'.CanonicalJson::digest(is_array($args) ? $args : []);
}

/**
 * Invariant 7 (G-387 round 14, G-403 and item 5): the replay a provider is
 * sent keeps EVERY stored result, and emits none twice, each in its own
 * segment (the rows after its step and before the next step with tool
 * calls). A stored row may carry results an earlier row of the segment
 * already holds (a resolution repeats the step's results): what the replay
 * owes a key is the most any one row of the segment holds of it.
 */
function ppReplayOnce(array $c): void
{
    $owed = [];
    $rows = ppRows($c);
    $count = -1;
    $steps = [];
    $segments = ppSegments($rows, function (array $row) use (&$count, &$steps): string {
        $steps[++$count] = $row;

        return (string) $count;
    });
    // Each segment's stored results, the most any one row holds of a key, in
    // first-seen order.
    $entries = [];
    $kept = [];
    foreach ($rows as $index => $row) {
        $segment = $segments[$index] === null ? -1 : (int) $segments[$index];
        if ($segment < 0 || $row['type'] !== 'tool_result') {
            continue;
        }
        $inRow = [];
        foreach ($row['tool_results'] ?? [] as $entry) {
            $key = ppReplayKey($segment, (string) $entry['tool_call_id'], (string) $entry['tool_name'], $entry['args'] ?? []);
            $inRow[$key] = ($inRow[$key] ?? 0) + 1;
            if ($inRow[$key] > count(array_keys($entries[$segment] ?? [], $key, true))) {
                $entries[$segment][] = $key;
                $kept[$segment][] = $entry;
            }
        }
    }
    // Round 17: what the replay OWES is every stored result that answers a
    // call of its segment's own step, each call once (the pairing the
    // harness reads outcomes by); a result answering no call of its step is
    // never sent, since no provider accepts it.
    foreach ($entries as $segment => $keys) {
        $calls = CallPairing::ofStored(array_values(array_filter($steps[$segment]['tool_calls'] ?? [], 'is_array')), 'id', 'name');
        $takers = CallPairing::takers($calls, CallPairing::ofStored($kept[$segment], 'tool_call_id', 'tool_name'));
        foreach ($keys as $k => $key) {
            if ($takers[$k] !== null) {
                $owed[$key] = ($owed[$key] ?? 0) + 1;
            }
        }
    }
    $stored = $owed;
    $shown = [];
    $segment = -1;
    foreach (ppSession($c)->thread()->messages() as $message) {
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $segment++;
        }
        if ($message instanceof ToolResultMessage && $segment >= 0) {
            foreach ($message->toolResults as $result) {
                $key = ppReplayKey($segment, $result->toolCallId, $result->toolName, $result->args);
                // The outcome the replay gives a call that has none stored
                // (round 16, item 3: replaced or interrupted) is not a stored
                // result emitted again.
                if (! isset($stored[$key]) && in_array((string) $result->result, [PP_SUPERSEDED, 'Tool call was interrupted, it may have run'], true)) {
                    continue;
                }
                $shown[$key] = ($shown[$key] ?? 0) + 1;
            }
        }
    }
    ksort($stored);
    ksort($shown);
    ppCheck($shown === $stored, '7: the replay emits every stored result that answers a call of its step exactly once, in its own segment', ['owed' => $stored, 'shown' => $shown]);
}

/** Invariants 5 and 6 on the thread rows: every call has exactly one outcome, the oracle's. */
function ppSettledOutcomes(array $c, array $oracle, string $label): void
{
    // A MULTISET (round 15): every entry counts, so the same outcome written
    // twice is two outcomes. A token is carried by as many calls as the step
    // holds identical copies of it, and each has exactly one.
    $seen = [];
    foreach (ppOutcomes($c) as [$token, $result]) {
        $seen[$token][] = $result;
    }
    $normal = ppNormalized($c);
    $carriers = array_count_values([...array_column([...$normal['calls'], ...($normal['later']['calls'] ?? [])], 'token'),
        ...array_map(fn (array $call): int => $call['token'] < 500 ? $call['token'] + 1000 : $call['token'], ($normal['copy'] ?? null) === null ? [] : ppCopyCalls($normal))]);
    foreach ($oracle['outcome'] as $token => $want) {
        $got = $seen[$token] ?? [];
        ppCheck(count($got) === ($carriers[$token] ?? 1), "{$label}: every call gets exactly one recorded outcome", ['token' => $token, 'got' => $got]);
        ppCheck(array_unique($got) === [$want], '6: the recorded outcome matches what ran', ['token' => $token, 'got' => $got, 'want' => $want]);
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
        'cross' => ($c['cross'] ?? null) === null || $c['cross']['call'] === $drop ? null : ['call' => $remap($c['cross']['call'])],
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
    foreach (['insert', 'later', 'forge', 'cross', 'repeats'] as $key) {
        if (($c[$key] ?? null) !== null && $c[$key] !== []) {
            $out[] = [...$c, $key => $key === 'repeats' ? [] : null, ...($key === 'later' ? ['forge' => null, 'cross' => null] : [])];
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
                if (PpRan::$during !== null) {
                    $during = PpRan::$during;
                    PpRan::$during = null;
                    $during();
                }

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
        unset($php['insert'], $php['later'], $php['forge'], $php['cross'], $php['repeats'], $php['twin'], $php['copy'], $php['forged_mark']);
        expect(json_encode($php))->toBe($json);
    }
    expect(array_map(fn (int $index): int => ppCaseSeed(0x6392, $index), [0, 1, 2, 149]))->toBe([2483776628, 2972160347, 2366528728, 3841045332]);
});

it('holds for the seeds that broke the rule on TS main', function (int $seed): void {
    ppHolds([$seed]);
    expect(true)->toBeTrue();
})->with(PP_REGRESSION_SEEDS);

// Round 17: seeds a wider run (400 from base 9000) found the ORACLE wrong
// at (a later step's decisions read in the parked step's order), kept as
// fixed cases.
const PP_ROUND17_SEEDS = [1508513416];

it('holds for the seeds a wider run found the oracle wrong at (round 17)', function (int $seed): void {
    ppHolds([$seed]);
    expect(true)->toBeTrue();
})->with(PP_ROUND17_SEEDS);

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
    // SEGMENT OWNERSHIP: a later-segment result naming an earlier call that
    // answers a later same-id call, one that answers a later APPROVED call,
    // one that answers nothing, each also with an inserted row.
    $crossTarget = function (array $c): ?int {
        if ($c['later'] === null || $c['cross'] === null) {
            return null;
        }
        $forgedLater = ($c['forge']['segment'] ?? null) === 'later' ? [$c['forge']['call']] : [];
        $answered = [...array_keys(array_filter($c['later']['calls'], fn (array $call): bool => $call['tool'] === 'free')), ...$forgedLater];
        $call = $c['calls'][$c['cross']['call']];

        return ppSegmentMatch($c['later']['calls'], $answered, $call['id'], $call['tool']);
    };
    $approvedLater = fn (array $c, int $index): bool => array_filter($c['later']['requests'], fn (array $q): bool => $q['call'] === $index && $q['verdict'] === 'approve') !== [];
    foreach ([
        'answers a later call' => fn (array $c): bool => $crossTarget($c) !== null,
        'answers a later approved call' => fn (array $c): bool => ($t = $crossTarget($c)) !== null && $approvedLater($c, $t),
        'answers nothing' => fn (array $c): bool => $c['cross'] !== null && $c['later'] !== null && $crossTarget($c) === null,
    ] as $label => $shape) {
        if (! $has($shape)) {
            $missing[] = "later-segment result naming an earlier call that {$label}";
        }
        if (! $has(fn (array $c): bool => $shape($c) && $c['insert'] !== null)) {
            $missing[] = "later-segment result naming an earlier call that {$label} + insert";
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
    // G-387 round 14: the concurrent supersede reaches an approved call that runs.
    foreach (['user', 'step'] as $kind) {
        if (! $has(fn (array $c): bool => ppConcurrent($c) === $kind && ppExpected(ppNormalized($c))['runs'] !== [])) {
            $missing[] = "a concurrent supersede ({$kind}) while an approved call runs";
        }
    }
    // Round 17: each row the concurrent `user` action writes, while an approved call runs.
    foreach (['user', 'text', 'user_text'] as $rows) {
        if (! $has(fn (array $c): bool => ppConcurrent($c) === 'user' && ppConcurrentRows($c) === $rows && ppExpected(ppNormalized($c))['runs'] !== [])) {
            $missing[] = "a concurrent {$rows} row while an approved call runs";
        }
    }
    // Round 15: identical duplicate calls, and an altered copy of the parked
    // step in both orders and with both calls.
    if (! $has(fn (array $c): bool => $c['twin'] !== null)) {
        $missing[] = 'identical duplicate calls';
    }
    foreach (['before_decision', 'after_decision'] as $when) {
        if (! $has(fn (array $c): bool => ($c['copy']['when'] ?? null) === $when && array_filter($c['requests'], fn (array $q): bool => $q['verdict'] === 'approve') !== [])) {
            $missing[] = "an altered copy {$when} with an approve";
        }
    }
    if (! $has(fn (array $c): bool => $c['copy']['both'] ?? false)) {
        $missing[] = 'a copy holding the old and the altered calls';
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
