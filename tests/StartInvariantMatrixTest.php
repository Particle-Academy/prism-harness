<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Events\ToolDenied;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Plans\Plans;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\Participant;
use Tests\Support\G360;

// G-387 round 11: THE INVARIANT MATRIX. Every reader of a child's state is
// driven through every phase of a runner start, and read both from INSIDE
// the start's own run (the Session object the run uses, as a host tool
// provider is handed it) and from OUTSIDE it (a fresh Session for the same
// child: a host turn, a resume, a decision, a cancel). The expected values
// are written first, as data, from the rules of rounds 7 to 11:
//
//  - the gate reads and writes nothing (R7);
//  - (b) writes the child's records and its mode PROVISIONALLY: absent to
//    every reader outside the start's run until its task row exists, the
//    refs reading as the last CONFIRMED ones (R9, R10);
//  - the start's own run reads its own committed state (R11 item 5);
//  - the task row ends the calls the child was parked on (R9 item 2), and an
//    ended call reads `superseded` by the harness's own mark (R10/R11);
//  - a failed start never happened: with no task row its records read as
//    absent whether or not its undo ran (R9), and the run it replaced is the
//    current run again (R9 item 7).
//
// Every outside action runs in a transaction that is rolled back, so each
// cell reads the phase as it is, not as an earlier cell left it.
//
// Round 12 widened it (the round-11 reviews' throwaway copies, made
// permanent): the phases `at RunStarted(S)` (a RunStarted listener on the
// start's run), `at ToolDenied(A0)` (a ToolDenied listener for the call the
// start ended) and `at RunFailed(S)` (a RunFailed listener, so a failed
// start is measured DURING its failure, not only after it); the readers
// plans, delegation, busy (the gate's child_busy fact), linked,
// provisional (the stored record is unconfirmed), plan_run, paused (the
// parked row a resume would continue) and policy (whether the mode the
// session reads gates `weather`); the outside action `second start` (a
// runner start onto the same child from a fresh root context); and the
// actions entered on the start's OWN Session object, during its run and
// after it (`own decision`, `own resume`, `own new message`, `reentrant
// start`). In PHP a RunStarted listener is handed no Session, so that phase
// has no inside reader; nor does (b), whose locked write runs no host code.
//
// The child, before the start: a delegation parked it on a weather approval
// (run r0, approval A0), then its saved binding was lost, so nobody can
// answer A0 and the start ends it; the host then set its mode to `chat`. The
// start delegates through the subagent tool into the `work` mode with
// max_steps 3. A host tool provider offers `danger` only to a session whose
// mode is not `work`.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.max_steps', 8);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'worker' => ['description' => 'Work.', 'mode' => 'work', 'max_steps' => 3],
    ]);
    config()->set('prism-harness.agent.modes.work', [
        'system_prompt' => 'Work.', 'tools' => ['weather', 'echo', 'danger', 'probe'], 'skills' => [], 'max_steps' => 8,
        'requires_approval' => ['weather'], 'plans' => true,
    ]);
    config()->set('prism-harness.plans.enabled', true);
    foreach ([ModeRegistry::class, PrismHarness::class, AgentRuntime::class, SubagentRunner::class, RunTrees::class, Plans::class] as $class) {
        app()->forgetInstance($class);
    }
    $this->ran = ['weather' => 0, 'danger' => 0];
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran['weather']++;
                ImIds::$weather++;

                return 'Sunny';
            }),
        (new Tool)->as('echo')->for('Echo.')->withStringParameter('text', 'Text')
            ->using(fn (string $text): string => $text),
    ]);
});

/**
 * The expected value of every reader, by phase and side. `n/a`: no reader
 * exists there (nothing runs on the start's own Session before its run, and
 * outside actions are not taken from inside it).
 *
 * @return array<string, array<string, mixed>>
 */
