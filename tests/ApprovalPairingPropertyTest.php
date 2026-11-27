<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentResponse;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
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

// G-392 QC: PROPERTY TESTS for approval pairing.
//
// Each example is a generated conversation driven through the session API a
// host uses (send / stream to park, decide / approve / streamDecision / a new
// message to resume, then repeat resumes over the history), against a faked
// provider, with no network. The expected outcome comes from a small model of
// the rule, written here independently of the code under test:
//
//   A step whose tool call ids repeat (an empty id is an id), or whose
//   approval requests repeat a tool_call_id, runs NONE of its gated calls,
//   whatever was decided. A step with distinct ids is decided per call: a
//   call runs only when ITS OWN request was approved; no decision is a
//   denial. A call that already has a result never runs again.
//
// Every call carries a unique token in its arguments, so a run is attributed
// to the call that made it even when ids repeat.
//
// Between a park and its decision the host sometimes records a row of its
// own (an assistant note, a user message, a system row, a tool result row):
// none of them changes which step a decision belongs to (G-392/G-397).
//
// PRISM_PROPERTY_EXAMPLES sets the number of examples (a small fixed set by
// default). PRISM_PROPERTY_BASE_SEED moves the base seed. PRISM_PROPERTY_SEED
// replays one example seed, which a failure prints with its case.

/** A deterministic xorshift32 generator: no third-party code. */
final class PairingPropertyRng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;

        return $this->state = $x & 0xFFFFFFFF;
    }

    public function int(int $n): int
    {
        return $this->next() % $n;
    }

    public function chance(float $p): bool
    {
        return $this->next() / 4294967296 < $p;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(count($items))];
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
            $j = $this->int($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}

/** Shared state between the faked provider, the tools and the driver. */
final class PairingPropertyWorld
{
    /** @var list<array{kind: string, step?: int, calls?: list<array{id: string, gated: bool, token: string}>}> */
    public static array $queue = [];

    /** @var list<int> the steps served, in order */
    public static array $served = [];

    /** @var list<string> tokens, in the order their calls ran */
    public static array $ran = [];

    public static int $item = 0;

    /** @var list<array<string, mixed>> the body of every provider request sent */
    public static array $requests = [];

    public static string $provider = 'anthropic';

    public static int $participant = 0;
}

const PAIRING_REPEATED_REASON = 'Tool call was not run because another call in this step has the same id';

const PAIRING_REPLACED_REASON = 'Tool call was not run because a later step replaced it';

/**
 * G-401: which recorded decision for an approval counts. `first`: the first
 * one. `deny`: any denial is final, otherwise the first approval.
 */
const PAIRING_DECISION_RULE = 'deny';

/** The fixed-seed set every CI run checks, and the coverage test reads. */
const PAIRING_FAST_EXAMPLES = 160;

const PAIRING_ID_POOL = ['a', 'b', 'c', ''];

