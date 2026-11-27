<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Models\Thread;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Plans\Plans;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Support\InRunRecording;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Support\G360;

// G-387 round 15: the write-ahead `started` row (DECIDED, option (i)), G-408,
// and the round-14 review findings, in PHP.
//
//  - Before the resolver runs an approved call, the runtime records a
//    harness-written `started` row for it, under the G-406 private token,
//    naming the call and carrying `harness_answers_step {position}`. A
//    superseding write (a host row, another run, a new message) treats a call
//    with a started row and no result as answered-in-progress: no replaced
//    denial, and the write is NEVER refused (no SessionLocked into a tool, no
//    lock wait). The real result row carries the same mark and pairs to its
//    call wherever it lands. A started call that never got a result reads
//    "interrupted, it may have run" and is never run again. No TTL.
//  - G-408: one rule for what is a step; a stored copy whose tool_calls are
//    not objects is never the paused step, and never makes the thread unusable.
//  - Thread::record's `endedByStart` / `parked` are runtime-only; a shared
//    thread takes a superseding row as it always did.
//
// Every interleaving is forced by a hook (a tool body, the scripted model's
// request hook), never by timing. No request leaves the process: G360 refuses
// stray requests and fakes every one from here.

const R15_REPLACED = 'Tool call was not run because a later step replaced it';
const R15_INTERRUPTED = 'Tool call was interrupted, it may have run';

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather', 'echo']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    foreach ([ModeRegistry::class, PrismHarness::class, AgentRuntime::class, SubagentRunner::class, RunTrees::class, Plans::class] as $class) {
        app()->forgetInstance($class);
    }
    $this->ran = [];
    $this->during = null;
    $ran = &$this->ran;
    $during = &$this->during;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran, &$during): string {
                $ran[] = $city;
                if ($during !== null) {
                    $hook = $during;
                    $during = null;
                    $hook();
                }

                return "Sunny in {$city}";
            }),
        (new Tool)->as('echo')->for('Echo.')->withStringParameter('text', 'Text')
            ->using(fn (string $text): string => "echo:{$text}"),
    ]);
});

function r15Session(): Session
{
    return G360::session();
}

/** @return array<string, list<string>> every stored result, by call id, oldest first (a multiset: repeats kept) */
function r15Outcomes(): array
{
    $out = [];
    foreach (r15Session()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            $out[$entry['tool_call_id']][] = is_string($entry['result']) ? $entry['result'] : json_encode($entry['result']);
        }
    }

    return $out;
}

/** @return array<string, list<string>> every stored weather result, by the city it was asked for, oldest first */
function r15ByCity(): array
{
    $out = [];
    foreach (r15Session()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            $out[$entry['tool_call_id'].'@'.($entry['args']['city'] ?? '')][] = (string) $entry['result'];
        }
    }

    return $out;
}

/** @return list<string> the stored row kinds, oldest first: a replaced denial reads `replaced`, a started row `started` */
function r15Rows(): array
{
    return r15Session()->thread()->storedMessages()->orderBy('position')->get()
        ->map(fn ($row): string => match (true) {
            array_key_exists('harness_superseded', $row->payload) => 'replaced',
            array_key_exists('harness_started', $row->payload) => 'started',
            default => (string) $row->type,
        })->all();
}

/** Park on one gated weather call (`call_paris`). @return string its approval id */
function r15Park(): string
{
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);

    return r15Session()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
}

/** What a write did: `recorded`, `locked`, a refusal code, or the exception's class. */
function r15Try(Closure $write): string
{
    try {
        $write();

        return 'recorded';
    } catch (SessionLocked) {
        return 'locked';
    } catch (ApprovalNotPending $refused) {
        return $refused->code();
    } catch (Throwable $other) {
        return $other::class;
    }
}

/** The tool outputs (function_call_output) of the last request, by call id, as a multiset. @return array<string, list<string>> */
function r15SentOutputs(): array
{
    $out = [];
    foreach (G360::lastRequest()['input'] ?? [] as $item) {
        if (($item['type'] ?? null) === 'function_call_output') {
            $out[$item['call_id']][] = (string) $item['output'];
        }
    }

    return $out;
}

/** Every text the stored rows and the requests sent carry, so a leak of an internal key shows. */
function r15AllText(): string
{
    $sent = '';
    foreach (Http::recorded() as [$request]) {
        $sent .= (string) $request->body();
    }

    return G360::threadText()."\n".$sent;
}

