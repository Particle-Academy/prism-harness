<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Support\InRunRecording;
use Prism\Harness\Tools\CanonicalJson;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Contracts\Message;
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

// Each tool call is decided by its own approval. A decision reaches a call
// through the call id its request names, and a provider can give two calls in
// one step the same id. A step whose call ids repeat is therefore denied: every
// decision for it is recorded as a denial, so no gated call in it runs, and
// approvalStatus reports what happened. A step with distinct ids is decided
// call by call, unchanged.

const G392_HARNESS_REASON = 'Tool call was not run because another call in this step has the same id';

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $ran = &$this->ran;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran[] = $city;

                return "Sunny in {$city}";
            }),
    ]);
});

/**
 * One Responses step with two weather calls, then a text reply for every later
 * request.
 */
function g392HarnessFake(string $firstId, string $secondId): void
{
    $call = fn (string $id, string $city): array => [
        'id' => $id, 'type' => 'function_call', 'status' => 'completed',
        'arguments' => json_encode(['city' => $city]), 'call_id' => $id, 'name' => 'weather',
    ];
    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];

    Http::fakeSequence()
        ->push(['id' => 'resp_g392', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [$call($firstId, 'Paris'), $call($secondId, 'Rome')], 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_g392_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_g392', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
}

function g392HarnessSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/**
 * @param  list<ToolApprovalRequest>  $pending
 * @param  list<bool>  $decisions
 * @return list<ToolApprovalResponse>
 */
function g392HarnessDecisions(array $pending, array $decisions): array
{
    return array_map(
        fn (ToolApprovalRequest $request, bool $approved): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, $approved),
        $pending,
        $decisions,
    );
}

/** @return array<string, callable(Session, list<ToolApprovalResponse>): mixed> */
function g392HarnessPaths(): array
{
    return [
        'decide' => fn (Session $session, array $decisions): mixed => $session->decide($decisions),
        'streamDecision' => fn (Session $session, array $decisions): mixed => iterator_to_array($session->streamDecision($decisions), false),
    ];
}

dataset('g392 harness decisions', [
    'deny the first, approve the second' => [[false, true]],
    'approve the first, deny the second' => [[true, false]],
    'approve both' => [[true, true]],
]);

it('runs no call of a step whose two calls share one id, and reports both denied', function (string $path, array $decisions): void {
    g392HarnessFake('weather', 'weather');

    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    expect($pending)->toHaveCount(2)
        ->and($pending[0]->toolCallId)->toBe($pending[1]->toolCallId);

    g392HarnessPaths()[$path](g392HarnessSession(), g392HarnessDecisions($pending, $decisions));

    $session = g392HarnessSession();

    expect($this->ran)->toBe([])
        ->and($session->approvalStatus($pending[0]->approvalId))->toBe('denied')
        ->and($session->approvalStatus($pending[1]->approvalId))->toBe('denied')
        ->and($session->thread()->pendingApprovalIds())->toBe([]);
})->with(['decide', 'streamDecision'])->with('g392 harness decisions');

it('runs no call of a repeated-id step when its approvals are answered one at a time', function (bool $first): void {
    g392HarnessFake('weather', 'weather');

    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    g392HarnessSession()->approve($pending[$first ? 0 : 1]);

    $session = g392HarnessSession();
    $other = $pending[$first ? 1 : 0]->approvalId;

    expect($this->ran)->toBe([])
        ->and($session->approvalStatus($pending[$first ? 0 : 1]->approvalId))->toBe('denied')
        ->and($session->approvalStatus($other))->not->toBe('approved')
        ->and($session->approvalStatus($other))->not->toBe('pending');
})->with(['the first' => [true], 'the second' => [false]]);

it('records the denial reason for a repeated-id step', function (): void {
    g392HarnessFake('weather', 'weather');

    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    g392HarnessSession()->decide(g392HarnessDecisions($pending, [true, true]));

    $reasons = [];
    foreach (g392HarnessSession()->thread()->storedMessages()->get() as $stored) {
        foreach ($stored->payload['tool_approval_responses'] ?? [] as $response) {
            $reasons[$response['approval_id'] ?? '?'][] = [$response['approved'] ?? null, $response['reason'] ?? null];
        }
    }

    // Every stored row that carries a decision for either approval holds the
    // denial, never the approval that was sent.
    expect(array_keys($reasons))->toEqualCanonicalizing([$pending[0]->approvalId, $pending[1]->approvalId]);
    foreach ($reasons as $rows) {
        expect(array_unique($rows, SORT_REGULAR))->toBe([[false, G392_HARNESS_REASON]]);
    }
});