const PAIRING_DISTINCT_POOL = ['a', 'b', 'c', 'd', 'e', 'f', ''];

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    // `phantom` is listed and never registered (G-412).
    config()->set('prism-harness.agent.modes.chat.tools', ['gated', 'free', 'phantom']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['gated']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 8);

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('gated')->for('Gated.')->withStringParameter('token', 'Token')
            ->using(function (string $token): string {
                PairingPropertyWorld::$ran[] = $token;

                return 'ran:'.$token;
            }),
        (new Tool)->as('free')->for('Free.')->withStringParameter('token', 'Token')
            ->using(function (string $token): string {
                PairingPropertyWorld::$ran[] = $token;

                return 'ran:'.$token;
            }),
    ]);

    Http::fake(['*' => function (HttpRequest $request) {
        $sent = json_decode((string) $request->body(), true);
        PairingPropertyWorld::$requests[] = is_array($sent) ? $sent : [];
        $next = array_shift(PairingPropertyWorld::$queue) ?? ['kind' => 'text'];
        if (PairingPropertyWorld::$provider === 'openai') {
            if ($next['kind'] === 'calls') {
                PairingPropertyWorld::$served[] = $next['step'];
            }
            $output = $next['kind'] === 'calls'
                // A reasoning model: a reasoning item, then the calls. The
                // call's id is both the item id and the call_id, so it is
                // the id the harness reads, as on the other provider.
                ? [['id' => 'rs_'.(++PairingPropertyWorld::$item), 'type' => 'reasoning', 'summary' => []],
                    ...array_map(fn (array $call): array => ['id' => $call['id'], 'type' => 'function_call', 'status' => 'completed',
                        'arguments' => json_encode(['token' => $call['token']]), 'call_id' => $call['id'], 'name' => $call['gated'] ? 'gated' : 'free'], $next['calls'])]
                : [['id' => 'msg_'.(++PairingPropertyWorld::$item), 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => '{"ok":true}', 'annotations' => []]]]];

            return Http::response(['id' => 'resp_'.(++PairingPropertyWorld::$item), 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
                'output' => $output, 'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2]]);
        }
        $content = [['type' => 'text', 'text' => '{"ok":true}']];
        if ($next['kind'] === 'calls') {
            PairingPropertyWorld::$served[] = $next['step'];
            $content = array_map(fn (array $call): array => [
                'type' => 'tool_use', 'id' => $call['id'], 'name' => $call['gated'] ? 'gated' : 'free', 'input' => ['token' => $call['token']],
            ], $next['calls']);
        }
        $stop = $next['kind'] === 'calls' ? 'tool_use' : 'end_turn';

        if ((json_decode((string) $request->body(), true)['stream'] ?? false) === true) {
            return Http::response(pairingSse($content, $stop), 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response(['id' => 'msg_'.(++PairingPropertyWorld::$item), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    }]);
});

/**
 * The same answer as the Messages API streams it.
 *
 * @param  list<array<string, mixed>>  $content
 */
function pairingSse(array $content, string $stop): string
{
    $events = [['message_start', ['message' => ['id' => 'msg_'.(++PairingPropertyWorld::$item), 'type' => 'message', 'role' => 'assistant',
        'model' => 'claude-sonnet-5-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]]];
    foreach ($content as $index => $block) {
        if ($block['type'] === 'tool_use') {
            $events[] = ['content_block_start', ['index' => $index, 'content_block' => [...$block, 'input' => (object) []]]];
            $events[] = ['content_block_delta', ['index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'])]]];
        } else {
            $events[] = ['content_block_start', ['index' => $index, 'content_block' => ['type' => 'text', 'text' => '']]];
            $events[] = ['content_block_delta', ['index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]]];
        }
        $events[] = ['content_block_stop', ['index' => $index]];
    }
    $events[] = ['message_delta', ['delta' => ['stop_reason' => $stop, 'stop_sequence' => null], 'usage' => ['output_tokens' => 1]]];
    $events[] = ['message_stop', []];

    $out = '';
    foreach ($events as [$type, $data]) {
        $out .= "event: {$type}\ndata: ".json_encode(['type' => $type, ...$data])."\n\n";
    }

    return $out;
}

/**
 * One generated case.
 *
 * @return array<string, mixed>
 */
function pairingGenerate(int $seed): array
{
    return pairingWithProvider(pairingGenerateBase($seed), $seed);
}

/**
 * G-415: the provider a case runs against, and a host note carrying the
 * parked step's provider content (in place of a plain note), from a
 * generator of their own so every
 * seed's case above is what it was before. OpenAI cases are driven without
 * streams (the faked Responses API answers in JSON), and record no system
 * row (the oracle's system-row refusal is the faked Anthropic API's).
 *
 * @param  array<string, mixed>  $case
 * @return array<string, mixed>
 */
function pairingWithProvider(array $case, int $seed): array
{
    $g = new PairingPropertyRng(($seed ^ 0x415415) & 0xFFFFFFFF);
    $case['provider'] = $g->chance(0.3) ? 'openai' : 'anthropic';
    // Some assistant notes carry the step's provider content, and some host
    // copies with a call of their own carry it too.
    if (($case['insert']['kind'] ?? null) === 'assistant_text' && $g->chance(0.4)) {
        $case['insert']['kind'] = 'assistant_content';
    }
    if (($case['insert']['kind'] ?? null) === 'assistant_call' && $g->chance(0.4)) {
        $case['insert']['kind'] = 'assistant_mismatch';
    }
    // A host step with an approval request of its own, recorded without its
    // tool offer (G-412).
    if (($case['insert']['kind'] ?? null) === 'assistant_call' && $g->chance(0.5)) {
        $case['insert']['kind'] = 'assistant_request';
    }
    // Its request for a tool that takes no approval, or for a listed tool
    // that is not registered (G-412).
    if (($case['insert']['kind'] ?? null) === 'assistant_request') {
        $case['insert']['tool'] = ['gated', 'free', 'phantom'][($seed >> 1) % 3];
    }
    if ($case['provider'] === 'openai') {
        $case['entry'] = 'send';
        foreach ($case['steps'] as $s => $step) {
            if ($step['resume'] === 'streamDecision') {
                $case['steps'][$s]['resume'] = 'decide';
            }
        }
        if (($case['recorded']['resume'] ?? null) === 'streamDecision') {
            $case['recorded']['resume'] = 'decide';
        }
        $case['repeats'] = array_map(fn (string $repeat): string => $repeat === 'stream' ? 'send' : $repeat, $case['repeats']);
        if (($case['insert']['kind'] ?? null) === 'system') {
            $case['insert']['kind'] = 'assistant_content';
        }
    }

    return $case;
}

/** @return array<string, mixed> */
function pairingGenerateBase(int $seed): array
{
    $rng = new PairingPropertyRng($seed);
    $steps = [];
    $count = $rng->chance(0.3) ? 2 : 1;
    for ($s = 0; $s < $count; $s++) {
        $n = 1 + $rng->int(6);
        $distinct = $rng->chance(0.4);
        $ids = $distinct ? array_slice($rng->shuffle(PAIRING_DISTINCT_POOL), 0, $n) : null;
        $calls = [];
        for ($k = 0; $k < $n; $k++) {
            $calls[] = ['id' => $ids !== null ? $ids[$k] : $rng->pick(PAIRING_ID_POOL), 'gated' => $rng->chance(0.65), 'token' => "t{$s}{$k}"];
        }
        $decisions = [];
        foreach ($calls as $k => $call) {
            if ($call['gated']) {
                $decisions[$k] = $rng->pick(['approve', 'approve', 'deny', 'none']);
            }
        }
        // The order the person submits decisions in, by gated call index,
        // some of them twice.
        $order = [];
        foreach ($rng->shuffle(array_keys(array_filter($decisions, fn (string $d): bool => $d !== 'none'))) as $k) {
            $order[] = $k;
            if ($rng->chance(0.2)) {
                $order[] = $k;
            }
        }
        $steps[] = [
            'calls' => $calls,
            'decisions' => $decisions,
            'order' => $order,
            'resume' => $rng->pick(['decide', 'decide', 'streamDecision', 'streamDecision', 'approveOne', 'newMessage']),
        ];
    }
    $repeats = [];
    for ($r = $rng->int(3); $r > 0; $r--) {
        $repeats[] = $rng->pick(['send', 'stream', 'structured', 'redecide', 'lateApprove']);
    }

    $case = ['entry' => $rng->pick(['send', 'stream']), 'steps' => $steps, 'repeats' => $repeats, 'mode' => 'normal', 'insert' => null];

    // G-397 / G-399 shapes, from a generator of their own, so every seed's
    // case above is what it was before they were added.
    $g = new PairingPropertyRng(($seed ^ 0x397397) & 0xFFFFFFFF);
    $gated = array_keys(array_filter($steps[0]['calls'], fn (array $call): bool => $call['gated']));
    $mode = $g->int(20);
    if ($gated !== [] && $mode < 8) {
        // A later tool-call step is recorded onto the thread while the first
        // is parked, as a host can: the first is replaced. The later step's
        // approval requests sometimes repeat an approval id.
        $case['mode'] = 'replaced';
        $case['steps'] = [$steps[0]];
        $n = 1 + $g->int(3);
        $calls = [];
        $approvals = [];
        $decisions = [];
        for ($k = 0; $k < $n; $k++) {
            $calls[] = ['id' => $g->pick(PAIRING_ID_POOL), 'gated' => true, 'token' => "r{$k}"];
            $approvals[] = "apr_r{$k}";
            $decisions[$k] = $g->pick(['approve', 'approve', 'deny', 'none']);
        }
        if ($n > 1 && $g->chance(0.5)) {
            $approvals[$n - 1] = $approvals[0];
        }
        // One batch: decisions for the replaced step's calls and the later
        // step's, mixed.
        $mix = [];
        foreach ($steps[0]['order'] as $k) {
            $mix[] = ['old', $k];
        }
        foreach (array_keys(array_filter($decisions, fn (string $d): bool => $d !== 'none')) as $k) {
            $mix[] = ['new', $k];
        }
        $case['recorded'] = ['calls' => $calls, 'approvals' => $approvals, 'decisions' => $decisions, 'mix' => $g->shuffle($mix),
            'resume' => $g->pick(['decide', 'streamDecision', 'approveOne'])];
        // A row the host records too: in the replaced step's segment (before
        // the later step) or the later step's, before the decision or after
        // the resume.
        $kind = $g->pick(['assistant_text', 'assistant_call', 'user', 'system', 'tool_empty', 'tool_result']);
        $case['insert'] = ['kind' => $kind, 'when' => $g->chance(0.6) ? 'before_decision' : 'after_decision',
            'segment' => $g->chance(0.5) ? 'earlier' : 'later',
            'call' => $kind === 'tool_result' ? $g->pick($gated) : $g->int(count($steps[0]['calls']))];
        // The later step's calls stored with their arguments as a JSON
        // string, as other providers' rows hold them; and sometimes its first
        // call copies the id and arguments of an earlier call that already
        // ran (an ungated one).
        $case['recorded']['stringArgs'] = $g->chance(0.5);
        $ungated = array_keys(array_filter($steps[0]['calls'], fn (array $call): bool => ! $call['gated']));
        if ($ungated !== [] && $g->chance(0.5)) {
            $u = $steps[0]['calls'][$g->pick($ungated)];
            $case['recorded']['calls'][0] = ['id' => $u['id'], 'gated' => true, 'token' => $u['token'], 'copy' => true];
        }
        // A host copy of the parked step: its first call is asked for under
        // the FIRST step's approval id. That approval belongs to the first
        // step, so it decides nothing here.
        $case['recorded']['reuseApproval'] = $g->chance(0.3);

        return $case;
    }
    $spent = $gated !== [] && $mode < 10;
    if ($gated !== [] && ($spent || $g->chance(0.6))) {
        // A row the host records after the park: before the decision, or
        // after the resume that acted on it (then repeat resumes).
        $kind = $g->pick(['assistant_text', 'assistant_call', 'user', 'system', 'system', 'tool_empty', 'tool_result']);
        $case['insert'] = ['kind' => $kind, 'when' => $g->chance(0.5) ? 'before_decision' : 'after_decision',
            'call' => $kind === 'tool_result' ? $g->pick($gated) : $g->int(count($steps[0]['calls']))];
    }
    if ($spent) {
        // The run's steps are spent when it resumes: no assistant row
        // follows the park; then repeat resumes (G-399).
        $case['mode'] = 'spent';
        $case['steps'] = [$steps[0]];
        $case['repeats'] = $repeats === [] ? [$g->pick(['send', 'stream', 'redecide', 'lateApprove'])] : $repeats;
    }
    // G-401: two decision rows already in the thread for one call, as two
    // writers leave them; and a late denial among the repeat resumes.
    $case['pair'] = $gated !== [] && $g->chance(0.3) ? ['call' => $g->pick($gated), 'first' => $g->chance(0.5)] : null;
    if ($g->chance(0.3)) {
        $case['repeats'][] = 'lateDeny';
    }
    // Two IDENTICAL gated calls (same id, tool and arguments): a copy of one
    // of the first step's gated calls, right after it.
    if ($gated !== [] && $g->chance(0.2)) {
        $case = pairingWithTwin($case, $g->pick($gated));
    }

    return $case;
}

/** `$case` with an identical copy of the first step's gated call `$k` right after it. */
function pairingWithTwin(array $case, int $k): array
{
    $step = $case['steps'][0];
    $shift = fn (int $i): int => $i > $k ? $i + 1 : $i;
    $calls = [];
    foreach ($step['calls'] as $i => $call) {
        $calls[] = $call;
        if ($i === $k) {
            $calls[] = $call;
        }
    }
    $decisions = [];
    foreach ($step['decisions'] as $i => $decision) {
        $decisions[$shift($i)] = $decision;
    }
    $decisions[$k + 1] = $step['decisions'][$k];
    ksort($decisions);
    $case['steps'][0] = [...$step, 'calls' => $calls, 'decisions' => $decisions, 'order' => array_map($shift, $step['order'])];
    if (($case['insert'] ?? null) !== null) {
        $case['insert']['call'] = $shift($case['insert']['call']);
    }
    if (($case['pair'] ?? null) !== null) {
        $case['pair']['call'] = $shift($case['pair']['call']);
    }
    $case['twin'] = $k;

    return $case;
}

/**
 * The decisions that count for step `$s`'s calls: the resume's, and for the
 * call a decision pair names, the pair's under the G-401 rule (a decision the
 * person sends for it later is recorded as nothing).
 *
 * @return array<int, string>
 */
function pairingEffectiveFor(array $case, int $s): array
{
    $effective = pairingEffectiveDecisions($case['steps'][$s]);
    if ($s === 0 && ($case['pair'] ?? null) !== null) {
        $effective[$case['pair']['call']] = PAIRING_DECISION_RULE === 'first' && $case['pair']['first'] ? 'approve' : 'deny';
    }

    return $effective;
}

/**
 * THE ORACLE: which tokens run, from the case alone.
 *
 * @param  array<string, mixed>  $case
 * @return array{ran: list<string>, steps: list<array{repeated: bool, runs: array<int, bool>}>}
 */
function pairingOracle(array $case): array
{
    $ran = [];
    $steps = [];
    // The faked provider (Anthropic) refuses a conversation that holds a
    // system row: once one is recorded, no later step is served, and in that
    // step a call ITS OWN approval approved may or may not have run before
    // the refusal (the decision-stream path resolves approvals first). Any
    // other gated call must not run.
    $refused = false;
    $may = [];
    // A host step with an approval request recorded after the first park,
    // before its decision, without its tool offer, is offered only the tool its request names
    // (G-412). The resume that resolves it, and every step after it, keep
    // that offer: a later call of another tool is not offered, so it never
    // runs.
    $hostScope = pairingInsertBefore($case) === 'assistant_request' && ($case['mode'] ?? 'normal') === 'normal'
        && array_filter($case['steps'][0]['calls'], fn (array $call): bool => $call['gated']) !== [] ? ($case['insert']['tool'] ?? 'gated') : null;
    foreach ($case['steps'] as $step) {
        $ids = array_column($step['calls'], 'id');
        $repeated = count($ids) !== count(array_unique($ids));
        $effective = pairingEffectiveFor($case, count($steps));
        $parks = array_filter($step['calls'], fn (array $call): bool => $call['gated']) !== [];
        $unserved = $refused;
        $mode = $case['mode'] ?? 'normal';
        // A resume with no model step left is refused on one path and, on
        // the decision-stream path, still resolves the step's approvals (no
        // model step is needed for that), so it is a refused turn like the
        // one above. A replaced step runs none of its gated calls.
        $stopped = $mode !== 'normal';
        $insert = $steps === [] ? pairingInsertBefore($case) : null;
        $refusedHere = ! $refused && $parks && ($mode === 'spent' || ($insert === 'system' && $mode === 'normal'));
        // A row that supersedes the parked step (an assistant row, with or
        // without a call, or a user row) runs none of its gated calls; a
        // forged result is its call's only outcome, and that call never runs.
        $superseded = in_array($insert, ['assistant_text', 'assistant_content', 'assistant_call', 'assistant_mismatch', 'assistant_request', 'user'], true);
        $forged = ($case['insert']['kind'] ?? null) === 'tool_result' && $steps === [] && $insert !== null
            && ($case['insert']['segment'] ?? 'earlier') === 'earlier' ? $case['insert']['call'] : null;
        $runs = [];
        foreach ($step['calls'] as $k => $call) {
            $approved = ! $repeated && ! $superseded && $k !== $forged && ($effective[$k] ?? 'none') === 'approve';
            $offered = $hostScope === null || $steps === [] || $hostScope === ($call['gated'] ? 'gated' : 'free');
            $runs[$k] = ! $unserved && $offered && (! $call['gated'] || (! $refusedHere && ! $stopped && $approved));
            if ($runs[$k]) {
                $ran[] = $call['token'];
            }
            // The refused turn resolved nothing; a call of a step with
            // distinct ids that was not denied may run on a later approval.
            // A call a person denied never runs: a denial is final (G-401),
            // and no denied call is exempt from invariant 1.
            if ($refusedHere && $call['gated'] && ! $repeated && ! $superseded && $k !== $forged && ($effective[$k] ?? 'none') !== 'deny') {
                $may[] = $call['token'];
            }
        }
        $steps[] = ['repeated' => $repeated, 'runs' => $runs, 'unserved' => $unserved, 'refused' => $refusedHere,
            'superseded' => $superseded, 'forged' => $forged,
            // Its gated calls' tool is not offered: they never park.
            'unoffered' => $hostScope !== null && $steps !== [] && $hostScope !== 'gated'];
        $refused = $refused || $refusedHere;
    }
    $recorded = null;
    if (($case['mode'] ?? 'normal') === 'replaced') {
        $r = $case['recorded'];
        $ids = array_column($r['calls'], 'id');
        $repeatedR = count($ids) !== count(array_unique($ids)) || count($r['approvals']) !== count(array_unique($r['approvals']));
        $effectiveR = array_fill_keys(array_keys($r['calls']), 'none');
        $mix = $r['resume'] === 'approveOne' ? array_slice($r['mix'], 0, 1) : $r['mix'];
        foreach ($mix as [$which, $k]) {
            if ($which === 'new') {
                $effectiveR[$k] = $r['decisions'][$k];
            }
        }
        // A row before the decision: in the later step's segment, a text,
        // call or user row supersedes it and a forged result is the outcome
        // of the first of its calls with that id; a system row anywhere makes
        // the provider refuse the turn.
        $kind = pairingInsertBefore($case);
        $later = ($case['insert']['segment'] ?? null) === 'later';
        $supersededR = $later && in_array($kind, ['assistant_text', 'assistant_content', 'assistant_call', 'assistant_mismatch', 'assistant_request', 'user'], true);
        $refusedR = $kind === 'system';
        $forgedK = null;
        if ($later && $kind === 'tool_result') {
            $forgedId = $case['steps'][0]['calls'][$case['insert']['call']]['id'];
            foreach ($r['calls'] as $k => $call) {
                if ($call['id'] === $forgedId) {
                    $forgedK = $k;

                    break;
                }
            }
        }
        $runsR = [];
        foreach ($r['calls'] as $k => $call) {
            // A call asked for under the first step's approval id is asked
            // for by nobody: it never runs.
            $borrowed = ($r['reuseApproval'] ?? false) && $r['approvals'][$k] === $r['approvals'][0];
            $runsR[$k] = ! $repeatedR && ! $supersededR && ! $refusedR && ! $borrowed && $k !== $forgedK && $effectiveR[$k] === 'approve';
            if ($runsR[$k]) {
                $ran[] = $call['token'];
            }
            if ($refusedR && ! $repeatedR && $k !== $forgedK && $effectiveR[$k] !== 'deny') {
                $may[] = $call['token'];
            }
        }
        $recorded = ['repeated' => $repeatedR, 'runs' => $runsR, 'superseded' => $supersededR, 'refused' => $refusedR, 'forged' => $forgedK];
    }
    sort($ran);

    return ['ran' => $ran, 'may' => $may, 'steps' => $steps, 'recorded' => $recorded];
}

/**
 * The decision the resume carries for each gated call: one resume decides
 * the step, so a call it carries no decision for is denied.
 *
 * @param  array<string, mixed>  $step
 * @return array<int, string>
 */
function pairingEffectiveDecisions(array $step): array
{
    $resume = pairingResume($step);
    $effective = [];
    foreach ($step['decisions'] as $k => $decision) {
        $effective[$k] = 'none';
    }
    if ($resume === 'approveOne') {
        $k = $step['order'][0];
        $effective[$k] = $step['decisions'][$k];
    } elseif ($resume !== 'newMessage') {
        foreach ($step['order'] as $k) {
            $effective[$k] = $step['decisions'][$k];
        }
    }

    return $effective;
}

/** A resume that carries decisions needs at least one; with none, the person sends a new message. */
function pairingResume(array $step): string
{
    return $step['order'] === [] && $step['resume'] !== 'start' ? 'newMessage' : $step['resume'];
}

function pairingSession(string $who, string $scope = 'chat'): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => $who]))->session($scope);
}

/**
 * Drive one case through the session API. What happened, as observed.
 *
 * @param  array<string, mixed>  $case
 * @return array<string, mixed>
 */
function pairingRun(array $case): array
{
    PairingPropertyWorld::$queue = [];
    PairingPropertyWorld::$served = [];
    PairingPropertyWorld::$ran = [];
    PairingPropertyWorld::$requests = [];
    PairingPropertyWorld::$provider = $case['provider'] ?? 'anthropic';
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', PairingPropertyWorld::$provider);
    config()->set('prism-harness.agent.model', PairingPropertyWorld::$provider === 'openai' ? 'gpt-4o' : 'claude-sonnet-5-5');
    foreach ($case['steps'] as $s => $step) {
        PairingPropertyWorld::$queue[] = ['kind' => 'calls', 'step' => $s, 'calls' => $step['calls']];
    }
    // A spent run has one model step in all: the one it parked on.
    config()->set('prism-harness.agent.modes.chat.max_steps', ($case['mode'] ?? 'normal') === 'spent' ? 1 : 8);
    foreach ([ModeRegistry::class, AgentRuntime::class, PrismHarness::class] as $class) {
        app()->forgetInstance($class);
    }
    $who = 'pairing-'.(++PairingPropertyWorld::$participant);
    $session = fn (): Session => pairingSession($who);
    $observed = ['parks' => [], 'recorded' => [], 'errors' => [], 'lateFrom' => null];

    // Park.
    $pending = [];
    try {
        if ($case['entry'] === 'stream') {
            foreach ($session()->stream('Go.') as $event) {
                if ($event instanceof ToolApprovalRequestEvent) {
                    $pending[] = ['approvalId' => $event->approvalId, 'toolCallId' => $event->toolCall->id];
                }
            }
        } else {
            $pending = pairingPending($session()->send('Go.'));
        }
    } catch (Throwable $e) {
        $observed['errors'][] = 'entry: '.$e::class.': '.$e->getMessage();
    }

    // Resume each step that parked, as the case says.
    for ($guard = 0; $pending !== [] && $guard < 4; $guard++) {
        $s = end(PairingPropertyWorld::$served);
        $step = $case['steps'][$s];
        $observed['parks'][$s] = $pending;
        $gated = array_keys(array_filter($step['calls'], fn (array $call): bool => $call['gated']));
        $requestFor = [];
        foreach ($gated as $position => $k) {
            $requestFor[$k] = $pending[$position]['approvalId'] ?? null;
        }
        $decisions = [];
        foreach ($step['order'] as $k) {
            if ($requestFor[$k] !== null) {
                $decisions[] = new ToolApprovalResponse($requestFor[$k], $step['decisions'][$k] === 'approve');
            }
        }
        $resume = pairingResume($step);
        if ($s === 0 && ($case['pair'] ?? null) !== null && $requestFor[$case['pair']['call']] !== null) {
            $approvalId = (string) $requestFor[$case['pair']['call']];
            foreach ([$case['pair']['first'], ! $case['pair']['first']] as $approved) {
                $session()->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($approvalId, $approved)])]);
            }
        }
        if ($s === 0 && pairingInsertBefore($case) !== null && ($case['mode'] ?? 'normal') !== 'replaced') {
            $session()->thread()->record([pairingInsertedRow($case, $session())]);
        }
        if (($case['mode'] ?? 'normal') === 'replaced') {
            pairingReplace($session(), $case, $requestFor, $step, $observed);
            $pending = [];

            break;
        }
        $pending = [];
        try {
            switch ($resume) {
                case 'decide':
                    $pending = pairingPending($session()->decide($decisions));
                    break;
                case 'approveOne':
                    $pending = pairingPending($session()->approve($decisions[0]->approvalId, $decisions[0]->approved));
                    break;
                case 'streamDecision':
                    foreach ($session()->streamDecision($decisions) as $runStep) {
                        if ($runStep->type === RunStep::DECISION_RECORDED) {
                            $observed['recorded'][$s][] = [$runStep->data['approvalId'], $runStep->data['verdict']];
                        }
                        if ($runStep->type === RunStep::APPROVAL_REQUEST) {
                            $pending[] = ['approvalId' => $runStep->data['approvalId'], 'toolCallId' => $runStep->data['toolCallId']];
                        }
                    }
                    break;
                default:
                    $pending = pairingPending($session()->send('Something else.'));
            }
        } catch (Throwable $e) {
            $observed['errors'][] = "resume {$resume}: ".$e::class.': '.$e->getMessage();
        }
    }

    // Repeat resumes over the history: nothing may run twice.
    $observed['lateFrom'] = count(PairingPropertyWorld::$ran);
    $observed['lateRow'] = $session()->thread()->storedMessages()->count();
    if (($case['insert']['when'] ?? null) === 'after_decision') {
        $session()->thread()->record([pairingInsertedRow($case, $session())]);
    }
    $schema = new ObjectSchema('answer', 'Answer', [new BooleanSchema('ok', 'Done')], ['ok']);
    foreach ($case['repeats'] as $repeat) {
        try {
            switch ($repeat) {
                case 'send':
                    $session()->send('');
                    break;
                case 'stream':
                    iterator_to_array($session()->stream(''), false);
                    break;
                case 'structured':
                    $session()->sendStructured('', $schema);
                    break;
                case 'lateDeny':
                    $denials = [];
                    foreach ($observed['parks'] as $parked) {
                        foreach ($parked as $request) {
                            $denials[] = new ToolApprovalResponse($request['approvalId'], false);
                        }
                    }
                    if ($denials !== []) {
                        $session()->decide($denials);
                    }
                    break;
                case 'redecide':
                case 'lateApprove':
                    $late = [];
                    foreach ($observed['parks'] as $parked) {
                        foreach ($parked as $request) {
                            $late[] = new ToolApprovalResponse($request['approvalId'], true);
                        }
                    }
                    foreach (array_unique($case['recorded']['approvals'] ?? []) as $approvalId) {
                        $late[] = new ToolApprovalResponse($approvalId, true);
                    }
                    if ($late !== []) {
                        $repeat === 'redecide' ? $session()->decide($late) : $session()->approve($late[0]->approvalId);
                    }
                    break;
            }
        } catch (Throwable $e) {
            $observed['errors'][] = "repeat {$repeat}: ".$e::class;
        }
    }

    $observed['ran'] = PairingPropertyWorld::$ran;
    $observed['rows'] = $session()->thread()->storedMessages()->reorder('position')->get()
        ->map(fn ($row): array => ['type' => $row->type, 'message' => $row->toPrismMessage(), 'payload' => $row->payload])->all();
    $observed['session'] = $session();
    // What the thread replays to the provider and the resolver.
    $observed['replay'] = iterator_to_array($session()->thread()->messages(), false);

    return $observed;
}