// ---------------------------------------------------------------------------
// 1. A row written WHILE an approved call runs: never refused, never a second outcome.
// ---------------------------------------------------------------------------

it('records a host row written while an approved call runs, and the call keeps its one real outcome', function (string $kind): void {
    $approval = r15Park();
    $host = null;
    $this->during = function () use (&$host, $kind): void {
        $host = r15Try(fn () => r15Session()->thread()->record([match ($kind) {
            'user row' => new UserMessage('A host row.'),
            'text row' => new AssistantMessage('A host answer.'),
            // Reusing the running call's id and tool, with other arguments.
            'tool-call step' => new AssistantMessage('', [new ToolCall('call_paris', 'weather', ['city' => 'Rome'])], [],
                [new ToolApprovalRequest('apr_host', 'call_paris')]),
        }]));
    };
    G360::text('Done.');

    r15Session()->decide([new ToolApprovalResponse($approval, true)]);

    // The host row is recorded (never SessionLocked); the call that ran has
    // exactly one stored outcome, its real result, and no replaced denial.
    expect($host)->toBe('recorded')
        ->and($this->ran)->toBe(['Paris'])
        ->and(r15Session()->approvalStatus($approval))->toBe('approved');
    if ($kind === 'tool-call step') {
        // The later step that reuses the id is never credited with the
        // earlier call's result: the run's own answer, recorded from outside
        // that host step, replaced it, so its one outcome is the denial.
        expect(r15ByCity())->toBe(['call_paris@Paris' => ['Sunny in Paris'], 'call_paris@Rome' => [R15_REPLACED]])
            ->and(r15Session()->thread()->pendingApprovalIds())->toBe([]);
    } else {
        expect(r15Outcomes())->toBe(['call_paris' => ['Sunny in Paris']]);
    }
})->with(['user row', 'text row', 'tool-call step']);

it('replays the result of a call that ran while a host step was recorded with its own step', function (): void {
    $approval = r15Park();
    $this->during = function (): void {
        r15Session()->thread()->record([new AssistantMessage('', [new ToolCall('call_paris', 'weather', ['city' => 'Rome'])], [],
            [new ToolApprovalRequest('apr_host', 'call_paris')])], toolNames: ['weather']);
    };
    G360::text('Done.');
    r15Session()->decide([new ToolApprovalResponse($approval, true)]);

    $replay = [];
    $segment = -1;
    foreach (r15Session()->thread()->messages() as $message) {
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $segment++;
        }
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $replay[] = [$segment, $result->args['city'] ?? null, (string) $result->result];
            }
        }
    }
    // Each step's own outcome, in its own segment: the earlier call's result
    // with its step (though stored after the host's step), the host step's
    // replaced denial with that step.
    expect($this->ran)->toBe(['Paris'])
        ->and($replay)->toBe([[0, 'Paris', 'Sunny in Paris'], [1, 'Rome', R15_REPLACED]]);
});

it('lets a host tool record on its OWN session mid-run without a lock error or a wait', function (string $kind): void {
    config()->set('prism-harness.agent.lock_wait', 5);
    $approval = r15Park();
    $failure = null;
    $lockQueries = 0;
    $this->during = function () use (&$failure, &$lockQueries, $kind): void {
        // Deterministic, never a clock: every query the host write makes is
        // seen, and none of them may touch a lock.
        DB::listen(function ($query) use (&$lockQueries): void {
            if (str_contains($query->sql, 'harness_session_state') && in_array(true, array_map(
                static fn (mixed $binding): bool => is_string($binding) && str_starts_with($binding, 'lock:'), $query->bindings), true)) {
                $lockQueries++;
            }
        });
        try {
            r15Session()->thread()->record([$kind === 'user row' ? new UserMessage('Tool note.')
                : new AssistantMessage('', [new ToolCall('note', 'echo', ['text' => 'n'])])]);
        } catch (Throwable $thrown) {
            $failure = $thrown;
        }
        DB::flushQueryLog();
        app('events')->forget(QueryExecuted::class);
    };
    G360::text('Done.');

    r15Session()->decide([new ToolApprovalResponse($approval, true)]);

    // No lock taken or waited on for the host's write (lock_wait is 5 s here),
    // and no error thrown into the tool.
    expect($failure)->toBeNull()
        ->and($lockQueries)->toBe(0)
        ->and(r15Outcomes()['call_paris'])->toBe(['Sunny in Paris']);
})->with(['user row', 'tool-call step']);