it('still decides each call by its own approval when the ids are distinct', function (string $path, array $decisions): void {
    g392HarnessFake('call_paris', 'call_rome');

    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    g392HarnessPaths()[$path](g392HarnessSession(), g392HarnessDecisions($pending, $decisions));

    $session = g392HarnessSession();
    $expected = array_values(array_filter(['Paris', 'Rome'], fn (string $city, int $i): bool => $decisions[$i], ARRAY_FILTER_USE_BOTH));

    expect($this->ran)->toBe($expected)
        ->and($session->approvalStatus($pending[0]->approvalId))->toBe($decisions[0] ? 'approved' : 'denied')
        ->and($session->approvalStatus($pending[1]->approvalId))->toBe($decisions[1] ? 'approved' : 'denied');
})->with(['decide', 'streamDecision'])->with('g392 harness decisions');

// G-397: a decision applies ONLY to the step whose approvals were requested.
// The repeated-id guard, the resolver, the decision checks and the plan
// readers choose that step by one rule (Support\PausedStep). Between the
// park and the decision a row is inserted (Thread::record() is public): an
// assistant answer, a user turn, a system row or a tool row. Each decision
// path either applies to the parked step and nothing else (here, a step
// whose ids repeat: a denial) or is refused, and no same-id gated call runs.

/** @return array<string, Closure(): Message> */
function g397Inserts(): array
{
    return [
        'an assistant row' => fn (): Message => new AssistantMessage('An answer written after the park.'),
        'a user row' => fn (): Message => new UserMessage('A message written after the park.'),
        'a system row' => fn (): Message => new SystemMessage('A system row written after the park.'),
        'a tool row' => fn (): Message => new ToolResultMessage([new ToolResult('other', 'weather', ['city' => 'Oslo'], 'Cold')]),
    ];
}

/** @return array<string, Closure(Session, list<ToolApprovalRequest>): void> */
function g397Paths(): array
{
    $approveAll = fn (array $pending): array => array_map(fn (ToolApprovalRequest $p): ToolApprovalResponse => new ToolApprovalResponse($p->approvalId, true), $pending);

    return [
        'approve' => function (Session $session, array $pending): void {
            $session->approve($pending[0]);
        },
        'decide' => fn (Session $session, array $pending) => $session->decide($approveAll($pending)),
        'streamDecision' => fn (Session $session, array $pending) => iterator_to_array($session->streamDecision($approveAll($pending)), false),
        // A host writing the decisions itself, then resuming: the route the
        // checks never see, so the resolver must hold on its own.
        'host-written decisions, then resume' => function (Session $session, array $pending) use ($approveAll): void {
            $session->thread()->record([new ToolResultMessage(toolApprovalResponses: $approveAll($pending))]);
            $session->send('');
        },
        'host-written decisions, then a new message' => function (Session $session, array $pending) use ($approveAll): void {
            $session->thread()->record([new ToolResultMessage(toolApprovalResponses: $approveAll($pending))]);
            $session->send('And now?');
        },
    ];
}

it('applies a decision only to the parked step, whatever row is written between the park and it', function (string $insert, string $path): void {
    g392HarnessFake('weather', 'weather');
    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();
    expect($pending)->toHaveCount(2);

    g392HarnessSession()->thread()->record([g397Inserts()[$insert]()]);
    $outcome = 'applied';
    try {
        g397Paths()[$path](g392HarnessSession(), $pending);
    } catch (ApprovalNotPending) {
        $outcome = 'refused';
    }

    $session = g392HarnessSession();
    $statuses = array_map(fn (ToolApprovalRequest $p): string => $session->approvalStatus($p->approvalId), $pending);
    // No same-id gated call runs, on any path, and nothing is left that a
    // gated tool would accept as approval evidence.
    expect($this->ran)->toBe([])
        ->and($session->thread()->approvedCallsAwaitingResult('weather', CanonicalJson::digest(['city' => 'Paris'])))->toBe(0)
        ->and($session->thread()->approvedCallsAwaitingResult('weather', CanonicalJson::digest(['city' => 'Rome'])))->toBe(0);
    if (! str_starts_with($path, 'host-written')) {
        // Through the harness's own checks: never recorded as approved.
        expect($statuses)->not->toContain('approved');
        if (in_array($insert, ['a tool row', 'a system row'], true)) {
            // A result row or a system row keeps the step parked (the release
            // lines' rule): the decision applies to it, as a denial.
            expect($outcome)->toBe('applied')->and($statuses[0])->toBe('denied');
        } else {
            // The conversation moved on: nothing is pending, and the decision is refused.
            expect($outcome)->toBe('refused');
        }
    }
})->with(array_keys(g397Inserts()))->with(array_keys(g397Paths()));