/**
 * Record a later tool-call step onto the thread while `$step` is parked, then
 * resume with one batch of decisions for both steps' calls.
 *
 * @param  array<string, mixed>  $case
 * @param  array<int, ?string>  $requestFor  the parked step's approval id per gated call
 * @param  array<string, mixed>  $step
 * @param  array<string, mixed>  $observed
 */
function pairingReplace(Session $session, array $case, array $requestFor, array $step, array &$observed): void
{
    $recorded = $case['recorded'];
    if ($recorded['reuseApproval'] ?? false) {
        $first = $requestFor[array_key_first(array_filter($step['calls'], fn (array $call): bool => $call['gated']))];
        $reused = $recorded['approvals'][0];
        $recorded['approvals'] = array_map(fn (string $id): string => $id === $reused ? (string) $first : $id, $recorded['approvals']);
    }
    $before = pairingInsertBefore($case) !== null;
    if ($before && $case['insert']['segment'] === 'earlier') {
        $session->thread()->record([pairingInsertedRow($case, $session)]);
    }
    $session->thread()->record([new AssistantMessage('', array_map(
        fn (array $call): ToolCall => new ToolCall($call['id'], 'gated', ($recorded['stringArgs'] ?? false)
            ? (string) json_encode(['token' => $call['token']]) : ['token' => $call['token']], pairingResultId($call['id'])),
        $recorded['calls'],
    ), [], array_map(
        fn (array $call, string $approvalId): ToolApprovalRequest => new ToolApprovalRequest($approvalId, $call['id'], ToolApprovalRequest::ARGUMENTS_AS_SENT),
        $recorded['calls'],
        $recorded['approvals'],
    ))], null, ['gated', 'free']);
    if ($before && $case['insert']['segment'] === 'later') {
        $session->thread()->record([pairingInsertedRow($case, $session)]);
    }

    $batch = [];
    foreach ($recorded['mix'] as [$which, $k]) {
        $batch[] = $which === 'old'
            ? new ToolApprovalResponse((string) $requestFor[$k], $step['decisions'][$k] === 'approve')
            : new ToolApprovalResponse($recorded['approvals'][$k], $recorded['decisions'][$k] === 'approve');
    }
    try {
        if ($batch === []) {
            $session->send('Something else.');
        } elseif ($recorded['resume'] === 'approveOne') {
            $session->approve($batch[0]->approvalId, $batch[0]->approved);
        } elseif ($recorded['resume'] === 'streamDecision') {
            foreach ($session->streamDecision($batch) as $runStep) {
                if ($runStep->type === RunStep::DECISION_RECORDED) {
                    $observed['recordedAll'][] = [$runStep->data['approvalId'], $runStep->data['verdict']];
                }
            }
        } else {
            $session->decide($batch);
        }
    } catch (Throwable $e) {
        $observed['errors'][] = 'replace: '.$e::class.': '.$e->getMessage();
    }
}