function imExpected(): array
{
    $prior = [
        'mode' => 'chat', 'binding' => false, 'refs' => ['tree' => false, 'plan' => false], 'depth' => 0, 'ceiling' => null,
        'run' => ['r0', 'completed'], 'approval' => ['pending', 'undecided', 'pending'], 'offer' => ['worker'],
        'plans' => false, 'delegation' => false, 'busy' => false, 'linked' => true, 'provisional' => false,
        'plan_run' => null, 'paused' => 'valid', 'policy' => false, 'mark' => [], 'cancel S' => 'no run',
    ];
    $started = [
        'mode' => 'work', 'binding' => true, 'refs' => ['tree' => true, 'plan' => false], 'depth' => 1, 'ceiling' => 3,
        'plans' => true, 'delegation' => true, 'policy' => true,
    ];
    $running = ['run' => ['S', 'running'], 'plan_run' => 'S', 'cancel S' => 'ok'];
    // `host row` (round 14, G-404; round 15): a host records a user row on the
    // child's thread. It is NEVER refused (round 15, the write-ahead started
    // row): it supersedes A0, or, once A0 is ended, supersedes nothing; a
    // call a run has started is answered-in-progress, so no lock is needed.
    // `host step` is the same with an assistant row with a tool call, and
    // `host row eb` the same user row through record()'s public
    // `endedByStart` parameter, which is runtime-only (PHP run17 #1).
    $hostRows = ['host row' => 'ran', 'host step' => 'ran', 'host row eb' => 'InvalidArgumentException',
        // Round 16 (item 8): a RAW copy of the paused step whose tool_calls
        // are a JSON string (G-408), one that mixes non-objects with a NEW
        // call under the old request (#92), a host copy with altered
        // arguments under the kept request (the binding), each then
        // approved; and a RAW started row for A0's call naming the parked
        // run's id (a run id is never a trust signal). Whatever the phase,
        // nothing a copy holds runs, and the forged row changes no reader.
        'g408 copy' => 'nothing ran', 'mixed copy' => 'nothing ran', 'altered copy' => 'nothing ran', 'forged started rid' => 'unchanged'];
    $refused = ['host turn' => 'child_binding_missing', 'resume' => 'child_binding_missing',
        'recordApproval' => 'child_binding_missing', 'cancel' => 'ok', 'second start' => 'completed', ...$hostRows];
    // The child's lock is held by the start: every turn and decision waits,
    // a second start included (framed `failed`, nothing written).
    $locked = ['host turn' => 'locked', 'resume' => 'locked', 'recordApproval' => 'locked', 'cancel' => 'ok', 'second start' => 'failed',
        ...$hostRows];
    $ownLocked = ['own decision' => 'locked', 'own resume' => 'locked', 'own new message' => 'locked'];
    $ownRefused = ['own decision' => 'child_binding_missing', 'own resume' => 'child_binding_missing',
        'own new message' => 'child_binding_missing'];
    // The RAW stored mark (round 13): the row's keys in order, and the mark,
    // byte for byte the thr-0014 shape, naming the approvals in call order.
    // Round 15: the runtime's own result row then names the step it answers
    // (`harness_answers_step`, last).
    $mark = [['tool_results', 'tool_approval_responses', 'harness_ended_by_start', 'harness_answers_step'], ['approval_ids' => ['A0']]];
    $ended = ['approval' => ['superseded', 'undecided', 'not pending'], 'offer' => ['echo', 'probe', 'weather'], 'paused' => 'none',
        'mark' => [$mark]];
    // Once A0 is ended nothing is parked: a host row supersedes nothing, so it needs no lock.
    $endedHostRow = $hostRows;
    // Once the start's run has ended, neither the parked run nor the start's own can be cancelled.
    $over = ['cancel' => 'not-running', 'cancel S' => 'not-running'];
    $after = [...$prior, ...$started, ...$ended, 'run' => ['S', 'completed'],
        'host turn' => 'ran', 'resume' => 'ran', 'recordApproval' => 'not_pending', ...$over, 'second start' => 'completed', ...$endedHostRow];
    $afterInside = [...$prior, ...$started, ...$ended, 'run' => ['S', 'completed'],
        'own decision' => 'not_pending', 'own resume' => 'ran', 'own new message' => 'ran'];
    // A failed start never happened: its run is not the child's current run
    // (R9 item 7), only a past one, so there is nothing of it to cancel.
    $unstarted = ['cancel S' => 'not-running'];

    return [
        'before the gate|outside' => [...$prior, ...$refused],
        'after (b)|outside' => [...$prior, ...$locked, 'provisional' => true],
        'at RunStarted(S)|outside' => [...$prior, ...$running, ...$locked, 'provisional' => true],
        'during its run|inside' => [...$prior, ...$started, ...$running, 'offer' => 'no danger', 'provisional' => true,
            'busy' => true, ...$ownLocked, 'reentrant start' => 'failed'],
        'during its run|outside' => [...$prior, ...$running, ...$locked, 'provisional' => true],
        'at ToolDenied(A0)|outside' => [...$prior, ...$started, ...$running, ...$ended, ...$locked,
            'cancel' => 'not-running', 'busy' => true, 'second start' => 'denied', ...$endedHostRow],
        // Round 13: inside a RunFinished listener for the start's run. The
        // run is completed and its records confirmed; the lock is still held.
        'at RunFinished(S)|outside' => [...$prior, ...$started, ...$ended, 'run' => ['S', 'completed'], ...$locked, ...$over, ...$endedHostRow],
        'at RunFinished(S)|inside' => [...$prior, ...$started, ...$ended, 'run' => ['S', 'completed'], ...$ownLocked],
        'after the task row|outside' => $after,
        'after the task row|inside' => $afterInside,
        // Round 13: a completed start followed by more turns stands. Its
        // saved ceiling (3) still bounds the child: the start took two steps
        // and the host turn the third, so a further turn takes no model step.
        'after more turns|outside' => [...$after, 'run' => ['other', 'completed'],
            'host turn' => 'run_not_permitted', 'resume' => 'run_not_permitted'],
        'after more turns|inside' => [...$afterInside, 'run' => ['other', 'completed'],
            'own resume' => 'run_not_permitted', 'own new message' => 'run_not_permitted'],
        'at RunFailed(S), undone|outside' => [...$prior, ...$locked, ...$unstarted],
        'at RunFailed(S), undone|inside' => [...$prior, ...$ownLocked, ...$unstarted],
        'after a failed start, undone|outside' => [...$prior, ...$refused, ...$unstarted],
        'after a failed start, undone|inside' => [...$prior, ...$ownRefused, ...$unstarted],
        'at RunFailed(S), undo failed|outside' => [...$prior, ...$locked, 'provisional' => true, ...$unstarted],
        'at RunFailed(S), undo failed|inside' => [...$prior, ...$ownLocked, 'provisional' => true, ...$unstarted],
        'after a failed start, undo failed|outside' => [...$prior, ...$refused, 'provisional' => true, ...$unstarted],
        'after a failed start, undo failed|inside' => [...$prior, ...$ownRefused, 'provisional' => true, ...$unstarted],
        // Round 13: a start that fails AFTER its task row happened, and stands.
        'at RunFailed(S), kept|outside' => [...$prior, ...$started, ...$ended, 'run' => ['S', 'failed'], ...$locked, ...$over, ...$endedHostRow],
        'at RunFailed(S), kept|inside' => [...$prior, ...$started, ...$ended, 'run' => ['S', 'failed'], ...$ownLocked],
        'after a failed start, kept|outside' => [...$after, 'run' => ['S', 'failed']],
        'after a failed start, kept|inside' => [...$afterInside, 'run' => ['S', 'failed']],
        // Round 13: a person cancels the start's run inside it, before its
        // first model step. The start was committed, so its task row is
        // recorded and it stands; the cancel ends its run and its tree, so
        // every later turn on the child is refused as cancelled.
        'after a cancelled start|outside' => [...$after, 'run' => ['S', 'cancelled'],
            'host turn' => 'run_cancelled', 'resume' => 'run_cancelled'],
        'after a cancelled start|inside' => [...$afterInside, 'run' => ['S', 'cancelled'],
            'own resume' => 'run_cancelled', 'own new message' => 'run_cancelled'],
    ];
}