it('reads every paused-step question from the same step', function (string $insert): void {
    g392HarnessFake('weather', 'weather');
    g392HarnessSession()->send('Weather in Paris and Rome?');
    g392HarnessSession()->thread()->record([g397Inserts()[$insert]()]);

    $thread = g392HarnessSession()->thread();
    $paused = in_array($insert, ['a tool row', 'a system row'], true);
    expect($thread->pendingApprovalIds() !== [])->toBe($paused)
        ->and($thread->pausedApprovalIds() !== [])->toBe($paused)
        ->and($thread->pausedStepRepeatsCallIds())->toBe($paused)
        ->and($thread->unansweredApprovals() !== [])->toBe($paused)
        ->and($thread->approvalToolNames() !== null)->toBe($paused);
})->with(array_keys(g397Inserts()));

// G-399: results and decisions are read from EVERY result row of the paused
// step, chosen by the shared rule (G-397), never only from the first run of
// result rows after it. A row inserted between a decision and its resume, or
// after the approved call ran, never makes an approved call run again: each
// approved call runs at most once over any number of resumes, on any path.
it('never runs an approved call twice when a row is written between its decision, its run and later resumes', function (string $insert, string $when): void {
    g392HarnessFake('call_paris', 'call_rome');
    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();
    $approve = array_map(fn (ToolApprovalRequest $p): ToolApprovalResponse => new ToolApprovalResponse($p->approvalId, true), $pending);

    if ($when === 'after the run') {
        g392HarnessSession()->decide($approve);
        expect($this->ran)->toBe(['Paris', 'Rome']);
        g392HarnessSession()->thread()->record([g397Inserts()[$insert]()]);
    } else {
        $session = g392HarnessSession();
        (fn () => $this->recordDecisions($approve))->call($session);
        g392HarnessSession()->thread()->record([g397Inserts()[$insert]()]);
    }
    for ($i = 0; $i < 2; $i++) {
        g392HarnessSession()->send('');
        iterator_to_array(g392HarnessSession()->streamSteps(''), false);
        try {
            g392HarnessSession()->decide($approve);
        } catch (ApprovalNotPending) {
            // Nothing pending: answered, or the conversation moved on.
        }
    }

    expect(count($this->ran))->toBe(count(array_unique($this->ran)));
    if ($when === 'after the run' || $insert === 'a tool row') {
        // A result row keeps the step parked, so the decision applied, once.
        expect($this->ran)->toBe(['Paris', 'Rome']);
    }
})->with(array_keys(g397Inserts()))->with(['between the decision and its resume', 'after the run']);

// DECISION B (G-387 round 13): a parked step is SUPERSEDED by a later
// assistant text row, a later assistant tool-call step or a user row (a host
// writing one, or a new message). Each of its gated calls with no result then
// gets exactly ONE denial, `Tool call was not run because a later step replaced it`, marked by the
// harness; nothing from it runs, whatever was decided on it before, and a
// decision made after the superseding row is refused.
function decisionBSupersede(Session $session, string $kind): void
{
    match ($kind) {
        'an assistant text row' => $session->thread()->record([new AssistantMessage('A later answer.')]),
        'an assistant tool-call step' => $session->thread()->record([new AssistantMessage('', [new ToolCall('later', 'weather', ['city' => 'Oslo'])])]),
        'a user row' => $session->thread()->record([new UserMessage('A later message.')]),
        'a new message' => $session->send('Something else, please.'),
    };
}