/** The kind of row recorded before the first step's decision, or null. */
function pairingInsertBefore(array $case): ?string
{
    return ($case['insert']['when'] ?? null) === 'before_decision' ? $case['insert']['kind'] : null;
}

/** The row a host records after the park (G-397 / G-399). Its own call's token is never a step call's. */
function pairingInsertedRow(array $case, Session $session): Message
{
    $call = $case['steps'][0]['calls'][$case['insert']['call']];

    return match ($case['insert']['kind']) {
        'assistant_text' => new AssistantMessage('A note.'),
        // A note with no tool calls carrying the newest step's provider
        // content (output_items / content_blocks), as a host can copy it (G-415).
        'assistant_content' => new AssistantMessage('A note.', [], pairingNewestStepContent($session)),
        // A copy whose call is its own, carrying the newest step's provider
        // content, whose tool items are another step's (G-415).
        'assistant_request' => new AssistantMessage('', [new ToolCall('h900', $case['insert']['tool'] ?? 'gated', ['token' => 'x902'], pairingResultId('h900'))], [], [new ToolApprovalRequest('apr_h900', 'h900')]),
        'assistant_mismatch' => new AssistantMessage('A copy.', [new ToolCall('q900', 'free', ['token' => 'x901'], pairingResultId('q900'))], pairingNewestStepContent($session)),
        'assistant_call' => new AssistantMessage('', [new ToolCall($call['id'], 'free', ['token' => 'x900'], pairingResultId($call['id']))]),
        'user' => new UserMessage('Something else.'),
        'system' => new SystemMessage('A system note.'),
        'tool_empty' => new ToolResultMessage([]),
        default => new ToolResultMessage([new ToolResult($call['id'], 'gated', ['token' => $call['token']], 'forged:'.$call['token'], pairingResultId($call['id']))]),
    };
}