/** The phases each scenario passes through. */
function imPhases(string $scenario): array
{
    $completes = ['before the gate|outside', 'after (b)|outside', 'at RunStarted(S)|outside', 'during its run|inside',
        'during its run|outside', 'at ToolDenied(A0)|outside', 'at RunFinished(S)|outside', 'at RunFinished(S)|inside',
        'after the task row|outside', 'after the task row|inside'];

    return match ($scenario) {
        'completes', 'repeated ids', 'mixed repeated' => $completes,
        'completes+' => [...$completes, 'after more turns|outside', 'after more turns|inside'],
        'fails, undone' => ['before the gate|outside', 'after (b)|outside', 'at RunStarted(S)|outside', 'during its run|inside',
            'during its run|outside', 'at RunFailed(S), undone|outside', 'at RunFailed(S), undone|inside',
            'after a failed start, undone|outside', 'after a failed start, undone|inside'],
        'fails, undo fails' => ['before the gate|outside', 'after (b)|outside', 'at RunStarted(S)|outside', 'during its run|inside',
            'during its run|outside', 'at RunFailed(S), undo failed|outside', 'at RunFailed(S), undo failed|inside',
            'after a failed start, undo failed|outside', 'after a failed start, undo failed|inside'],
        'fails after the task row' => ['before the gate|outside', 'after (b)|outside', 'at RunStarted(S)|outside', 'during its run|inside',
            'during its run|outside', 'at ToolDenied(A0)|outside', 'at RunFailed(S), kept|outside', 'at RunFailed(S), kept|inside',
            'after a failed start, kept|outside', 'after a failed start, kept|inside'],
        'cancelled' => ['before the gate|outside', 'after (b)|outside', 'at RunStarted(S)|outside', 'during its run|inside',
            'during its run|outside', 'after a cancelled start|outside', 'after a cancelled start|inside'],
    };
}