it('denies each open gated call of a superseded step once, runs nothing and refuses a later decision', function (string $kind): void {
    g392HarnessFake('call_paris', 'call_rome');
    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();
    // A decision made BEFORE the superseding row is not acted on either.
    $session = g392HarnessSession();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($pending[0]->approvalId, true)]))->call($session);

    decisionBSupersede(g392HarnessSession(), $kind);

    $refused = false;
    try {
        g392HarnessSession()->decide([new ToolApprovalResponse($pending[1]->approvalId, true)]);
    } catch (ApprovalNotPending) {
        $refused = true;
    }
    g392HarnessSession()->send('');
    iterator_to_array(g392HarnessSession()->streamSteps(''), false);

    $outcomes = [];
    $marks = [];
    foreach (g392HarnessSession()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            if (in_array($entry['tool_call_id'], ['call_paris', 'call_rome'], true)) {
                $outcomes[$entry['tool_call_id']][] = $entry['result'];
            }
        }
        if (array_key_exists('harness_superseded', $row->payload)) {
            $marks[] = [array_keys($row->payload), $row->payload['harness_superseded']];
        }
    }
    $session = g392HarnessSession();

    expect($this->ran)->toBe([])
        ->and($refused)->toBeTrue()
        ->and($outcomes)->toBe([
            'call_paris' => ['Tool call was not run because a later step replaced it'],
            'call_rome' => ['Tool call was not run because a later step replaced it'],
        ])
        ->and($marks)->toBe([[['tool_results', 'tool_approval_responses', 'harness_superseded'],
            ['approval_ids' => [$pending[0]->approvalId, $pending[1]->approvalId]]]])
        ->and($session->approvalStatus($pending[0]->approvalId))->toBe('superseded')
        ->and($session->approvalStatus($pending[1]->approvalId))->toBe('superseded');
})->with(['an assistant text row', 'an assistant tool-call step', 'a user row', 'a new message']);

// The refinement of DECISION B: an assistant text row or a user row
// supersedes the step only when it is recorded OUTSIDE the run that owns it.
// The harness's own rows inside that run do not; and a caller cannot make a
// row "inside" by naming the run: only the runtime's own recording counts.
/** The denials a superseded step got, by call id. */
function decisionBDenials(): array
{
    $out = [];
    foreach (g392HarnessSession()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        if (array_key_exists('harness_superseded', $row->payload)) {
            foreach ($row->payload['tool_results'] as $entry) {
                $out[$entry['tool_call_id']][] = $entry['result'];
            }
        }
    }

    return $out;
}

it('does not supersede a step with the harness\'s own row inside the run that owns it', function (string $row): void {
    g392HarnessFake('call_paris', 'call_rome');
    $response = g392HarnessSession()->send('Weather in Paris and Rome?');
    $owner = $response->runId;
    $message = $row === 'assistant text' ? new AssistantMessage('The run\'s own answer.') : new UserMessage('The run\'s own row.');

    g397AsRuntime($owner, fn () => g392HarnessSession()->thread()->record([$message], $owner));

    expect(decisionBDenials())->toBe([])->and($this->ran)->toBe([]);
})->with(['assistant text', 'user']);

it('supersedes a step with a row a host records, whatever run id it names', function (string $runId): void {
    g392HarnessFake('call_paris', 'call_rome');
    $response = g392HarnessSession()->send('Weather in Paris and Rome?');
    $named = match ($runId) {
        'none' => null,
        'the owning run (forged)' => $response->runId,
        'another run' => 'run_'.str_repeat('a', 24),
    };

    g392HarnessSession()->thread()->record([new UserMessage('A host row.')], $named);
    $refused = false;
    try {
        g392HarnessSession()->decide([new ToolApprovalResponse($response->pendingApprovals()[0]->approvalId, true)]);
    } catch (ApprovalNotPending) {
        $refused = true;
    }

    expect(decisionBDenials())->toBe([
        'call_paris' => ['Tool call was not run because a later step replaced it'],
        'call_rome' => ['Tool call was not run because a later step replaced it'],
    ])->and($refused)->toBeTrue()->and($this->ran)->toBe([]);
})->with(['none', 'the owning run (forged)', 'another run']);

it('always supersedes a step with a later assistant step that has tool calls, even inside the owning run', function (): void {
    g392HarnessFake('call_paris', 'call_rome');
    $owner = g392HarnessSession()->send('Weather in Paris and Rome?')->runId;

    g397AsRuntime($owner, fn () => g392HarnessSession()->thread()->record(
        [new AssistantMessage('', [new ToolCall('later', 'weather', ['city' => 'Oslo'])])], $owner));

    expect(decisionBDenials())->toBe([
        'call_paris' => ['Tool call was not run because a later step replaced it'],
        'call_rome' => ['Tool call was not run because a later step replaced it'],
    ])->and($this->ran)->toBe([]);
});