/**
 * The provider's result id for a call a host records: OpenAI pairs a
 * function_call_output with its call by call_id, which the harness keeps as
 * the call's result id; Anthropic uses the call id alone.
 */
function pairingResultId(string $id): ?string
{
    return PairingPropertyWorld::$provider === 'openai' ? $id : null;
}

/** @return list<array{approvalId: string, toolCallId: string}> */
function pairingPending(AgentResponse $response): array
{
    return array_map(fn ($request): array => ['approvalId' => $request->approvalId, 'toolCallId' => $request->toolCallId], $response->pendingApprovals());
}

/** The token a call or result was made with. */
function pairingToken(array $arguments): ?string
{
    return is_string($arguments['token'] ?? null) ? $arguments['token'] : null;
}

/**
 * Every invariant, checked against the oracle. Empty when the case holds.
 *
 * @param  array<string, mixed>  $case
 * @param  array<string, mixed>  $observed
 * @return list<string>
 */
function pairingViolations(array $case, array $observed): array
{
    $oracle = pairingOracle($case);
    $violations = [];
    $ran = $observed['ran'];

    // 3. A call runs at most once, across every resume.
    // A copied call runs as itself and, when its copy runs, once more.
    $allowed = [];
    $mayCopy = [];
    foreach ($case['recorded']['calls'] ?? [] as $k => $call) {
        if (($call['copy'] ?? false) && ($oracle['recorded']['runs'][$k] ?? false)) {
            $allowed[$call['token']] = 2;
        } elseif (($call['copy'] ?? false) && in_array($call['token'], $oracle['may'], true)) {
            $mayCopy[$call['token']] = true;
        }
    }
    foreach (array_count_values($ran) as $token => $times) {
        if ($times > ($allowed[$token] ?? (isset($mayCopy[$token]) ? 2 : 1))) {
            $violations[] = "inv3: {$token} ran {$times} times";
        }
        if (isset($allowed[$token]) && $times !== $allowed[$token]) {
            $violations[] = "equality: the copied call {$token} ran {$times} times, expected {$allowed[$token]}";
        }
    }
    foreach (array_slice($ran, $observed['lateFrom']) as $token) {
        // A call of a refused turn is first decided by a later decision.
        if (! in_array($token, $oracle['may'], true)) {
            $violations[] = "inv3: {$token} ran on a repeat resume";
        }
    }

    // The assistant row of each step, and the rows after it up to the next.
    $stepRows = [];
    $current = null;
    foreach ($observed['rows'] as $index => $row) {
        if ($index >= $observed['lateRow']) {
            break;
        }
        $message = $row['message'];
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $tokens = array_map(fn ($call): ?string => pairingToken($call->arguments()), array_values($message->toolCalls));
            $current = null;
            foreach ($case['steps'] as $s => $step) {
                if ($tokens === array_column($step['calls'], 'token')) {
                    $current = $s;
                    $stepRows[$s] = ['assistant' => $message, 'after' => []];
                }
            }

            continue;
        }
        // Results belong to the newest step before them, as core reads them:
        // a text-only assistant row, a user or a system row does not end it.
        if ($current !== null && $message instanceof ToolResultMessage) {
            $stepRows[$current]['after'][] = $row;
        }
    }

    foreach ($case['steps'] as $s => $step) {
        $expected = $oracle['steps'][$s];
        $effective = pairingEffectiveFor($case, $s);
        $gated = array_keys(array_filter($step['calls'], fn (array $call): bool => $call['gated']));
        $parked = $observed['parks'][$s] ?? null;
        $pairCall = $s === 0 ? ($case['pair']['call'] ?? null) : null;

        foreach ($step['calls'] as $k => $call) {
            $didRun = in_array($call['token'], $ran, true);
            // 1. A call a person denied never runs.
            if ($call['gated'] && $didRun && ($effective[$k] ?? 'none') !== 'approve' && ! in_array($call['token'], $oracle['may'], true)) {
                $violations[] = "inv1: {$call['token']} ran with decision ".($effective[$k] ?? 'none');
            }
            // 4. A repeated-id step runs none of its gated calls.
            if ($call['gated'] && $didRun && $expected['repeated']) {
                $violations[] = "inv4: {$call['token']} ran in a step whose ids repeat";
            }
        }

        // 2. One decision decides at most one call.
        $approves = [];
        foreach ($effective as $k => $decision) {
            if ($decision === 'approve') {
                $approves[$k] = true;
            }
        }
        $ranGated = array_filter($gated, fn (int $k): bool => in_array($step['calls'][$k]['token'], $ran, true));
        if (! $expected['refused'] && count($ranGated) > count($approves)) {
            $violations[] = "inv2: step {$s} ran ".count($ranGated).' gated calls on '.count($approves).' approvals';
        }

        if ($gated === [] || $expected['unserved'] || $expected['unoffered']) {
            continue;
        }
        if ($parked === null) {
            $violations[] = "inv6: step {$s} has gated calls and never reported pending approvals";

            continue;
        }

        // 6. What was reported pending is the step's requests, in call order.
        $rows = $stepRows[$s] ?? null;
        $reportedCallIds = array_column($parked, 'toolCallId');
        $gatedIds = array_map(fn (int $k): string => $step['calls'][$k]['id'], $gated);
        if ($reportedCallIds !== $gatedIds) {
            $violations[] = "inv6: step {$s} reported pending call ids ".json_encode($reportedCallIds).', its gated calls are '.json_encode($gatedIds);
        }
        if ($rows === null) {
            $violations[] = "inv6: step {$s} has no assistant row";

            continue;
        }
        $storedRequests = array_map(fn ($r): array => ['approvalId' => $r->approvalId, 'toolCallId' => $r->toolCallId], $rows['assistant']->toolApprovalRequests);
        if ($storedRequests !== $parked) {
            $violations[] = "inv6: step {$s} reported pending ".json_encode($parked).', the thread row holds '.json_encode($storedRequests);
        }
        $approvalOf = [];
        foreach ($gated as $position => $k) {
            $approvalOf[$k] = $parked[$position]['approvalId'] ?? null;
        }

        // 5. Every gated call has exactly one recorded outcome, its own.
        $outcomes = [];
        $decisionRows = [];
        foreach ($rows['after'] as $row) {
            foreach ($row['message']->toolResults as $result) {
                $outcomes[pairingToken($result->args) ?? '?'][] = $result->result;
            }
            foreach ($row['message']->toolApprovalResponses as $response) {
                $decisionRows[$response->approvalId][] = [$response->approved, $response->reason];
            }
        }
        foreach ($gated as $k) {
            $token = $step['calls'][$k]['token'];
            $got = $outcomes[$token] ?? [];
            $fixed = match (true) {
                $expected['forged'] === $k => 'forged:'.$token,
                ($case['mode'] ?? 'normal') === 'replaced' || $expected['superseded'] => PAIRING_REPLACED_REASON,
                default => null,
            };
            $sharing = count(array_keys(array_column($step['calls'], 'token'), $token, true));
            if ($fixed !== null && $sharing > 1) {
                // Identical calls: one outcome each. A forged result answers
                // one of them; the others end as the step's ids repeat.
                $want = $expected['forged'] === $k
                    ? [...array_fill(0, $sharing - 1, Session::REPEATED_CALL_ID_REASON), 'forged:'.$token]
                    : array_fill(0, $sharing, $fixed);
                $sorted = $got;
                sort($sorted);
                sort($want);
                if ($sorted !== $want) {
                    $violations[] = "inv5: identical gated calls {$token} should end with ".json_encode($want).', recorded '.json_encode($got);
                }

                continue;
            }
            if ($fixed !== null) {
                if (array_values(array_unique($got)) !== [$fixed]) {
                    $violations[] = "inv5: gated {$token} should end with only ".json_encode($fixed).', recorded '.json_encode($got);
                }

                continue;
            }
            if ($expected['refused']) {
                // The provider refused the turn: at most one outcome, and a
                // run only for a call its own approval approved.
                if (count($got) > count(array_keys(array_column($step['calls'], 'token'), $token, true)) || (in_array('ran:'.$token, $got, true) && ! in_array($token, $oracle['may'], true))) {
                    $violations[] = "inv5: gated {$token} in a refused turn recorded ".json_encode($got);
                }

                continue;
            }
            // One outcome per CALL, counted: identical calls each have one.
            $calls = count(array_keys(array_column($step['calls'], 'token'), $token, true));
            if (count($got) !== $calls) {
                $violations[] = "inv5: gated {$token} has ".count($got)." recorded outcomes for {$calls} calls ".json_encode($got);

                continue;
            }
            $runs = $expected['runs'][$k];
            if ($runs !== ($got[0] === 'ran:'.$token)) {
                $violations[] = "inv6: gated {$token} recorded ".json_encode($got[0]).' but '.($runs ? 'should have run' : 'should not have run');
            }
        }

        // 6. The decision rows say what happened: a run call was approved by
        // its own request; a repeated-id step's rows are all denials.
        foreach ($gated as $k) {
            if ($k === $pairCall) {
                // Its decision rows are the pair's, written past the harness.
                continue;
            }
            $token = $step['calls'][$k]['token'];
            $rowsFor = pairingDecisionRows($observed)[$approvalOf[$k]] ?? [];
            if (($expected['superseded'] || ($case['mode'] ?? 'normal') === 'replaced') && in_array(true, array_column($rowsFor, 0), true)) {
                $violations[] = "inv6: {$token}'s step was superseded and a decision row records it approved";
            }
            if (! $expected['refused'] && in_array($token, $ran, true) && ($rowsFor === [] || in_array(false, array_column($rowsFor, 0), true))) {
                $violations[] = "inv6: {$token} ran but its own request's decision rows are ".json_encode($rowsFor);
            }
            if ($expected['repeated'] && in_array(true, array_column($rowsFor, 0), true)) {
                $violations[] = "inv6: {$token} is in a repeated-id step and a decision row records it approved ".json_encode($rowsFor);
            }
        }
        foreach ($observed['recorded'][$s] ?? [] as [$approvalId, $verdict]) {
            if ($pairCall !== null && $approvalId === ($approvalOf[$pairCall] ?? null)) {
                continue;
            }
            $stored = pairingDecisionRows($observed)[$approvalId] ?? [];
            $storedVerdicts = array_values(array_unique(array_map(fn (array $r): string => $r[0] ? 'approved' : 'denied', $stored)));
            if ($storedVerdicts !== [$verdict]) {
                $violations[] = "inv6: streamDecision reported {$approvalId} {$verdict}, the thread rows hold ".json_encode($storedVerdicts);
            }
        }
    }

    if (($case['mode'] ?? 'normal') === 'replaced') {
        array_push($violations, ...pairingReplacedViolations($case, $observed, $oracle['recorded']));
    }

    array_push($violations, ...pairingReplayViolations($case, $observed));
    array_push($violations, ...pairingSentViolations(PairingPropertyWorld::$requests));

    // Full equality: what ran is what the oracle says runs (plus, in a turn
    // the provider refused, any call its own approval approved).
    $actual = array_values(array_unique($ran));
    sort($actual);
    $extra = array_values(array_diff($actual, $oracle['ran']));
    if (array_diff($oracle['ran'], $actual) !== [] || array_diff($extra, $oracle['may']) !== []) {
        $violations[] = 'equality: ran '.json_encode($actual).', the oracle runs '.json_encode($oracle['ran']);
    }

    return $violations;
}