it('gives a call running when another run sends a new message exactly one outcome (a lapsed lease)', function (): void {
    $approval = r15Park();
    $sent = null;
    $this->during = function () use (&$sent): void {
        // The run's lease lapsed (a call outliving lock_ttl): another worker
        // takes the session and sends a new message. Deterministic: the lease
        // row is removed, exactly what its expiry leaves.
        DB::table('harness_session_state')->where('key', 'like', 'lock:%')->delete();
        $sent = r15Try(fn () => r15Session()->send('A new message.'));
    };
    G360::text('Answer to the new message.');
    G360::text('Done.');

    r15Try(fn () => r15Session()->decide([new ToolApprovalResponse($approval, true)]));

    expect($sent)->toBe('recorded')
        ->and($this->ran)->toBe(['Paris'])
        ->and(r15Outcomes())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r15Rows())->not->toContain('replaced');
});

it('never shows the model a lock error or an internal key from a tool that writes on its own session', function (): void {
    $approval = r15Park();
    $this->during = function (): void {
        r15Session()->thread()->record([new UserMessage('Tool note.')]);
    };
    G360::text('Done.');

    r15Session()->decide([new ToolApprovalResponse($approval, true)]);

    expect(r15SentOutputs())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r15AllText())->not->toContain('Could not acquire')
        ->and(r15AllText())->not->toContain('lock:');
});

// ---------------------------------------------------------------------------
// 2. The started row itself, and a crash after it.
// ---------------------------------------------------------------------------

