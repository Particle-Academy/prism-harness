<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentResponse;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
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

    public static int $participant = 0;
}

const PAIRING_REPEATED_REASON = 'Tool call was not run because another call in this step has the same id';

const PAIRING_ID_POOL = ['a', 'b', 'c', ''];

const PAIRING_DISTINCT_POOL = ['a', 'b', 'c', 'd', 'e', 'f', ''];

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['gated', 'free']);
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
        $next = array_shift(PairingPropertyWorld::$queue) ?? ['kind' => 'text'];
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
function pairingGenerate(PairingPropertyRng $rng): array
{
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

    return ['entry' => $rng->pick(['send', 'stream']), 'steps' => $steps, 'repeats' => $repeats];
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
    foreach ($case['steps'] as $step) {
        $ids = array_column($step['calls'], 'id');
        $repeated = count($ids) !== count(array_unique($ids));
        $effective = pairingEffectiveDecisions($step);
        $runs = [];
        foreach ($step['calls'] as $k => $call) {
            $runs[$k] = ! $call['gated'] || (! $repeated && ($effective[$k] ?? 'none') === 'approve');
            if ($runs[$k]) {
                $ran[] = $call['token'];
            }
        }
        $steps[] = ['repeated' => $repeated, 'runs' => $runs];
    }
    sort($ran);

    return ['ran' => $ran, 'steps' => $steps];
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
    foreach ($case['steps'] as $s => $step) {
        PairingPropertyWorld::$queue[] = ['kind' => 'calls', 'step' => $s, 'calls' => $step['calls']];
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
                case 'redecide':
                case 'lateApprove':
                    $late = [];
                    foreach ($observed['parks'] as $parked) {
                        foreach ($parked as $request) {
                            $late[] = new ToolApprovalResponse($request['approvalId'], true);
                        }
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

    return $observed;
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
    foreach (array_count_values($ran) as $token => $times) {
        if ($times > 1) {
            $violations[] = "inv3: {$token} ran {$times} times";
        }
    }
    foreach (array_slice($ran, $observed['lateFrom']) as $token) {
        $violations[] = "inv3: {$token} ran on a repeat resume";
    }

    // The assistant row of each step, and the rows after it up to the next.
    $stepRows = [];
    $current = null;
    foreach ($observed['rows'] as $index => $row) {
        $message = $row['message'];
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $tokens = array_map(fn ($call): ?string => pairingToken($call->arguments()), $message->toolCalls);
            $current = null;
            foreach ($case['steps'] as $s => $step) {
                if ($tokens === array_column($step['calls'], 'token')) {
                    $current = $s;
                    $stepRows[$s] = ['assistant' => $message, 'after' => []];
                }
            }

            continue;
        }
        if ($message instanceof AssistantMessage) {
            $current = null;
        }
        if ($current !== null && $message instanceof ToolResultMessage) {
            $stepRows[$current]['after'][] = $row;
        }
    }

    foreach ($case['steps'] as $s => $step) {
        $expected = $oracle['steps'][$s];
        $effective = pairingEffectiveDecisions($step);
        $gated = array_keys(array_filter($step['calls'], fn (array $call): bool => $call['gated']));
        $parked = $observed['parks'][$s] ?? null;

        foreach ($step['calls'] as $k => $call) {
            $didRun = in_array($call['token'], $ran, true);
            // 1. A call a person denied never runs.
            if ($call['gated'] && $didRun && ($effective[$k] ?? 'none') !== 'approve') {
                $violations[] = "inv1: {$call['token']} ran with decision ".($effective[$k] ?? 'none');
            }
            // 4. A repeated-id step runs none of its gated calls.
            if ($call['gated'] && $didRun && $expected['repeated']) {
                $violations[] = "inv4: {$call['token']} ran in a step whose ids repeat";
            }
        }

        // 2. One decision decides at most one call.
        $approves = [];
        foreach ($step['order'] as $k) {
            if (($effective[$k] ?? 'none') === 'approve') {
                $approves[$k] = true;
            }
        }
        $ranGated = array_filter($gated, fn (int $k): bool => in_array($step['calls'][$k]['token'], $ran, true));
        if (count($ranGated) > count($approves)) {
            $violations[] = "inv2: step {$s} ran ".count($ranGated).' gated calls on '.count($approves).' approvals';
        }

        if ($gated === []) {
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
            if (count($got) !== 1) {
                $violations[] = "inv5: gated {$token} has ".count($got).' recorded outcomes '.json_encode($got);

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
            $token = $step['calls'][$k]['token'];
            $rowsFor = $decisionRows[$approvalOf[$k]] ?? [];
            if (in_array($token, $ran, true) && ($rowsFor === [] || in_array(false, array_column($rowsFor, 0), true))) {
                $violations[] = "inv6: {$token} ran but its own request's decision rows are ".json_encode($rowsFor);
            }
            if ($expected['repeated'] && in_array(true, array_column($rowsFor, 0), true)) {
                $violations[] = "inv6: {$token} is in a repeated-id step and a decision row records it approved ".json_encode($rowsFor);
            }
        }
        foreach ($observed['recorded'][$s] ?? [] as [$approvalId, $verdict]) {
            $stored = $decisionRows[$approvalId] ?? [];
            $storedVerdicts = array_values(array_unique(array_map(fn (array $r): string => $r[0] ? 'approved' : 'denied', $stored)));
            if ($storedVerdicts !== [$verdict]) {
                $violations[] = "inv6: streamDecision reported {$approvalId} {$verdict}, the thread rows hold ".json_encode($storedVerdicts);
            }
        }
    }

    // Full equality: what ran is what the oracle says runs.
    $actual = array_values(array_unique($ran));
    sort($actual);
    if ($actual !== $oracle['ran']) {
        $violations[] = 'equality: ran '.json_encode($actual).', the oracle runs '.json_encode($oracle['ran']);
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
        foreach (array_keys($case['steps']) as $s) {
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

    return $out;
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

it('holds the approval pairing invariants on generated steps, decisions and resumes', function (): void {
    $replay = getenv('PRISM_PROPERTY_SEED');
    $base = (int) (getenv('PRISM_PROPERTY_BASE_SEED') ?: 392);
    $examples = max(1, (int) (getenv('PRISM_PROPERTY_EXAMPLES') ?: 40));
    $seeds = $replay !== false && $replay !== ''
        ? [(int) $replay]
        : array_map(fn (int $i): int => crc32("{$base}/{$i}"), range(0, $examples - 1));
    fwrite(STDERR, '[pairing-property] base='.$base.' examples='.count($seeds).($replay ? ' replay='.$replay : '')."\n");

    foreach ($seeds as $seed) {
        $case = pairingGenerate(new PairingPropertyRng($seed));
        $violations = pairingCheck($case);
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
                $candidateViolations = pairingCheck($candidate);
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

it('generates every shape the rule distinguishes, in the fixed-seed set', function (): void {
    $seen = [];
    foreach (range(0, 39) as $i) {
        $case = pairingGenerate(new PairingPropertyRng(crc32("392/{$i}")));
        $seen['entry '.$case['entry']] = true;
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
    }

    expect(array_keys($seen))->toEqualCanonicalizing([
        'entry send', 'entry stream', '1 steps', '2 steps',
        'repeat send', 'repeat stream', 'repeat structured', 'repeat redecide', 'repeat lateApprove',
        'resume decide', 'resume streamDecision', 'resume approveOne', 'resume newMessage',
        'distinct ids', 'repeated ids', 'gated calls sharing an id', 'a gated and an ungated call sharing an id',
        'an empty gated id', 'a decision sent twice', 'decision approve', 'decision deny', 'decision none',
    ]);
});