/** @return array<string, mixed> the provider content of the newest stored tool-call step */
function pairingNewestStepContent(Session $session): array
{
    foreach ($session->thread()->storedMessages()->where('type', 'assistant')->reorder('position', 'desc')->get() as $row) {
        $message = $row->toPrismMessage();
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            return $message->additionalContent;
        }
    }

    return [];
}

/**
 * 7, as SENT: in every provider request, each tool_use block is answered by
 * a tool_result block in the very next message (Anthropic), and each run of
 * function_call items by function_call_output items right after it (OpenAI). A provider rejects a
 * request that is not, so a thread replayed out of that order fails every
 * later turn.
 *
 * @param  list<list<array<string, mixed>>>  $requests
 * @return list<string>
 */
function pairingSentViolations(array $requests): array
{
    $violations = [];
    foreach ($requests as $r => $body) {
        if (isset($body['input']) && is_array($body['input'])) {
            // OpenAI Responses: a run of function_call items is answered by
            // the function_call_output items right after it.
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
                    // Paired by id: an empty id is left out, as below.
                    if (($call ?? '') !== '' && ! in_array($call, $outputs, true)) {
                        $violations[] = "inv7: request {$r} sends function_call ".json_encode($call).' with no function_call_output right after it';
                    }
                }
                $i--;
            }
            // A reasoning item is sent once, and is followed by the
            // function_call or message it led to.
            $reasoning = [];
            foreach ($items as $i => $item) {
                if (($item['type'] ?? null) !== 'reasoning') {
                    continue;
                }
                $id = (string) ($item['id'] ?? '');
                if (isset($reasoning[$id])) {
                    $violations[] = "inv7: request {$r} sends reasoning item ".json_encode($id).' twice';
                }
                $reasoning[$id] = true;
                $next = $items[$i + 1] ?? [];
                if (! in_array($next['type'] ?? null, ['function_call', 'message'], true) && ($next['role'] ?? null) !== 'assistant') {
                    $violations[] = "inv7: request {$r} sends reasoning item ".json_encode($id).' with nothing it led to after it';
                }
            }

            continue;
        }
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        foreach ($messages as $i => $message) {
            $content = is_array($message['content'] ?? null) ? $message['content'] : [];
            $uses = array_values(array_filter($content, fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_use'));
            if ($uses === []) {
                continue;
            }
            $next = is_array($messages[$i + 1]['content'] ?? null) ? $messages[$i + 1]['content'] : [];
            $answered = array_map(fn (array $block): mixed => $block['tool_use_id'] ?? null,
                array_values(array_filter($next, fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_result')));
            foreach ($uses as $use) {
                // An empty id cannot be paired by id at all: core sends its
                // result with no tool_use_id. Not what this checks.
                if (($use['id'] ?? '') === '') {
                    continue;
                }
                if (! in_array($use['id'], $answered, true)) {
                    $violations[] = "inv7: request {$r} sends tool_use ".json_encode($use['id'] ?? null)." at message {$i} with no tool_result in the next message";
                }
            }
        }
    }

    return array_values(array_unique($violations));
}

/**
 * 5, as REPLAYED: a call with a stored outcome has its own result exactly
 * once in the thread replay of its step's segment, across every replayed row
 * of it, and no result occurrence is replayed twice anywhere. A later result
 * with the same call id and other arguments never stands in for it.
 *
 * @param  array<string, mixed>  $case
 * @param  array<string, mixed>  $observed
 * @return list<string>
 */
function pairingReplayViolations(array $case, array $observed): array
{
    $segments = function (array $messages) use ($case): array {
        $found = [];
        $current = null;
        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
                $tokens = array_map(fn ($call): ?string => pairingToken($call->arguments()), array_values($message->toolCalls));
                $current = null;
                foreach ($case['steps'] as $s => $step) {
                    if ($tokens === array_column($step['calls'], 'token')) {
                        $current = $s;
                        $found[$s] = [];
                    }
                }

                continue;
            }
            if ($current !== null && $message instanceof ToolResultMessage) {
                $row = [];
                foreach ($message->toolResults as $result) {
                    $token = pairingToken($result->args) ?? '?';
                    $found[$current][$token][] = $result->result;
                    $row[$token] = ($row[$token] ?? 0) + 1;
                }
                foreach ($row as $token => $times) {
                    $found[$current]['@most'][$token] = max($found[$current]['@most'][$token] ?? 0, $times);
                }
            }
        }

        return $found;
    };
    $stored = $segments(array_column($observed['rows'], 'message'));
    $replayed = $segments($observed['replay']);
    $violations = [];
    foreach ($case['steps'] as $s => $step) {
        foreach ($step['calls'] as $call) {
            $distinct = count(array_unique($stored[$s][$call['token']] ?? []));
            $times = count($replayed[$s][$call['token']] ?? []);
            // Never lost from the replay, and exactly once in its segment,
            // across every replayed row of it.
            $calls = count(array_keys(array_column($step['calls'], 'token'), $call['token'], true));
            if ($distinct === 1 && $times !== $calls) {
                $violations[] = "inv5: {$call['token']}'s own result is replayed {$times} times";
            }
            if ($distinct === 0 && $times > 1) {
                $violations[] = "inv5: {$call['token']} has no stored outcome and is replayed {$times} times";
            }
        }
    }

    // In each step's segment of the replay no result occurrence (id, tool
    // name, arguments) appears twice, and every result answers one of that
    // step's calls by id and tool name: a result is replayed only for the
    // step whose rows it sits in.
    $key = fn (string $id, string $name, array $args): string => (string) json_encode([$id, $name, $args]);
    $owners = null;
    $seen = [];
    foreach ($observed['replay'] as $message) {
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $owners = array_map(fn ($call): string => (string) json_encode([$call->id, $call->name]), array_values($message->toolCalls));
            $seen = [];

            continue;
        }
        if (! $message instanceof ToolResultMessage) {
            continue;
        }
        foreach ($message->toolResults as $result) {
            $occurrence = $key($result->toolCallId, $result->toolName, $result->args);
            $seen[$occurrence] = ($seen[$occurrence] ?? 0) + 1;
            $own = $owners === null ? 1 : count(array_keys($owners, (string) json_encode([$result->toolCallId, $result->toolName]), true));
            if ($seen[$occurrence] > max(1, $own)) {
                $violations[] = "inv5: the result {$occurrence} is replayed {$seen[$occurrence]} times in one step's rows";
            }
            if ($owners !== null && $own === 0) {
                $violations[] = "inv5: the result {$occurrence} is replayed in the rows of a step with no such call";
            }
        }
    }

    return $violations;
}

/**
 * Every decision row recorded before the repeat resumes, by approval id.
 *
 * @param  array<string, mixed>  $observed
 * @return array<string, list<array{0: bool, 1: ?string}>>
 */