it('records a started row for the approved call before it runs, in its own segment', function (): void {
    $approval = r15Park();
    $seen = null;
    $this->during = function () use (&$seen): void {
        $seen = r15Session()->thread()->storedMessages()->reorder('position', 'desc')->first();
    };
    G360::text('Done.');

    r15Session()->decide([new ToolApprovalResponse($approval, true)]);

    $step = r15Session()->thread()->storedMessages()->where('type', 'assistant')->orderBy('position')->first();
    expect($seen->type)->toBe('tool_result')
        ->and(json_encode($seen->payload))->toBe(json_encode([
            'tool_results' => [], 'tool_approval_responses' => [],
            'harness_started' => ['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'approval_id' => $approval],
            'harness_answers_step' => ['position' => (int) $step->position],
        ]))
        ->and($seen->run_id)->not->toBeNull();
    // The real result row names the same step, as its last key.
    $result = r15Session()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get()
        ->first(fn ($row): bool => ($row->payload['tool_results'] ?? []) !== []);
    expect(array_key_last($result->payload))->toBe('harness_answers_step')
        ->and($result->payload['harness_answers_step'])->toBe(['position' => (int) $step->position]);
});

it('reads a started call that never got a result as interrupted, and never runs it again', function (string $path): void {
    $approval = r15Park();
    $kept = null;
    // The worker dies while the tool runs: everything written after the
    // started row is lost, exactly what a crash leaves.
    $this->during = function () use (&$kept): void {
        $kept = (int) r15Session()->thread()->storedMessages()->max('position');
    };
    G360::text('Done.');
    r15Session()->decide([new ToolApprovalResponse($approval, true)]);
    r15Session()->thread()->storedMessages()->where('position', '>', $kept)->delete();
    expect(r15Rows())->toBe(['user', 'assistant', 'tool_result', 'started']);

    expect(r15Session()->approvalStatus($approval))->toBe('interrupted')
        ->and(r15Session()->thread()->pendingApprovalIds())->toBe([]);

    // Three resumes: the call never runs again, and the model is told it was
    // interrupted (never "not run").
    for ($i = 0; $i < 3; $i++) {
        G360::text('Resumed.');
        $path === 'send' ? r15Session()->send('') : iterator_to_array(r15Session()->streamSteps(''), false);
    }
    expect($this->ran)->toBe(['Paris'])
        ->and(r15SentOutputs())->toBe(['call_paris' => [R15_INTERRUPTED]])
        ->and(r15Outcomes())->toBe([])
        ->and(r15Session()->approvalStatus($approval))->toBe('interrupted');

    // A second decision on it is refused: running it again needs a fresh approval.
    expect(r15Try(fn () => r15Session()->decide([new ToolApprovalResponse($approval, true)])))->not->toBe('recorded')
        ->and($this->ran)->toBe(['Paris']);
})->with(['send', 'streamSteps']);

it('gives an interrupted call no replaced denial when a later row supersedes its step', function (): void {
    $approval = r15Park();
    $kept = null;
    $this->during = function () use (&$kept): void {
        $kept = (int) r15Session()->thread()->storedMessages()->max('position');
    };
    G360::text('Done.');
    r15Session()->decide([new ToolApprovalResponse($approval, true)]);
    r15Session()->thread()->storedMessages()->where('position', '>', $kept)->delete();

    r15Session()->thread()->record([new UserMessage('Something else.')]);

    expect(r15Rows())->not->toContain('replaced')
        ->and(r15Outcomes())->toBe([])
        ->and(r15Session()->approvalStatus($approval))->toBe('interrupted');
});

it('pairs a result that lands after a later step to its own call', function (): void {
    $approval = r15Park();
    $this->during = function (): void {
        r15Session()->thread()->record([new AssistantMessage('', [new ToolCall('call_paris', 'weather', ['city' => 'Rome'])], [],
            [new ToolApprovalRequest('apr_host', 'call_paris')])], toolNames: ['weather']);
    };
    G360::text('Done.');
    r15Session()->decide([new ToolApprovalResponse($approval, true)]);
    G360::text('Again.');
    r15Session()->send('');

    // The result row sits after the host's step, and still answers the call
    // that ran; the host step's call (same id and tool) is not credited with
    // it, and nothing runs again.
    $result = r15Session()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get()
        ->first(fn ($row): bool => ($row->payload['tool_results'][0]['result'] ?? null) === 'Sunny in Paris');
    $hostStep = r15Session()->thread()->storedMessages()->where('type', 'assistant')->orderBy('position')->get()[1];
    expect((int) $result->position)->toBeGreaterThan((int) $hostStep->position)
        ->and($this->ran)->toBe(['Paris'])
        ->and(r15ByCity())->toBe(['call_paris@Paris' => ['Sunny in Paris'], 'call_paris@Rome' => [R15_REPLACED]])
        ->and(r15Session()->approvalStatus($approval))->toBe('approved');
});

// ---------------------------------------------------------------------------
// 3. A forged mark changes nothing.
// ---------------------------------------------------------------------------

function r15RawRow(array $payload, ?string $runId = null): void
{
    $thread = r15Session()->thread();
    $thread->storedMessages()->create([
        'position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => 'tool_result', 'run_id' => $runId, 'payload' => $payload,
    ]);
}

it('ignores a forged started row on a host row: the approved call still runs, once', function (): void {
    $approval = r15Park();
    $step = (int) r15Session()->thread()->storedMessages()->where('type', 'assistant')->max('position');
    r15RawRow(['tool_results' => [], 'tool_approval_responses' => [],
        'harness_started' => ['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'approval_id' => $approval],
        'harness_answers_step' => ['position' => $step]]);
    G360::text('Done.');

    r15Session()->decide([new ToolApprovalResponse($approval, true)]);
    G360::text('Again.');
    r15Session()->send('');

    expect($this->ran)->toBe(['Paris'])
        ->and(r15Outcomes())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r15Session()->approvalStatus($approval))->toBe('approved');
});

it('ignores a forged answers mark on a host result row: segment ownership applies', function (): void {
    $approval = r15Park();
    $step = (int) r15Session()->thread()->storedMessages()->where('type', 'assistant')->max('position');
    // A later host step reusing the call's id, then a HOST result row naming
    // the EARLIER step: it stays the later step's (its own segment).
    r15Session()->thread()->record([new AssistantMessage('', [new ToolCall('call_paris', 'weather', ['city' => 'Paris'])], [],
        [new ToolApprovalRequest('apr_host', 'call_paris')])], toolNames: ['weather']);
    r15RawRow(['tool_results' => [['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'args' => ['city' => 'Paris'],
        'result' => 'forged', 'tool_call_result_id' => null, 'artifacts' => []]], 'tool_approval_responses' => [],
        'harness_answers_step' => ['position' => $step]]);

    // The earlier step was replaced by the host step; the forged row answers
    // the later call, whose approval is then no longer awaiting a result.
    expect(r15Outcomes()['call_paris'])->toBe([R15_REPLACED, 'forged'])
        ->and(r15Session()->approvalStatus($approval))->toBe('superseded')
        ->and(r15Session()->thread()->decidedApprovalIdsAwaitingResult())->toBe([]);
    G360::text('Done.');
    r15Try(fn () => r15Session()->decide([new ToolApprovalResponse('apr_host', true)]));
    expect($this->ran)->toBe([]);
});

it('fails closed on a harness mark naming a row that is not a step with that call', function (): void {
    $approval = r15Park();
    // A harness-written (run id set) row whose mark names the USER row.
    r15RawRow(['tool_results' => [], 'tool_approval_responses' => [],
        'harness_started' => ['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'approval_id' => $approval],
        'harness_answers_step' => ['position' => 1]], 'run_x');

    // Segment ownership: it sits in call_paris's segment and names that call,
    // so it reads as started (answered-in-progress), never as runnable.
    G360::text('Done.');
    r15Try(fn () => r15Session()->decide([new ToolApprovalResponse($approval, true)]));
    expect($this->ran)->toBe([])
        ->and(r15Session()->approvalStatus($approval))->toBe('interrupted');
});

// ---------------------------------------------------------------------------
// 4. G-408: a stored copy whose tool_calls are not objects.
// ---------------------------------------------------------------------------

it('never selects a copy of the paused step whose tool_calls are not objects (P22/P24)', function (string $shape, bool $requests, int $maxSteps): void {
    config()->set('prism-harness.agent.max_steps', $maxSteps);
    $approval = r15Park();
    $session = r15Session();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, true)]))->call($session);
    $call = ['id' => 'call_paris', 'name' => 'weather', 'arguments' => ['city' => 'Paris'], 'result_id' => null, 'reasoning_id' => null, 'reasoning_summary' => null];
    $calls = match ($shape) {
        'a JSON string' => json_encode([$call]),
        'a number' => 7,
        'null' => null,
        'a list of strings' => ['call_paris', 'weather'],
        'a list of JSON-string calls' => [json_encode($call)],
        'objects mixed with strings' => [$call, 'weather'],
    };
    $thread = r15Session()->thread();
    $thread->storedMessages()->create([
        'position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => 'assistant', 'run_id' => null,
        'payload' => ['content' => '', 'tool_calls' => $calls, 'additional_content' => [],
            'tool_approval_requests' => $requests ? [['approval_id' => $approval, 'tool_call_id' => 'call_paris']] : []],
    ]);

    $entries = [];
    for ($i = 0; $i < 3; $i++) {
        G360::text('Resumed.');
        $turn = r15Try(fn () => r15Session()->send(''));
        expect($turn)->not->toBe(TypeError::class);
        $entries[] = array_sum(array_map('count', r15Outcomes()));
    }

    // The copy is not a step for any reader: nothing is paused at it, so
    // nothing is pending on it, the thread is usable, the approved call never
    // runs twice, and no resume adds an outcome after the first.
    expect(r15Session()->thread()->pendingApprovalIds())->toBe([])
        ->and(count($this->ran))->toBeLessThanOrEqual(1)
        ->and(count(r15Outcomes()['call_paris'] ?? []))->toBeLessThanOrEqual(1)
        ->and($entries[1])->toBe($entries[0])
        ->and($entries[2])->toBe($entries[0]);
    foreach (r15Session()->thread()->messages() as $message) {
        expect($message)->toBeInstanceOf(Message::class);
    }
})->with([
    'a JSON string', 'a number', 'null', 'a list of strings', 'a list of JSON-string calls', 'objects mixed with strings',
])->with([true, false])->with([6, 1]);