/**
 * The expected value of `$cell` in `$scenario`. On `repeated ids` the child
 * was parked on TWO calls sharing one id (A0 and A1): A1 reads exactly as A0
 * in every cell, and the mark names both, in call order.
 *
 * @return array<string, mixed>
 */
function imExpectedIn(string $scenario, string $cell): array
{
    $want = imExpected()[$cell];
    if (str_ends_with($cell, '|inside')) {
        // Cancelling the start's run is an action taken from outside it.
        unset($want['cancel S']);
    }
    if ($scenario === 'repeated ids') {
        $want['approval1'] = $want['approval'];
        if (array_key_exists('recordApproval', $want)) {
            $want['recordApproval1'] = $want['recordApproval'];
        }
        $want['mark'] = array_map(fn (array $mark): array => [$mark[0], ['approval_ids' => ['A0', 'A1']]], $want['mark']);
    }

    return $want;
}

function imChild(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat::sub::worker');
}

function imRoot(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

final class ImIds
{
    public static ?string $r0 = null;

    public static ?string $s = null;

    public static ?string $a0 = null;

    /** The second approval of a `repeated ids` step (round 13). */
    public static ?string $a1 = null;

    public static bool $outside = false;

    /** How many times the weather tool ran (round 16). */
    public static int $weather = 0;
}

/**
 * The ended marks stored on the child's thread, RAW (round 13): each marked
 * row's keys in order and its mark, the approval ids named A0 / A1.
 *
 * @return list<array{0: list<string>, 1: mixed}>
 */
function imMarks(Session $session): array
{
    $names = array_filter([(string) ImIds::$a0 => 'A0', (string) ImIds::$a1 => 'A1'], fn (string $key): bool => $key !== '', ARRAY_FILTER_USE_KEY);
    $marks = [];
    foreach ($session->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        $payload = $row->payload;
        if (! array_key_exists('harness_ended_by_start', $payload)) {
            continue;
        }
        $mark = $payload['harness_ended_by_start'];
        if (is_array($mark) && is_array($mark['approval_ids'] ?? null)) {
            $mark['approval_ids'] = array_map(fn (mixed $id): mixed => is_string($id) ? ($names[$id] ?? 'other') : $id, $mark['approval_ids']);
        }
        $marks[] = [array_keys($payload), $mark];
    }

    return $marks;
}

/** @return array{0: string, 1: string, 2: string} */
function imApproval(Session $session, string $approval): array
{
    $thread = $session->thread();

    return [$session->approvalStatus($approval),
        array_key_exists($approval, $thread->approvalDecisions()) ? 'decided' : 'undecided',
        in_array($approval, $thread->pendingApprovalIds(), true) ? 'pending' : 'not pending'];
}

/**
 * Every state reader on `$session`.
 *
 * @return array<string, mixed>
 */
function imRead(Session $session, bool $offer): array
{
    $run = $session->run();
    $id = $run['id'] ?? null;
    $thread = $session->thread();
    $read = [
        'mode' => $session->mode(),
        'binding' => $session->childBinding() !== null,
        'refs' => ['tree' => $session->childTree() !== null, 'plan' => $session->childPlanLedger() !== null],
        'depth' => $session->childDepth(),
        'ceiling' => $session->childStepCeiling(),
        'run' => [$id === ImIds::$r0 ? 'r0' : ($id === ImIds::$s && $id !== null ? 'S' : ($id === null ? null : 'other')), $run['status'] ?? null],
        'approval' => imApproval($session, (string) ImIds::$a0),
        'plans' => app(Plans::class)->enabledFor($session),
        'delegation' => $session->childDelegation() !== null,
        'busy' => app(RunTrees::class)->delegationBusy($session),
        'linked' => $session->isLinkedChild(),
        'provisional' => imProvisional($session),
        'plan_run' => ($planRun = app(Plans::class)->runId($session)) === null ? null : ($planRun === ImIds::$s ? 'S' : 'other'),
        'paused' => $thread->pausedStepsUsed()[0],
        'policy' => app(ModeRegistry::class)->forAgent($session->agent())->resolve($session->mode())->needsApproval('weather'),
        'mark' => imMarks($session),
    ];
    if (ImIds::$a1 !== null) {
        $read['approval1'] = imApproval($session, ImIds::$a1);
    }
    if ($offer) {
        ImIds::$outside = true;
        try {
            $names = array_column(app(AgentRuntime::class)->effectiveTools($session), 'name');
        } finally {
            ImIds::$outside = false;
        }
        sort($names);
        $read['offer'] = $names;
    }

    return $read;
}

/** Whether the child's STORED record is a start's, not yet confirmed by its task row (a storage fact: the same from every side). */
function imProvisional(Session $session): bool
{
    $raw = DB::table('harness_session_state')->where('key', $session->key().':child')->value('payload');
    $mark = $raw === null ? null : (json_decode((string) $raw, true)['child_binding']['provisional'] ?? null);

    return is_string($mark) && ! $session->taskRowExists($mark);
}

/** A runner start onto the child from a fresh root context: its outcome. */
function imSecondStart(): string
{
    $root = 'run_'.bin2hex(random_bytes(12));

    return app(SubagentRunner::class)->run(new Subagent('worker', 'Work.', 'work', new RunBudget(3)), imRoot(),
        RunContext::root($root, new RunBudget(8)), $root, 'A second start')->outcome->value;
}

/**
 * The actions entered on the start's OWN Session object (`$own`).
 *
 * @return array<string, string>
 */
function imOwnActions(Session $own, bool $reentrant = false): array
{
    $actions = [
        'own decision' => imRolledBack(function () use ($own): void {
            $own->approve((string) ImIds::$a0);
        }),
        'own resume' => imRolledBack(function () use ($own): void {
            $own->send('');
        }),
        'own new message' => imRolledBack(function () use ($own): void {
            $own->send('Injected');
        }),
    ];
    if ($reentrant) {
        $actions['reentrant start'] = imRolledBack(fn (): string => imSecondStart());
    }

    return $actions;
}

/** Run `$action` (its string result is the outcome) against the database as it is, then put the database back. */
function imRolledBack(Closure $action): string
{
    $queue = G360::$queue;
    $outside = ImIds::$outside;
    ImIds::$outside = true;
    G360::$queue = [];
    for ($i = 0; $i < 4; $i++) {
        G360::text('Hi.');
    }
    DB::beginTransaction();
    try {
        $outcome = $action();

        return is_string($outcome) ? $outcome : 'ran';
    } catch (RunNotPermitted $refused) {
        return $refused->code();
    } catch (SessionLocked) {
        return 'locked';
    } catch (ApprovalNotPending) {
        return 'not_pending';
    } catch (Throwable $other) {
        return $other::class;
    } finally {
        DB::rollBack();
        G360::$queue = $queue;
        ImIds::$outside = $outside;
    }
}

/**
 * Every reader and every action from OUTSIDE the start's run: a fresh Session.
 *
 * @return array<string, mixed>
 */
function imOutside(): array
{
    $read = imRead(imChild(), true);
    $read['host turn'] = imRolledBack(function (): void {
        imChild()->send('Hello');
    });
    $read['resume'] = imRolledBack(function (): void {
        imChild()->send('');
    });
    $read['recordApproval'] = imRolledBack(function (): void {
        imChild()->approve((string) ImIds::$a0);
    });
    $read['cancel'] = imRolledBack(fn (): string => imChild()->cancel((string) ImIds::$r0)->value);
    // Round 13: a person cancelling the START's own run, from outside it.
    $read['cancel S'] = ImIds::$s === null ? 'no run' : imRolledBack(fn (): string => imChild()->cancel((string) ImIds::$s)->value);
    if (ImIds::$a1 !== null) {
        $read['recordApproval1'] = imRolledBack(function (): void {
            imChild()->approve((string) ImIds::$a1);
        });
    }
    $read['second start'] = imRolledBack(fn (): string => imSecondStart());
    $read['host row'] = imRolledBack(function (): void {
        imChild()->thread()->record([new UserMessage('A host row.')]);
    });
    $read['host step'] = imRolledBack(function (): void {
        imChild()->thread()->record([new AssistantMessage('', [new ToolCall('host_call', 'echo', ['text' => 'h'])], [],
            [new ToolApprovalRequest('host_apr', 'host_call')])]);
    });
    $read['host row eb'] = imRolledBack(function (): void {
        $ended = new ToolResultMessage([]);
        imChild()->thread()->record([new UserMessage('A host row.'), $ended], endedByStart: [['message' => $ended, 'approvals' => []]]);
    });
    foreach (['g408 copy', 'mixed copy', 'altered copy'] as $copy) {
        $read[$copy] = imRolledBack(fn (): string => imCopyThenApprove($copy));
    }
    $read['forged started rid'] = imRolledBack(fn (): string => imForgedStarted());

    return $read;
}

/**
 * Round 16 (item 8): a copy of the child's parked step, then an approval of
 * A0: 'nothing ran' when no weather call ran, whatever the decision did.
 */
function imCopyThenApprove(string $copy): string
{
    $thread = imChild()->thread();
    $step = $thread->storedMessages()->where('type', 'assistant')->orderBy('position', 'desc')->get()
        ->first(fn ($row): bool => is_array($row->payload['tool_calls'] ?? null) && ($row->payload['tool_approval_requests'] ?? []) !== []);
    $call = $step === null ? ['id' => 'call_x', 'name' => 'weather', 'arguments' => ['city' => 'Paris'], 'result_id' => null, 'reasoning_id' => null, 'reasoning_summary' => null]
        : $step->payload['tool_calls'][0];
    $request = ['approval_id' => (string) ImIds::$a0, 'tool_call_id' => $call['id']];
    $before = ImIds::$weather;
    if ($copy === 'altered copy') {
        $thread->record([new AssistantMessage('', [new ToolCall($call['id'], 'weather', ['city' => 'Rome'])], [],
            [new ToolApprovalRequest((string) ImIds::$a0, $call['id'])])], toolNames: ['weather']);
    } else {
        $calls = $copy === 'g408 copy' ? json_encode([$call])
            : ['x', [...$call, 'id' => 'zz_new', 'arguments' => ['city' => 'Rome']], null, 3];
        $thread->storedMessages()->create(['position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => 'assistant', 'run_id' => null,
            'payload' => ['content' => '', 'tool_calls' => $calls, 'additional_content' => [],
                'tool_approval_requests' => [$copy === 'g408 copy' ? $request : [...$request, 'tool_call_id' => 'zz_new']]]]);
    }
    try {
        imChild()->approve((string) ImIds::$a0);
    } catch (RunNotPermitted|SessionLocked|ApprovalNotPending) {
        // Refused: what matters is that nothing ran.
    }

    return ImIds::$weather === $before ? 'nothing ran' : 'ran '.(ImIds::$weather - $before);
}

/**
 * Round 16 (item 8): a RAW started row for A0's call carrying the parked
 * run's id: 'unchanged' when every reader reads as it did before it.
 */
function imForgedStarted(): string
{
    $read = fn (): string => (string) json_encode([imChild()->thread()->pendingApprovalIds(), imChild()->approvalStatus((string) ImIds::$a0),
        imChild()->thread()->approvalDecisions()]);
    $before = $read();
    $thread = imChild()->thread();
    $step = $thread->storedMessages()->where('type', 'assistant')->orderBy('position', 'desc')->get()
        ->first(fn ($row): bool => is_array($row->payload['tool_calls'] ?? null) && ($row->payload['tool_approval_requests'] ?? []) !== []);
    $callId = $step === null ? 'call_x' : (string) $step->payload['tool_calls'][0]['id'];
    $thread->storedMessages()->create(['position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => 'tool_result',
        'run_id' => ImIds::$r0, 'payload' => ['tool_results' => [], 'tool_approval_responses' => [],
            'harness_started' => ['tool_call_id' => $callId, 'tool_name' => 'weather', 'approval_id' => (string) ImIds::$a0],
            'harness_answers_step' => ['position' => (int) ($step?->position ?? 0)]]]);

    return $read() === $before ? 'unchanged' : 'changed: '.$before.' => '.$read();
}

/**
 * The child parks on A0 in run r0; its binding is lost; the host sets it to
 * `chat`. On `repeated ids` it parks on TWO gated weather calls sharing one
 * id (A0 and A1, G-392); on `mixed repeated` on a gated weather call that
 * shares its id with an ungated echo call that ran at step time (round 13).
 */
function imPrior(string $scenario): void
{
    Event::listen(RunStarted::class, function (RunStarted $event): void {
        if (str_contains($event->sessionKey, '::sub::worker') && ! ImIds::$outside) {
            if (ImIds::$r0 === null) {
                ImIds::$r0 = $event->runId;
            } elseif (ImIds::$s === null) {
                ImIds::$s = $event->runId;
            }
        }
    });
    G360::calls([['worker', ['task' => 'Go']]]);
    G360::calls(match ($scenario) {
        'repeated ids' => [['weather', ['city' => 'Paris'], 'dup'], ['weather', ['city' => 'Rome'], 'dup']],
        'mixed repeated' => [['weather', ['city' => 'Paris'], 'dup'], ['echo', ['text' => 'hi'], 'dup']],
        default => [['weather', ['city' => 'Paris']]],
    });
    for ($i = 0; $i < 3; $i++) {
        G360::text('Asked.');
    }
    imRoot()->send('Go');
    $pending = imChild()->thread()->pendingApprovalIds();
    ImIds::$a0 = $pending[0] ?? 'apr_none';
    ImIds::$a1 = $scenario === 'repeated ids' ? ($pending[1] ?? 'apr_none') : null;
    DB::table('harness_session_state')->where('key', imChild()->key().':child')->delete();
    imChild()->usingMode('chat');
    foreach ([PrismHarness::class, AgentRuntime::class, SubagentRunner::class, RunTrees::class, Plans::class] as $class) {
        app()->forgetInstance($class);
    }
}

it('holds every child-state reader at every phase of a runner start, inside and outside its run', function (string $scenario): void {
    ImIds::$r0 = ImIds::$s = ImIds::$a0 = ImIds::$a1 = null;
    imPrior($scenario);
    $seen = [];
    $kept = null;
    $armed = true;
    $failsBeforeTaskRow = in_array($scenario, ['fails, undone', 'fails, undo fails'], true);

    // A host tool provider: it offers `danger` to a session not in `work`,
    // and on the start's own Session it reads every reader (INSIDE), then
    // every reader and action from a fresh Session (OUTSIDE).
    app(ToolRegistry::class)->registerProvider(function (Session $session) use (&$seen, &$kept, &$armed, $scenario, $failsBeforeTaskRow): array {
        if (! str_contains($session->key(), '::sub::worker')) {
            return [];
        }
        $danger = $session->mode() !== 'work';
        if ($armed && ! ImIds::$outside) {
            $armed = false;
            $kept = $session;
            $seen['during its run|inside'] = [...imRead($session, false), 'offer' => $danger ? 'danger' : 'no danger',
                ...imOwnActions($session, reentrant: true)];
            $seen['during its run|outside'] = imOutside();
            if ($scenario === 'cancelled') {
                // A person stops the start's run, committed, before its first model step.
                imChild()->cancel((string) ImIds::$s);
            }
            if ($failsBeforeTaskRow) {
                if ($scenario === 'fails, undo fails') {
                    foreach (['UPDATE', 'DELETE'] as $op) {
                        DB::statement("CREATE TRIGGER im_undo_{$op} BEFORE {$op} ON harness_session_state
                            WHEN OLD.key LIKE '%::sub::worker:child' BEGIN SELECT RAISE(ABORT, 'outage'); END");
                    }
                }

                throw new RuntimeException('provider down');
            }
        }
        $tools = [(new Tool)->as('probe')->for('Probe.')->withStringParameter('text', 'Text')->using(fn (string $text): string => 'ok')];
        if ($danger) {
            $tools[] = (new Tool)->as('danger')->for('Danger.')->withStringParameter('text', 'Text')
                ->using(function (string $text): string {
                    test()->ran['danger']++;

                    return 'done';
                });
        }

        return $tools;
    }, name: 'matrix');

    $seen['before the gate|outside'] = imOutside();

    // DURING the start's events: a RunStarted listener on its run, a
    // ToolDenied listener for the call it ended, a RunFinished listener
    // (round 13) and a RunFailed listener.
    $failedCell = match ($scenario) {
        'fails, undone' => 'at RunFailed(S), undone',
        'fails, undo fails' => 'at RunFailed(S), undo failed',
        'fails after the task row' => 'at RunFailed(S), kept',
        default => null,
    };
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$seen): void {
        if (! ImIds::$outside && $event->runId === ImIds::$s && ! isset($seen['at RunStarted(S)|outside'])) {
            $seen['at RunStarted(S)|outside'] = imOutside();
        }
    });
    Event::listen(ToolDenied::class, function (ToolDenied $event) use (&$seen): void {
        if (! ImIds::$outside && str_contains($event->sessionKey, '::sub::worker') && ImIds::$s !== null && ! isset($seen['at ToolDenied(A0)|outside'])) {
            $seen['at ToolDenied(A0)|outside'] = imOutside();
        }
    });
    Event::listen(RunFinished::class, function (RunFinished $event) use (&$seen, &$kept): void {
        if (! ImIds::$outside && $event->runId === ImIds::$s && ! isset($seen['at RunFinished(S)|outside'])) {
            $seen['at RunFinished(S)|outside'] = imOutside();
            $seen['at RunFinished(S)|inside'] = $kept === null ? 'no inside reader' : [...imRead($kept, true), ...imOwnActions($kept)];
        }
    });
    Event::listen(RunFailed::class, function (RunFailed $event) use (&$seen, &$kept, $failedCell): void {
        if (! ImIds::$outside && $failedCell !== null && $event->runId === ImIds::$s && ! isset($seen[$failedCell.'|outside'])) {
            $seen[$failedCell.'|outside'] = imOutside();
            $seen[$failedCell.'|inside'] = $kept === null ? 'no inside reader' : [...imRead($kept, true), ...imOwnActions($kept)];
        }
    });

    // After (b): right after its last write, the provisional mode mark.
    $fired = false;
    DB::listen(function (QueryExecuted $query) use (&$fired, &$seen): void {
        if ($fired) {
            return;
        }
        foreach ($query->bindings as $binding) {
            if (str_contains((string) $binding, 'mode_provisional') && str_contains((string) $binding, '"mode":"work"')) {
                $fired = true;
                $seen['after (b)|outside'] = imOutside();

                return;
            }
        }
    });

    G360::$queue = [];
    G360::calls([['worker', ['task' => 'Start over']]]);
    if (in_array($scenario, ['completes', 'completes+', 'repeated ids', 'mixed repeated'], true)) {
        G360::calls([['danger', ['text' => 'now']]]);
        G360::text('Child done.');
    } elseif ($scenario === 'fails after the task row') {
        // Its first step is recorded, task row first; its second model request fails.
        G360::calls([['echo', ['text' => 'step one']]]);
        G360::$queue[] = G360::FAIL;
    }
    G360::text('Handled.');
    for ($i = 0; $i < 3; $i++) {
        G360::text('Pad.');
    }
    try {
        imRoot()->send('Go again');
    } catch (RunNotPermitted $stopped) {
        // A cancelled start cancels its tree: the root's next step is refused.
        expect($scenario)->toBe('cancelled')->and($stopped->code())->toBe('run_cancelled');
    }
    DB::statement('DROP TRIGGER IF EXISTS im_undo_UPDATE');
    DB::statement('DROP TRIGGER IF EXISTS im_undo_DELETE');

    if ($scenario === 'completes+') {
        $seen['after the task row|outside'] = imOutside();
        $seen['after the task row|inside'] = $kept === null ? 'no inside reader' : [...imRead($kept, true), ...imOwnActions($kept)];
        // A host turn on the child after the start completed, inside the
        // ceiling the start saved (the start took two of its three steps).
        G360::$queue = [];
        G360::text('More.');
        imChild()->send('More, please.');
    }

    $after = match ($scenario) {
        'completes', 'repeated ids', 'mixed repeated' => 'after the task row',
        'completes+' => 'after more turns',
        'fails, undone' => 'after a failed start, undone',
        'fails, undo fails' => 'after a failed start, undo failed',
        'fails after the task row' => 'after a failed start, kept',
        'cancelled' => 'after a cancelled start',
    };
    $seen[$after.'|outside'] = imOutside();
    $seen[$after.'|inside'] = $kept === null ? 'no inside reader' : [...imRead($kept, true), ...imOwnActions($kept)];

    $diff = [];
    foreach (imPhases($scenario) as $cell) {
        $want = imExpectedIn($scenario, $cell);
        $got = $seen[$cell] ?? 'never read';
        foreach ($want as $reader => $value) {
            $actual = is_array($got) ? (array_key_exists($reader, $got) ? $got[$reader] : 'missing') : $got;
            if ($actual !== $value) {
                $diff[] = $cell.' '.$reader.': expected '.json_encode($value).', got '.json_encode($actual);
            }
        }
    }

    expect($diff)->toBe([])
        ->and($this->ran['danger'])->toBe(0)
        ->and($this->ran['weather'])->toBe(0);
})->with(['completes', 'fails, undone', 'fails, undo fails',
    // Round 13.
    'completes+', 'repeated ids', 'mixed repeated', 'fails after the task row', 'cancelled']);