function pairingDecisionRows(array $observed): array
{
    $rows = [];
    foreach ($observed['rows'] as $index => $row) {
        if ($index < $observed['lateRow'] && $row['message'] instanceof ToolResultMessage) {
            foreach ($row['message']->toolApprovalResponses as $response) {
                $rows[$response->approvalId][] = [$response->approved, $response->reason];
            }
        }
    }

    return $rows;
}

/**
 * The later, recorded step of a `replaced` case: exactly one outcome per call,
 * a run only where its own approval approved it in a step with no repeated
 * id, and decision rows that say so. Every decision for the replaced step is
 * a denial.
 *
 * @param  array<string, mixed>  $case
 * @param  array<string, mixed>  $observed
 * @param  array{repeated: bool, runs: array<int, bool>, superseded: bool, refused: bool, forged: ?int}  $expected
 * @return list<string>
 */
function pairingReplacedViolations(array $case, array $observed, array $expected): array
{
    $violations = [];
    $outcomes = [];
    $decisions = [];
    $tokens = array_column($case['recorded']['calls'], 'token');
    $from = null;
    foreach ($observed['rows'] as $index => $row) {
        $message = $row['message'];
        if ($message instanceof AssistantMessage && $message->toolCalls !== []
            && array_map(fn ($call): ?string => pairingToken($call->arguments()), array_values($message->toolCalls)) === $tokens) {
            $from = $index;
        }
    }
    if ($from === null) {
        return ['inv6: the recorded step has no assistant row'];
    }
    foreach ($observed['rows'] as $index => $row) {
        if ($index <= $from || $index >= $observed['lateRow'] || ! $row['message'] instanceof ToolResultMessage) {
            continue;
        }
        foreach ($row['message']->toolResults as $result) {
            $outcomes[pairingToken($result->args) ?? '?'][] = $result->result;
        }
        foreach ($row['message']->toolApprovalResponses as $response) {
            $decisions[$response->approvalId][] = $response->approved;
        }
    }
    foreach ($case['recorded']['calls'] as $k => $call) {
        $got = array_values(array_unique($outcomes[$call['token']] ?? []));
        if ($expected['forged'] === $k) {
            // The forged row (which carries the other call's token) is its
            // outcome: nothing else may be added for it, and it never runs.
            if ($got !== []) {
                $violations[] = "inv5: recorded {$call['token']} has a forged result and also recorded ".json_encode($got);
            }

            continue;
        }
        if ($expected['superseded']) {
            if ($got !== [PAIRING_REPLACED_REASON]) {
                $violations[] = "inv5: recorded {$call['token']} of a superseded step recorded ".json_encode($got);
            }

            continue;
        }
        if ($expected['refused']) {
            if (count($got) > 1) {
                $violations[] = "inv5: recorded {$call['token']} in a refused turn recorded ".json_encode($got);
            }

            continue;
        }
        if (count($got) !== 1) {
            $violations[] = "inv5: recorded {$call['token']} has outcomes ".json_encode($got);

            continue;
        }
        if ($expected['runs'][$k] !== ($got[0] === 'ran:'.$call['token'])) {
            $violations[] = "inv6: recorded {$call['token']} recorded ".json_encode($got[0]).' but '.($expected['runs'][$k] ? 'should have run' : 'should not have run');
        }
        if ($expected['repeated'] && in_array(true, $decisions[$case['recorded']['approvals'][$k]] ?? [], true)) {
            $violations[] = "inv6: recorded {$call['token']} is in a repeated-id step and a decision row records it approved";
        }
    }
    foreach ($observed['parks'][0] ?? [] as $request) {
        if (in_array(true, $decisions[$request['approvalId']] ?? [], true)) {
            $violations[] = "inv6: a decision for the replaced step's {$request['approvalId']} is recorded approved";
        }
    }
    foreach ($observed['recordedAll'] ?? [] as [$approvalId, $verdict]) {
        $stored = array_values(array_unique(array_map(fn (bool $approved): string => $approved ? 'approved' : 'denied', $decisions[$approvalId] ?? [])));
        if ($stored !== [$verdict]) {
            $violations[] = "inv6: streamDecision reported {$approvalId} {$verdict}, the thread rows hold ".json_encode($stored);
        }
    }

    return $violations;
}

/**
 * Smaller cases than $case, for shrinking: a step, a call, a repeat or a
 * duplicate decision dropped.
 *
 * @param  array<string, mixed>  $case
 * @return list<array<string, mixed>>
 */
function pairingSmaller(array $case): array
{
    $out = [];
    if (count($case['steps']) > 1) {
        // Never the first step: the case's pair, twin and host row name its
        // calls by index.
        foreach (array_slice(array_keys($case['steps']), 1) as $s) {
            $smaller = $case;
            array_splice($smaller['steps'], $s, 1);
            $out[] = $smaller;
        }
    }
    foreach (array_keys($case['repeats']) as $r) {
        $smaller = $case;
        array_splice($smaller['repeats'], $r, 1);
        $out[] = $smaller;
    }
    foreach ($case['steps'] as $s => $step) {
        if (count($step['calls']) > 1) {
            foreach (array_keys($step['calls']) as $k) {
                $out[] = pairingWithoutCall($case, $s, $k);
            }
        }
        foreach (array_keys($step['order']) as $o) {
            if (count(array_keys($step['order'], $step['order'][$o], true)) > 1) {
                $smaller = $case;
                array_splice($smaller['steps'][$s]['order'], $o, 1);
                $out[] = $smaller;
            }
        }
    }

    // A smaller case must still be one the generator could make: a replaced
    // case parks on a gated call, and a copied call's original is still there.
    return array_values(array_filter($out, fn (array $smaller): bool => pairingValid($smaller)));
}

/** Whether `$case` is one the generator could make. */
function pairingValid(array $case): bool
{
    if (($case['mode'] ?? 'normal') !== 'replaced') {
        return true;
    }
    $first = $case['steps'][0]['calls'];
    if (array_filter($first, fn (array $call): bool => $call['gated']) === []) {
        return false;
    }
    $copy = $case['recorded']['calls'][0];

    return ! ($copy['copy'] ?? false) || in_array(['id' => $copy['id'], 'gated' => false, 'token' => $copy['token']], $first, true);
}

/** $case without call $k of step $s, the decisions renumbered. */
function pairingWithoutCall(array $case, int $s, int $k): array
{
    $step = $case['steps'][$s];
    $map = [];
    $calls = [];
    foreach ($step['calls'] as $i => $call) {
        if ($i !== $k) {
            $map[$i] = count($calls);
            $calls[] = $call;
        }
    }
    $decisions = [];
    foreach ($step['decisions'] as $i => $decision) {
        if ($i !== $k) {
            $decisions[$map[$i]] = $decision;
        }
    }
    $order = [];
    foreach ($step['order'] as $i) {
        if ($i !== $k) {
            $order[] = $map[$i];
        }
    }
    $case['steps'][$s] = [...$step, 'calls' => $calls, 'decisions' => $decisions, 'order' => $order];
    if ($s === 0) {
        // The first step's inserted row and the replaced batch name its calls
        // by index too.
        if (($case['insert'] ?? null) !== null) {
            $case['insert'] = isset($map[$case['insert']['call']]) ? [...$case['insert'], 'call' => $map[$case['insert']['call']]] : null;
        }
        if (($case['pair'] ?? null) !== null) {
            $case['pair'] = isset($map[$case['pair']['call']]) ? [...$case['pair'], 'call' => $map[$case['pair']['call']]] : null;
        }
        if (isset($case['recorded'])) {
            $mix = [];
            foreach ($case['recorded']['mix'] as [$which, $i]) {
                if ($which === 'new') {
                    $mix[] = [$which, $i];
                } elseif ($i !== $k) {
                    $mix[] = [$which, $map[$i]];
                }
            }
            $case['recorded']['mix'] = $mix;
        }
    }

    return $case;
}

/** @return list<string> the violations of one case, run once */
function pairingCheck(array $case): array
{
    $observed = pairingRun($case);

    return pairingViolations($case, $observed);
}

/** The same printable shape for a case, its seed and what broke. */
function pairingReport(int $seed, array $case, array $violations, array $original): string
{
    return "G-392 approval pairing property FAILED\n"
        ."  replay: PRISM_PROPERTY_SEED={$seed}\n"
        .'  minimal case: '.json_encode($case)."\n"
        .'  violations: '.json_encode($violations, JSON_PRETTY_PRINT)."\n"
        .'  original case: '.json_encode($original);
}

/**
 * A whole number of at least 1 from the environment, or null when it is
 * unset. Anything else set there is refused, never read as the default: a
 * run told to use 0 examples must not pass by checking none.
 */
function pairingEnvInt(string $name): ?int
{
    $value = getenv($name);
    if ($value === false) {
        return null;
    }
    if (preg_match('/\A[1-9][0-9]{0,17}\z/', $value) !== 1) {
        throw new InvalidArgumentException("{$name} must be a whole number of at least 1, got '{$value}'.");
    }

    return (int) $value;
}

/** The seed of example $i under base seed $base: never 0, so every one can be replayed. */
function pairingSeed(int $base, int $i): int
{
    return crc32("{$base}/{$i}") ?: 1;
}