// ---------------------------------------------------------------------------
// 5. Thread::record's runtime-only parameters (PHP run17 #1), and shared threads (#2).
// ---------------------------------------------------------------------------

it('refuses the ended-by-start and parked parameters from anyone but the runtime (Z2)', function (string $param): void {
    r15Park();
    $before = r15Rows();
    $ended = new ToolResultMessage([]);

    $outcome = r15Try(fn () => $param === 'endedByStart'
        ? r15Session()->thread()->record([new UserMessage('Next.'), $ended], endedByStart: [['message' => $ended, 'approvals' => []]])
        : r15Session()->thread()->record([new UserMessage('Next.')], parked: ['harness_steps_used' => 0, 'harness_steps_left' => 9]));

    expect($outcome)->toBe(InvalidArgumentException::class)
        ->and(r15Rows())->toBe($before)
        ->and(r15Session()->thread()->pendingApprovalIds())->toHaveCount(1);
})->with(['endedByStart', 'parked']);

it('records a superseding host row on a shared thread with its replaced denial (Z3)', function (): void {
    $thread = Thread::shared('admin-review');
    $thread->record([
        new UserMessage('Review this.'),
        new AssistantMessage('', [new ToolCall('call_x', 'weather', ['city' => 'Oslo'])], [], [new ToolApprovalRequest('apr_x', 'call_x')]),
    ]);

    $outcome = r15Try(fn () => Thread::shared('admin-review')->record([new UserMessage('Next.')]));

    $rows = Thread::shared('admin-review')->storedMessages()->orderBy('position')->get()
        ->map(fn ($row): string => array_key_exists('harness_superseded', $row->payload) ? 'replaced' : (string) $row->type)->all();
    expect($outcome)->toBe('recorded')
        ->and($rows)->toBe(['user', 'assistant', 'replaced', 'user']);
});