// Round 12 (the TS review's S5): two starts of the same child in ONE parent
// step run one after the other. Each records its own task row, the second
// reads the first as confirmed, and the child ends on the second's records.
it('runs two starts of one child in the same parent step one after the other', function (): void {
    G360::calls([['worker', ['task' => 'First']], ['worker', ['task' => 'Second']]]);
    G360::text('First done.');
    G360::text('Second done.');
    G360::text('Both handled.');

    imRoot()->send('Go');

    $tasks = imChild()->thread()->storedMessages()->where('type', 'user')->orderBy('position')->get()
        ->map(fn ($row): string => (string) ($row->payload['content'] ?? ''))->all();
    $outcomes = collect(G360::outputs())->filter(fn (string $out): bool => str_contains($out, '"outcome"'))->values()->all();

    expect($tasks)->toBe(['First', 'Second'])
        ->and(implode(' ', G360::outputs()))->not->toContain('"outcome":"failed"')
        ->and(substr_count(implode(' ', G360::outputs()), '"outcome":"completed"'))->toBe(2)
        ->and(imChild()->childBinding())->not->toBeNull()
        ->and(imChild()->mode())->toBe('work')
        ->and(app(RunTrees::class)->delegationBusy(imChild()))->toBeFalse()
        ->and($outcomes)->not->toBe([]);
});