// Aligned with the release lines (G-387 round 13): a step whose approval
// requests repeat an APPROVAL id across distinct calls is a repeated-id step.
// It fails closed: every gated call of it is denied, nothing runs.
it('runs nothing of a step whose distinct calls share one approval id', function (string $path): void {
    $session = g392HarnessSession();
    $session->thread()->record([
        new UserMessage('Weather in Paris and Rome?'),
        new AssistantMessage('', [new ToolCall('call_paris', 'weather', ['city' => 'Paris']), new ToolCall('call_rome', 'weather', ['city' => 'Rome'])], [],
            [new ToolApprovalRequest('apr_shared', 'call_paris'), new ToolApprovalRequest('apr_shared', 'call_rome')]),
    ], toolNames: ['weather']);
    Http::fakeSequence()->whenEmpty(Http::response(['id' => 'resp_shared', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
        'output' => [['id' => 'msg_shared', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]],
        'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2]]));

    $decision = [new ToolApprovalResponse('apr_shared', true)];
    $path === 'decide' ? g392HarnessSession()->decide($decision) : iterator_to_array(g392HarnessSession()->streamDecision($decision), false);
    g392HarnessSession()->send('');

    $outcomes = [];
    foreach (g392HarnessSession()->thread()->storedMessages()->where('type', 'tool_result')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            $outcomes[$entry['tool_call_id']][] = $entry['result'];
        }
    }
    expect($this->ran)->toBe([])
        ->and(g392HarnessSession()->thread()->approvalDecisions())->toBe(['apr_shared' => false])
        ->and($outcomes)->toBe(['call_paris' => [G392_HARNESS_REASON], 'call_rome' => [G392_HARNESS_REASON]]);
})->with(['decide', 'streamDecision']);

// Run-aware (the release lines' rule): the runs that own a parked step are
// the one that recorded it and every run that recorded one of its results. A
// text row from one of them does not supersede the step; from any other run
// it does, and only the calls still without a result are denied.
it('reads the runs that recorded a step\'s results as owning it', function (string $writer): void {
    g392HarnessFake('call_paris', 'call_rome');
    g392HarnessSession()->send('Weather in Paris and Rome?');
    $resumer = 'run_'.str_repeat('b', 24);
    $thread = g392HarnessSession()->thread();
    g397AsRuntime($resumer, fn () => $thread->record(
        [new ToolResultMessage([new ToolResult('call_paris', 'weather', ['city' => 'Paris'], 'Sunny in Paris')])], $resumer));
    $run = $writer === 'the run that recorded a result' ? $resumer : 'run_'.str_repeat('c', 24);
    g397AsRuntime($run, fn () => g392HarnessSession()->thread()->record([new AssistantMessage('An answer.')], $run));

    expect(decisionBDenials())->toBe($writer === 'the run that recorded a result' ? []
        : ['call_rome' => ['Tool call was not run because a later step replaced it']])
        ->and($this->ran)->toBe([]);
})->with(['the run that recorded a result', 'another run']);

// A decision on a superseded step is refused with the code the ports use,
// `approval_not_pending`, on every decision path, and all or nothing: a batch
// that names one such approval records none of its decisions.
it('refuses a decision on a superseded step with approval_not_pending, all or nothing', function (string $path): void {
    g392HarnessFake('call_paris', 'call_rome');
    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();
    g392HarnessSession()->thread()->record([new UserMessage('A later message.')]);
    $decisions = array_map(fn (ToolApprovalRequest $p): ToolApprovalResponse => new ToolApprovalResponse($p->approvalId, true), $pending);

    $code = null;
    try {
        match ($path) {
            'approve' => g392HarnessSession()->approve($pending[0]),
            'decide' => g392HarnessSession()->decide($decisions),
            'streamDecision' => iterator_to_array(g392HarnessSession()->streamDecision($decisions), false),
        };
    } catch (ApprovalNotPending $refused) {
        $code = $refused->code();
    }

    expect($code)->toBe('approval_not_pending')
        ->and(g392HarnessSession()->thread()->approvalDecisions())->toBe([])
        ->and($this->ran)->toBe([]);
})->with(['approve', 'decide', 'streamDecision']);

// G-401: a second decision on an approval already decided stays refused,
// whatever it says, and the first decision stands.
it('refuses a second decision on an approval already decided', function (string $path, bool $again): void {
    g392HarnessFake('call_paris', 'call_rome');
    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();
    $session = g392HarnessSession();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($pending[0]->approvalId, false)]))->call($session);

    $code = null;
    try {
        $second = [new ToolApprovalResponse($pending[0]->approvalId, $again), new ToolApprovalResponse($pending[1]->approvalId, true)];
        match ($path) {
            'decide' => g392HarnessSession()->decide($second),
            'streamDecision' => iterator_to_array(g392HarnessSession()->streamDecision($second), false),
        };
    } catch (ApprovalNotPending $refused) {
        $code = $refused->code();
    }

    expect($code)->toBe('approval_not_pending')
        ->and(g392HarnessSession()->thread()->approvalDecisions())->toBe([$pending[0]->approvalId => false])
        ->and($this->ran)->toBe([]);
})->with(['decide', 'streamDecision'])->with(['approve it' => [true], 'deny it again' => [false]]);