// ---------------------------------------------------------------------------
// 6. An approval belongs to the step that FIRST asked for it, bound to the
//    exact call the person was shown (G-408; the round-15 design rule).
// ---------------------------------------------------------------------------

/** Store a copy of the parked step, raw, as another writer could. */
function r15Copy(array $calls, bool $keepRequest, string $approval): void
{
    $thread = r15Session()->thread();
    $thread->storedMessages()->create([
        'position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => 'assistant', 'run_id' => null,
        'payload' => ['content' => '', 'tool_calls' => $calls, 'additional_content' => [],
            'tool_approval_requests' => $keepRequest ? [['approval_id' => $approval, 'tool_call_id' => 'call_paris']] : []],
    ]);
}

/** @return array<string, mixed> a stored weather call */
function r15Call(string $city, string $id = 'call_paris'): array
{
    return ['id' => $id, 'name' => 'weather', 'arguments' => ['city' => $city], 'result_id' => null, 'reasoning_id' => null, 'reasoning_summary' => null];
}

it('binds every stored approval request to its call: tool name and the digest of its arguments, last', function (): void {
    $approval = r15Park();
    $step = r15Session()->thread()->storedMessages()->where('type', 'assistant')->orderBy('position')->first();

    expect(json_encode($step->payload['tool_approval_requests']))->toBe(json_encode([[
        'approval_id' => $approval, 'tool_call_id' => 'call_paris', 'arguments_format' => 1,
        'harness_call' => ['tool_name' => 'weather', 'args_digest' => 'sha256:'.hash('sha256', '{"city":"Paris"}')],
    ]]));
});

it('never lends an approval to a copy of its step whose call was altered', function (string $order, string $copy): void {
    $approval = r15Park();
    $calls = match ($copy) {
        'altered arguments' => [r15Call('Rome')],
        'the old and the altered call' => [r15Call('Paris'), r15Call('Rome')],
        'non-objects and a new call' => ['x', r15Call('Rome', 'call_new'), null, 3],
    };
    $session = r15Session();
    if ($order === 'after the approval') {
        (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, true)]))->call($session);
    }
    r15Copy($calls, true, $approval);
    $decision = 'recorded';
    if ($order === 'before the approval') {
        $later = r15Session();
        $decision = r15Try(fn () => (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, true)]))->call($later));
    }
    for ($i = 0; $i < 3; $i++) {
        G360::text('Resumed.');
        r15Try(fn () => r15Session()->send(''));
    }

    // The copy's request asks for nothing (the approval is its FIRST step's),
    // so no altered or new call runs, before or after the approval, and the
    // approval is not decidable on the copy.
    expect($this->ran)->toBe([])
        ->and($decision)->toBe($order === 'before the approval' ? 'approval_not_pending' : 'recorded')
        ->and(r15Session()->thread()->pendingApprovalIds())->toBe([]);
})->with(['before the approval', 'after the approval'])->with(['altered arguments', 'the old and the altered call', 'non-objects and a new call']);

it('does not run a call whose stored step no longer matches the request it was bound to', function (): void {
    $approval = r15Park();
    // The parked step's call is altered in place after it parked; its request
    // still carries the binding of the call the person was shown.
    $row = r15Session()->thread()->storedMessages()->where('type', 'assistant')->orderBy('position')->first();
    $payload = $row->payload;
    $payload['tool_calls'][0]['arguments'] = ['city' => 'Rome'];
    $row->forceFill(['payload' => $payload])->save();
    G360::text('Done.');

    r15Try(fn () => r15Session()->decide([new ToolApprovalResponse($approval, true)]));

    expect($this->ran)->toBe([])
        ->and(count(r15Outcomes()['call_paris'] ?? []))->toBe(1);
});