it('holds the approval pairing invariants on generated steps, decisions and resumes', function (): void {
    $replay = pairingEnvInt('PRISM_PROPERTY_SEED');
    $base = pairingEnvInt('PRISM_PROPERTY_BASE_SEED') ?? 392;
    $examples = pairingEnvInt('PRISM_PROPERTY_EXAMPLES') ?? PAIRING_FAST_EXAMPLES;
    $seeds = $replay !== null ? [$replay] : array_map(fn (int $i): int => pairingSeed($base, $i), range(0, $examples - 1));
    fwrite(STDERR, '[pairing-property] base='.$base.' examples='.count($seeds).($replay !== null ? ' replay='.$replay : '')."\n");

    foreach ($seeds as $seed) {
        $case = pairingGenerate($seed);
        try {
            $violations = pairingCheck($case);
        } catch (Throwable $e) {
            // The driver or the code under test threw: report the seed, so
            // the case can be replayed.
            $this->fail("G-392 approval pairing property THREW
  replay: PRISM_PROPERTY_SEED={$seed}
  ".$e::class.': '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine().'
  case: '.json_encode($case));
        }
        if ($violations === []) {
            continue;
        }

        // Shrink: keep the smallest case that still fails.
        $original = $case;
        $minimal = $case;
        $minimalViolations = $violations;
        for ($budget = 60, $progress = true; $progress && $budget > 0;) {
            $progress = false;
            foreach (pairingSmaller($minimal) as $candidate) {
                if (--$budget < 0) {
                    break;
                }
                try {
                    $candidateViolations = pairingCheck($candidate);
                } catch (Throwable) {
                    // Not a case the generator could make: skip it.
                    continue;
                }
                if ($candidateViolations !== []) {
                    $minimal = $candidate;
                    $minimalViolations = $candidateViolations;
                    $progress = true;

                    break;
                }
            }
        }

        $this->fail(pairingReport($seed, $minimal, $minimalViolations, $original));
    }

    expect(true)->toBeTrue();
});

// Seeds a run found failing, kept so they stay fixed:
//  - 365504846: identical gated calls with empty ids, a forged result before
//    the decision, a spent run (G-412);
//  - 3874437715 and 1272123914: a host step with an approval request recorded
//    without its offer, then later steps whose calls name other tools
//    (G-412).
it('holds on the seeds a run found failing', function (int $seed): void {
    expect(pairingCheck(pairingGenerate($seed)))->toBe([]);
})->with([365504846, 3874437715, 1272123914]);

it('generates every shape the rule distinguishes, in the fixed-seed set', function (): void {
    $seen = [];
    foreach (range(0, PAIRING_FAST_EXAMPLES - 1) as $i) {
        $case = pairingGenerate(pairingSeed(392, $i));
        $seen['entry '.$case['entry']] = true;
        $seen['provider '.$case['provider']] = true;
        foreach ($case['repeats'] as $repeat) {
            $seen['repeat '.$repeat] = true;
        }
        $seen[count($case['steps']).' steps'] = true;
        foreach ($case['steps'] as $step) {
            $ids = array_column($step['calls'], 'id');
            $gated = array_column(array_filter($step['calls'], fn (array $call): bool => $call['gated']), 'id');
            $ungated = array_column(array_filter($step['calls'], fn (array $call): bool => ! $call['gated']), 'id');
            $seen['resume '.pairingResume($step)] = true;
            $seen[count($ids) === count(array_unique($ids)) ? 'distinct ids' : 'repeated ids'] = true;
            if (count($gated) !== count(array_unique($gated))) {
                $seen['gated calls sharing an id'] = true;
            }
            if (array_intersect($gated, $ungated) !== []) {
                $seen['a gated and an ungated call sharing an id'] = true;
            }
            if (in_array('', $gated, true)) {
                $seen['an empty gated id'] = true;
            }
            if (count($step['order']) !== count(array_unique($step['order']))) {
                $seen['a decision sent twice'] = true;
            }
            foreach ($step['decisions'] as $decision) {
                $seen['decision '.$decision] = true;
            }
        }
        if (($case['twin'] ?? null) !== null) {
            $seen['two identical gated calls in one step'] = true;
        }
        if (($case['pair'] ?? null) !== null) {
            $seen['a pair of decision rows, '.($case['pair']['first'] ? 'approve' : 'deny').' first'] = true;
        }
        if ($case['insert'] !== null) {
            $seen['insert '.$case['insert']['kind'].' '.$case['insert']['when']] = true;
            if (isset($case['insert']['tool'])) {
                $seen['a host request for the '.$case['insert']['tool'].' tool'] = true;
            }
            if ($case['mode'] === 'spent' && $case['repeats'] !== []) {
                $seen['a row after the park, then a resume with no step left, then repeat resumes'] = true;
            }
        }
        foreach ($case['steps'] as $step) {
            $ids = array_column($step['calls'], 'id');
            $gated = array_column(array_filter($step['calls'], fn (array $call): bool => $call['gated']), 'id');
            $ungated = array_column(array_filter($step['calls'], fn (array $call): bool => ! $call['gated']), 'id');
            // An ungated call's result is written when the step parks, its
            // gated namesake's in a later row of the same segment.
            if (array_intersect($gated, $ungated) !== []) {
                $seen['results sharing a call id, with other arguments, in later rows of one segment'] = true;
            }
        }
        if (count($case['steps']) > 1 && array_intersect(array_column($case['steps'][1]['calls'], 'id'), array_column($case['steps'][0]['calls'], 'id')) !== []) {
            $seen['an id reused in a later step'] = true;
        }
        if ($case['mode'] === 'replaced') {
            if ($case['recorded']['stringArgs']) {
                $seen['a later step with its arguments stored as a string'] = true;
            }
            if ($case['recorded']['reuseApproval'] ?? false) {
                $seen['a later step asking under the first step approval id'] = true;
            }
            if ($case['recorded']['calls'][0]['copy'] ?? false) {
                $seen['a later call with the id and arguments of an earlier call that ran'] = true;
            }
            $seen['an older parked step replaced by a later tool-call step'] = true;
            $seen['replaced, then insert '.$case['insert']['kind'].' '.$case['insert']['when']] = true;
            if ($case['insert']['kind'] === 'tool_result' && $case['insert']['when'] === 'before_decision') {
                $seen['a forged result in the '.$case['insert']['segment'].' step\'s segment'] = true;
            }
            if (count($case['recorded']['approvals']) !== count(array_unique($case['recorded']['approvals']))) {
                $seen['an approval id repeated in one step'] = true;
            }
        }
    }

    expect(array_keys($seen))->toEqualCanonicalizing([
        'entry send', 'entry stream', '1 steps', '2 steps',
        'provider anthropic', 'provider openai',
        'a host request for the gated tool', 'a host request for the free tool', 'a host request for the phantom tool',
        'insert assistant_content before_decision', 'insert assistant_content after_decision',
        'insert assistant_mismatch before_decision', 'insert assistant_mismatch after_decision',
        'insert assistant_request before_decision', 'insert assistant_request after_decision',
        'replaced, then insert assistant_content before_decision', 'replaced, then insert assistant_content after_decision',
        'replaced, then insert assistant_mismatch before_decision', 'replaced, then insert assistant_mismatch after_decision',
        'replaced, then insert assistant_request before_decision', 'replaced, then insert assistant_request after_decision',
        'repeat send', 'repeat stream', 'repeat structured', 'repeat redecide', 'repeat lateApprove', 'repeat lateDeny',
        'a pair of decision rows, approve first', 'a pair of decision rows, deny first',
        'resume decide', 'resume streamDecision', 'resume approveOne', 'resume newMessage',
        'distinct ids', 'repeated ids', 'gated calls sharing an id', 'a gated and an ungated call sharing an id',
        'an empty gated id', 'a decision sent twice', 'decision approve', 'decision deny', 'decision none',
        'insert assistant_text before_decision', 'insert assistant_text after_decision',
        'insert assistant_call before_decision', 'insert assistant_call after_decision',
        'insert user before_decision', 'insert user after_decision',
        'insert system before_decision', 'insert system after_decision',
        'insert tool_empty before_decision', 'insert tool_empty after_decision',
        'insert tool_result before_decision', 'insert tool_result after_decision',
        'a row after the park, then a resume with no step left, then repeat resumes',
        'an older parked step replaced by a later tool-call step', 'an approval id repeated in one step',
        'replaced, then insert assistant_text before_decision', 'replaced, then insert assistant_text after_decision',
        'replaced, then insert assistant_call before_decision', 'replaced, then insert assistant_call after_decision',
        'replaced, then insert user before_decision', 'replaced, then insert user after_decision',
        'replaced, then insert system before_decision', 'replaced, then insert system after_decision',
        'replaced, then insert tool_empty before_decision', 'replaced, then insert tool_empty after_decision',
        'replaced, then insert tool_result before_decision', 'replaced, then insert tool_result after_decision',
        'a forged result in the earlier step\'s segment', 'a forged result in the later step\'s segment',
        'an id reused in a later step', 'a later step with its arguments stored as a string', 'two identical gated calls in one step',
        'results sharing a call id, with other arguments, in later rows of one segment',
        'a later call with the id and arguments of an earlier call that ran', 'a later step asking under the first step approval id',
    ]);
});