// Upgrade (DECIDED, G-387 round 13): 0.27 main reads a thread that 0.26.x
// wrote with a REPLACED step, in that line's exact rows, taken from its own
// writer (release/0.26.x Thread::withReplacedStepsEnded):
//  - 6cf2804: a later tool-call step replaced the parked one; the replaced
//    calls' row has NO mark;
//  - 9820779 (the line's head): also a user or assistant text row from
//    outside the run; the row carries `harness_replaced: {approval_ids}`.
// The text is the same in both, and in main. Main resumes the thread: each
// gated call keeps exactly ONE outcome (no second denial), nothing runs, a
// decision on the replaced step is refused.
const G397_026_REPLACED = 'Tool call was not run because a later step replaced it';

/** Write rows exactly as stored, bypassing this line's writer (as an older worker wrote them). */
function g397WriteRaw(Session $session, string $type, array $payload, ?string $runId = null): void
{
    $thread = $session->thread();
    $thread->storedMessages()->create([
        'position' => (int) $thread->storedMessages()->max('position') + 1,
        'type' => $type,
        'run_id' => $runId,
        'payload' => $payload,
    ]);
}

it('reads a 0.26.x thread with a replaced step: one outcome per call, nothing runs twice', function (string $shape): void {
    g392HarnessFake('call_paris', 'call_rome');
    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();
    $ids = array_map(fn (ToolApprovalRequest $p): string => $p->approvalId, $pending);
    // A decision recorded before the step was replaced (0.26.x accepted it).
    $session = g392HarnessSession();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($ids[0], true)]))->call($session);

    $denials = ['tool_results' => [
        ['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'args' => ['city' => 'Paris'], 'result' => G397_026_REPLACED, 'tool_call_result_id' => 'call_paris', 'artifacts' => []],
        ['tool_call_id' => 'call_rome', 'tool_name' => 'weather', 'args' => ['city' => 'Rome'], 'result' => G397_026_REPLACED, 'tool_call_result_id' => 'call_rome', 'artifacts' => []],
    ], 'tool_approval_responses' => []];
    if ($shape !== 'later step, unmarked (6cf2804)') {
        $denials['harness_replaced'] = ['approval_ids' => $ids];
    }
    g397WriteRaw(g392HarnessSession(), 'tool_result', $denials);
    match ($shape) {
        'later step, unmarked (6cf2804)', 'later step, marked (9820779)' => g397WriteRaw(g392HarnessSession(), 'assistant', [
            'content' => '', 'tool_calls' => [['id' => 'later', 'name' => 'weather', 'arguments' => ['city' => 'Oslo'], 'result_id' => null, 'reasoning_id' => null, 'reasoning_summary' => null]],
            'additional_content' => [], 'tool_approval_requests' => []]),
        'user row, marked (9820779)' => g397WriteRaw(g392HarnessSession(), 'user', ['content' => 'Something else.', 'additional_content' => [], 'additional_attributes' => []]),
    };

    $refused = false;
    try {
        g392HarnessSession()->decide([new ToolApprovalResponse($ids[1], true)]);
    } catch (ApprovalNotPending) {
        $refused = true;
    }
    g392HarnessSession()->send('');
    iterator_to_array(g392HarnessSession()->streamSteps(''), false);
    g392HarnessSession()->send('And now?');

    $outcomes = [];
    foreach (g392HarnessSession()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            if (in_array($entry['tool_call_id'], ['call_paris', 'call_rome'], true)) {
                $outcomes[$entry['tool_call_id']][] = $entry['result'];
            }
        }
    }
    expect($this->ran)->toBe([])
        ->and($refused)->toBeTrue()
        ->and($outcomes)->toBe(['call_paris' => [G397_026_REPLACED], 'call_rome' => [G397_026_REPLACED]]);
    if ($shape !== 'later step, unmarked (6cf2804)') {
        // The released line's mark is read: the approved decision reads replaced, never approved.
        expect(g392HarnessSession()->approvalStatus($ids[0]))->toBe('superseded')
            ->and(g392HarnessSession()->approvalStatus($ids[1]))->toBe('superseded');
    }
})->with(['later step, unmarked (6cf2804)', 'later step, marked (9820779)', 'user row, marked (9820779)']);