it('upgrades a request stored before bindings were kept: it binds to its own step\'s call, and runs it once', function (): void {
    $approval = r15Park();
    $row = r15Session()->thread()->storedMessages()->where('type', 'assistant')->orderBy('position')->first();
    $payload = $row->payload;
    unset($payload['tool_approval_requests'][0]['harness_call']);
    $row->forceFill(['payload' => $payload])->save();
    G360::text('Done.');
    r15Session()->decide([new ToolApprovalResponse($approval, true)]);
    G360::text('Again.');
    r15Session()->send('');

    expect($this->ran)->toBe(['Paris'])
        ->and(r15Outcomes())->toBe(['call_paris' => ['Sunny in Paris']]);
});

// ---------------------------------------------------------------------------
// 7. Threads an earlier version wrote: a replaced step reads replaced, once.
// ---------------------------------------------------------------------------

it('reads a step an earlier version replaced without an outcome as replaced, and never runs it', function (string $shape): void {
    $approval = r15Park();
    $session = r15Session();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, true)]))->call($session);
    // The rows 0.26.x wrote before it kept the replaced denial: the
    // superseding row alone (no denial, no mark), raw.
    $thread = r15Session()->thread();
    $thread->storedMessages()->create([
        'position' => (int) $thread->storedMessages()->max('position') + 1,
        'type' => $shape === 'a user row' ? 'user' : 'assistant', 'run_id' => null,
        'payload' => $shape === 'a user row'
            ? ['content' => 'Something else.', 'additional_content' => [], 'additional_attributes' => []]
            : ['content' => '', 'tool_calls' => [r15Call('Oslo', 'call_later')], 'additional_content' => [], 'tool_approval_requests' => []],
    ]);

    $decision = r15Try(fn () => r15Session()->decide([new ToolApprovalResponse($approval, true)]));
    for ($i = 0; $i < 2; $i++) {
        G360::text('Next.');
        r15Try(fn () => r15Session()->send($shape === 'a user row' ? '' : 'Go on.'));
    }

    $replayed = [];
    foreach (r15Session()->thread()->messages() as $message) {
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                if ($result->toolCallId === 'call_paris') {
                    $replayed[] = (string) $result->result;
                }
            }
        }
    }
    expect($this->ran)->toBe([])
        ->and($decision)->not->toBe('recorded')
        ->and($replayed)->toBe([R15_REPLACED])
        ->and(count(r15Outcomes()['call_paris'] ?? []))->toBeLessThanOrEqual(1)
        ->and(r15Session()->approvalStatus($approval))->toBe('superseded');
})->with(['a user row', 'a later step']);

// ---------------------------------------------------------------------------
// 8. A recorded denial is final (G-401, the recommended option).
// ---------------------------------------------------------------------------

it('never runs a call whose denial was stored when its resume was refused, whatever is approved after (a)', function (string $then): void {
    $approval = r15Park();
    $session = r15Session();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, false, 'No.')]))->call($session);
    // Another worker holds the session: the resume is refused SessionLocked,
    // the denial stays durable.
    DB::table('harness_session_state')->insert(['key' => 'lock:'.$session->key(), 'payload' => json_encode(['locked' => true, 'owner' => 'other']),
        'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
    $locked = r15Try(fn () => r15Session()->send(''));
    DB::table('harness_session_state')->where('key', 'lock:'.$session->key())->delete();
    G360::text('Done.');
    G360::text('Again.');

    $after = r15Session();
    $approve = r15Try(fn () => $then === 'decide' ? $after->decide([new ToolApprovalResponse($approval, true)])
        : (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, true)]))->call($after));
    r15Try(fn () => r15Session()->send(''));

    expect($locked)->toBe('locked')
        ->and($approve)->toBe('approval_not_pending')
        ->and($this->ran)->toBe([])
        ->and(r15Outcomes())->toBe(['call_paris' => ['No.']])
        ->and(r15Session()->approvalStatus($approval))->toBe('denied');
})->with(['decide', 'recordDecisions']);