/**
 * Record as the RUNTIME records a run's own rows. Only the runtime can: its
 * marker is private, so a test reaches it the way no host can, by binding
 * into the class.
 */
function g397AsRuntime(string $runId, Closure $record): mixed
{
    return Closure::bind(static fn (): mixed => AgentRuntime::recordInRun($runId, $record), null, AgentRuntime::class)();
}

// A run id a CALLER passes is always outside the runs that own a step: the
// run id argument of Thread::record(), and any other public route. Only the
// runtime's own recording of a run counts as inside it, and nothing public
// sets that.
it('never lets a caller mark a row as written inside the run that owns the step', function (): void {
    g392HarnessFake('call_paris', 'call_rome');
    $owner = g392HarnessSession()->send('Weather in Paris and Rome?')->runId;

    $public = array_map(fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(Thread::class))->getMethods(ReflectionMethod::IS_PUBLIC));
    g392HarnessSession()->thread()->record([new UserMessage('A host row under the owning run id.')], $owner);

    expect($public)->not->toContain('recordingInRun')
        ->and((new ReflectionMethod(AgentRuntime::class, 'recordInRun'))->isPublic())->toBeFalse()
        ->and(array_map(fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(InRunRecording::class))->getMethods(ReflectionMethod::IS_PUBLIC)))->toBe(['active'])
        ->and(decisionBDenials())->toBe([
            'call_paris' => ['Tool call was not run because a later step replaced it'],
            'call_rome' => ['Tool call was not run because a later step replaced it'],
        ])->and($this->ran)->toBe([]);
});

// ABSOLUTE SEGMENT OWNERSHIP (G-387 round 13b): a result counts ONLY for the
// calls of the step whose segment it sits in (the rows after that step and
// before the next assistant row with tool calls). Within the segment it is
// matched by exact arguments, then id and tool name, then id. A result whose
// arguments exactly match an EARLIER step's call with the same id never moves
// to that call: it answers the later same-id call (or nothing), the earlier
// call keeps its one replaced denial, and nothing runs twice.
function segmentFake(bool $failResume): void
{
    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    $sequence = Http::fakeSequence()
        ->push(['id' => 'resp_seg', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'call_x', 'type' => 'function_call', 'status' => 'completed',
                'arguments' => json_encode(['city' => 'Paris']), 'call_id' => 'call_x', 'name' => 'weather']], 'usage' => $usage]);
    if ($failResume) {
        // The resume that runs the newest call fails AFTER its result is
        // recorded, so that result stays the tail every later resume reads.
        $sequence->pushStatus(500);
    }
    $sequence->whenEmpty(Http::response(['id' => 'resp_seg_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
        'output' => [['id' => 'msg_seg', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
}

/**
 * Park on call_x {Paris}, then a later step (another writer's) with the SAME
 * id and tool, different arguments, asking its own approval; approve it.
 *
 * @return array{0: string, 1: string} the earlier and the newest approval ids
 */
function segmentSetup(bool $failResume): array
{
    segmentFake($failResume);
    $earlier = g392HarnessSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    g392HarnessSession()->thread()->record([new AssistantMessage('', [new ToolCall('call_x', 'weather', ['city' => 'Rome'])], [],
        [new ToolApprovalRequest('apr_seg_newest', 'call_x', ToolApprovalRequest::ARGUMENTS_AS_SENT)])]);

    return [$earlier, 'apr_seg_newest'];
}

/** Every result each call_x got, in stored order, with the arguments it recorded. */
function segmentOutcomes(): array
{
    $out = [];
    foreach (g392HarnessSession()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            $out[] = [$entry['args'], $entry['result']];
        }
    }

    return $out;
}

function segmentResumes(int $times, string $newest): void
{
    for ($i = 0; $i < $times; $i++) {
        try {
            g392HarnessSession()->send('');
        } catch (Throwable) {
            // A failed resume: what it recorded stays.
        }
        try {
            g392HarnessSession()->decide([new ToolApprovalResponse($newest, true)]);
        } catch (ApprovalNotPending) {
            // Answered already.
        } catch (Throwable) {
            // A failed resume.
        }
    }
}

it('never moves a later segment\'s result to an earlier same-id call: a forged result answers the later call', function (): void {
    [$earlier, $newest] = segmentSetup(false);
    // A result in the LATER segment whose arguments exactly match the EARLIER call.
    g392HarnessSession()->thread()->record([new ToolResultMessage([new ToolResult('call_x', 'weather', ['city' => 'Paris'], 'forged')])]);

    segmentResumes(3, $newest);

    // The forged row answers the newest call (it fails closed: nothing runs);
    // the earlier call has exactly one outcome, the replaced denial.
    expect($this->ran)->toBe([])
        ->and(segmentOutcomes())->toBe([
            [['city' => 'Paris'], 'Tool call was not run because a later step replaced it'],
            [['city' => 'Paris'], 'forged'],
        ])
        ->and(g392HarnessSession()->approvalStatus($earlier))->toBe('superseded');
});

it('keeps an approved newest call done when its recorded result carries an earlier same-id call\'s arguments', function (): void {
    [$earlier, $newest] = segmentSetup(true);
    try {
        g392HarnessSession()->decide([new ToolApprovalResponse($newest, true)]);
    } catch (Throwable) {
        // The model request after the run fails: the result is recorded.
    }
    expect($this->ran)->toBe(['Rome']);

    // The newest call's recorded result carries LOSSY arguments that exactly
    // match the earlier same-id call (as stored, not as the call had them).
    $thread = g392HarnessSession()->thread();
    $row = $thread->storedMessages()->where('type', 'tool_result')->orderByDesc('position')->get()
        ->first(fn ($row): bool => collect($row->payload['tool_results'] ?? [])->contains(fn (array $r): bool => $r['result'] === 'Sunny in Rome'));
    expect($row)->not->toBeNull();
    $payload = $row->payload;
    foreach ($payload['tool_results'] as $i => $entry) {
        if ($entry['result'] === 'Sunny in Rome') {
            $payload['tool_results'][$i]['args'] = ['city' => 'Paris'];
        }
    }
    $row->payload = $payload;
    $row->save();

    segmentResumes(3, $newest);

    // It answers the newest call, whose segment it sits in: the approved call
    // ran exactly once over three resumes, and the earlier call kept its one
    // replaced denial.
    expect($this->ran)->toBe(['Rome'])
        ->and(segmentOutcomes())->toBe([
            [['city' => 'Paris'], 'Tool call was not run because a later step replaced it'],
            [['city' => 'Paris'], 'Sunny in Rome'],
        ])
        ->and(g392HarnessSession()->approvalStatus($earlier))->toBe('superseded');
});

// Within a segment a result is its call's by exact arguments first (round
// 13b). The replay that folds consecutive result rows into one message must
// keep that: folded by call id alone, a later same-id result with other
// arguments replaced a call's OWN result, so the resolver saw that call open
// and gave it a second outcome.
it('keeps each call\'s own result when a later row in its segment carries the same id with other arguments', function (string $path): void {
    g392HarnessFake('call_dup', 'call_dup');
    $pending = g392HarnessSession()->send('Weather in Paris and Rome?')->pendingApprovals();
    g392HarnessSession()->thread()->record([new ToolResultMessage([new ToolResult('call_dup', 'weather', ['city' => 'Rome'], 'forged Rome')])]);
    g392HarnessSession()->thread()->record([new ToolResultMessage([new ToolResult('call_dup', 'weather', ['city' => 'Oslo'], 'forged Oslo')])]);

    g392HarnessPaths()[$path](g392HarnessSession(), g392HarnessDecisions($pending, [true, true]));
    g392HarnessSession()->send('');

    $outcomes = [];
    foreach (segmentOutcomes() as [$args, $result]) {
        $outcomes[$args['city']][$result] = true;
    }

    // Rome has its own result; Oslo, no call's arguments, answers the first
    // open call with the id and tool (Paris). Each call: ONE outcome.
    expect($this->ran)->toBe([])
        ->and(array_map('array_keys', $outcomes))->toBe([
            'Rome' => ['forged Rome'],
            'Oslo' => ['forged Oslo'],
        ]);
})->with(array_keys(g392HarnessPaths()));