it('denies a call whose approval no run acted on when a denial arrives after it (d)', function (): void {
    $approval = r15Park();
    $session = r15Session();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, true)]))->call($session);
    G360::text('Done.');

    $deny = r15Try(fn () => r15Session()->decide([new ToolApprovalResponse($approval, false, 'Changed my mind.')]));
    G360::text('Again.');
    r15Try(fn () => r15Session()->send(''));

    expect($deny)->toBe('recorded')
        ->and($this->ran)->toBe([])
        ->and(r15Outcomes())->toBe(['call_paris' => ['Changed my mind.']])
        ->and(r15Session()->approvalStatus($approval))->toBe('denied');
});

it('refuses a denial for an approval whose call already ran', function (): void {
    $approval = r15Park();
    G360::text('Done.');
    r15Session()->decide([new ToolApprovalResponse($approval, true)]);

    expect(r15Try(fn () => r15Session()->decide([new ToolApprovalResponse($approval, false)])))->toBe('approval_not_pending')
        ->and(r15Outcomes())->toBe(['call_paris' => ['Sunny in Paris']]);
});

// ---------------------------------------------------------------------------
// 9. Clearing state, and a parked step that mixes object calls with others.
// ---------------------------------------------------------------------------

it('keeps an interrupted call interrupted when the session state is cleared: never "not run", never re-run', function (): void {
    $approval = r15Park();
    $kept = null;
    $this->during = function () use (&$kept): void {
        $kept = (int) r15Session()->thread()->storedMessages()->max('position');
    };
    G360::text('Done.');
    r15Session()->decide([new ToolApprovalResponse($approval, true)]);
    r15Session()->thread()->storedMessages()->where('position', '>', $kept)->delete();

    // The ephemeral half is dropped; the started row is a thread row and stays.
    r15Session()->forget();
    G360::text('Resumed.');
    r15Try(fn () => r15Session()->send(''));

    expect($this->ran)->toBe(['Paris'])
        ->and(r15Session()->approvalStatus($approval))->toBe('interrupted')
        ->and(r15SentOutputs())->toBe(['call_paris' => [R15_INTERRUPTED]]);
});

it('reads a parked step whose tool_calls mix an object call with non-objects as a step: its object call is decided once', function (): void {
    $thread = r15Session()->thread();
    $thread->storedMessages()->create(['position' => 1, 'type' => 'user', 'run_id' => null,
        'payload' => ['content' => 'Weather?', 'additional_content' => [], 'additional_attributes' => []]]);
    $thread->storedMessages()->create(['position' => 2, 'type' => 'assistant', 'run_id' => null,
        'payload' => ['content' => '', 'tool_calls' => ['x', r15Call('Paris'), null, 3], 'additional_content' => [],
            'tool_approval_requests' => [['approval_id' => 'apr_mix', 'tool_call_id' => 'call_paris']], 'harness_tool_names' => ['weather']]]);
    G360::text('Done.');

    expect(r15Session()->thread()->pendingApprovalIds())->toBe(['apr_mix']);
    r15Session()->decide([new ToolApprovalResponse('apr_mix', true)]);
    G360::text('Again.');
    r15Session()->send('');

    expect($this->ran)->toBe(['Paris'])
        ->and(r15Outcomes())->toBe(['call_paris' => ['Sunny in Paris']]);
});

it('refuses to start a call whose standing decision is no longer an approval', function (bool $denied): void {
    $approval = r15Park();
    $session = r15Session();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, true)]))->call($session);
    if ($denied) {
        // A denial that landed after the run read its history.
        r15RawRow(['tool_results' => [], 'tool_approval_responses' => [['approval_id' => $approval, 'approved' => false, 'reason' => 'No.']]]);
    }
    $thread = r15Session()->thread();
    $position = (int) $thread->storedMessages()->where('type', 'assistant')->max('position');
    $start = fn (): bool => $thread->recordStarted('run_gate', $position, 'call_paris', 'weather', ['city' => 'Paris']);

    $started = (Closure::bind(static fn (): mixed => InRunRecording::during('run_gate', $start), null, InRunRecording::class))();

    expect($started)->toBe(! $denied);
})->with([true, false]);

it('refuses a started row from anyone but the runtime', function (): void {
    r15Park();
    $thread = r15Session()->thread();

    expect(r15Try(fn () => $thread->recordStarted('run_x', 2, 'call_paris', 'weather', ['city' => 'Paris'])))->toBe(LogicException::class)
        ->and(r15Rows())->not->toContain('started');
});
