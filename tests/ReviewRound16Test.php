<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Models\Thread;
use Prism\Harness\Models\ThreadMessage;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Plans\Plans;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Support\G360;

// G-387 round 16: the round-15 review findings, in PHP (the reference).
//
//  1. A decision counts only while its call has no started row and no
//     outcome: a raw denial recorded after a call started or ran changes
//     nothing, on every reader and in the replay (item 10: the replay shows
//     the decision that STOOD when the call was resolved).
//  2. An approval whose call already has an outcome is never pending.
//  3. Every call, gated or not, has exactly one honest outcome: an ungated
//     call that ran is never left with none (a write-ahead journal row), the
//     replay gives every call of a step no longer paused with no outcome an
//     outcome, and a run stops before another model step once a newer step
//     on its session is waiting on approval (a nested park).
//  4. ONE trust signal: only rows the runtime wrote (the `harness_written`
//     column, which no public write sets) carry harness marks. A run id is
//     never a trust signal.
//  5. No host code runs inside the runtime's private recording window.
//  6. No error text that can reach the model names a session key.
//  7. A resume reads the thread a fixed number of times, whatever its length.
//
// Every interleaving is forced by a hook (a tool body, a model event), never
// by timing. No request leaves the process: G360 refuses stray requests and
// fakes every one from here.

const R16_REPLACED = 'Tool call was not run because a later step replaced it';
const R16_INTERRUPTED = 'Tool call was interrupted, it may have run';

beforeEach(function (): void {
    Http::preventStrayRequests();
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
    $this->echoed = [];
    $this->during = null;
    $ran = &$this->ran;
    $echoed = &$this->echoed;
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
            ->using(function (string $text) use (&$echoed): string {
                $echoed[] = $text;

                return "echo:{$text}";
            }),
    ]);
});

function r16Session(): Session
{
    return G360::session($GLOBALS['r16Participant'] ?? 'Ada');
}

/** Park on one gated weather call (`call_paris`). @return string its approval id */
function r16Park(): string
{
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);

    return r16Session()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
}

/** @return array<string, list<string>> every stored result, by call id, oldest first (a multiset) */
function r16Outcomes(): array
{
    $out = [];
    foreach (r16Session()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            $out[$entry['tool_call_id']][] = is_string($entry['result']) ? $entry['result'] : json_encode($entry['result']);
        }
    }

    return $out;
}

/** What a write did: `recorded`, `locked`, a refusal code, or the exception's class. */
function r16Try(Closure $write): string
{
    try {
        $write();

        return 'recorded';
    } catch (SessionLocked) {
        return 'locked';
    } catch (ApprovalNotPending $refused) {
        return $refused->code();
    } catch (RunNotPermitted $refused) {
        return 'run_not_permitted:'.$refused->code();
    } catch (Throwable $other) {
        return $other::class;
    }
}

/** A row stored raw, as another writer with table access can (never through Thread::record()). */
function r16Raw(string $type, array $payload, ?string $runId = null): void
{
    $thread = r16Session()->thread();
    $thread->storedMessages()->create([
        'position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => $type, 'run_id' => $runId, 'payload' => $payload,
        // A raw write may NAME the column; mass assignment never sets it.
        'harness_written' => true,
    ]);
}

/** The decisions the replay carries for one approval, oldest first. @return list<bool> */
function r16ReplayedDecisions(string $approval): array
{
    $out = [];
    foreach (r16Session()->thread()->messages() as $message) {
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolApprovalResponses as $response) {
                if ($response->approvalId === $approval) {
                    $out[] = $response->approved;
                }
            }
        }
    }

    return $out;
}

/** The run id of the parked step. */
function r16ParkedRun(): string
{
    return (string) r16Session()->thread()->storedMessages()->where('type', 'assistant')->orderBy('position', 'desc')->value('run_id');
}

/** Whether every tool call of the last request has exactly one output. */
function r16RequestAnswersEveryCall(): bool
{
    $calls = [];
    $outputs = [];
    foreach (G360::lastRequest()['input'] ?? [] as $item) {
        if (($item['type'] ?? null) === 'function_call') {
            $calls[] = $item['call_id'];
        }
        if (($item['type'] ?? null) === 'function_call_output') {
            $outputs[] = $item['call_id'];
        }
    }
    sort($calls);
    sort($outputs);

    return $calls === $outputs;
}

// ---------------------------------------------------------------------------
// 1 + 10. A decision counts only while its call has no started row and no outcome.
// ---------------------------------------------------------------------------

it('ignores a raw denial recorded while an approved call runs: the call ran, and every reader says approved', function (): void {
    $approval = r16Park();
    $this->during = function () use ($approval): void {
        r16Raw('tool_result', ['tool_results' => [], 'tool_approval_responses' => [['approval_id' => $approval, 'approved' => false, 'reason' => 'No.']]]);
    };
    G360::text('Done.');

    r16Session()->decide([new ToolApprovalResponse($approval, true)]);

    expect($this->ran)->toBe(['Paris'])
        ->and(r16Outcomes())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r16Session()->approvalStatus($approval))->toBe('approved')
        ->and(r16Session()->thread()->approvalDecisions())->toBe([$approval => true])
        ->and(r16ReplayedDecisions($approval))->not->toContain(false);
});

it('ignores a raw denial recorded after an approved call ran, on every reader and in the replay (item 10)', function (): void {
    $approval = r16Park();
    G360::text('Done.');
    r16Session()->decide([new ToolApprovalResponse($approval, true)]);

    r16Raw('tool_result', ['tool_results' => [], 'tool_approval_responses' => [['approval_id' => $approval, 'approved' => false, 'reason' => 'Late.']]]);

    expect(r16Session()->approvalStatus($approval))->toBe('approved')
        ->and(r16Session()->thread()->approvalDecisions())->toBe([$approval => true])
        ->and(r16ReplayedDecisions($approval))->not->toBeEmpty()
        ->and(r16ReplayedDecisions($approval))->not->toContain(false);
    G360::text('Again.');
    r16Session()->send('Thanks.');
    expect($this->ran)->toBe(['Paris']);
});

it('replays a denial that stood when its call was resolved, never an approval recorded after the outcome (item 10)', function (): void {
    $approval = r16Park();
    G360::text('Done.');
    r16Session()->decide([new ToolApprovalResponse($approval, false, 'No.')]);

    // Written after the call's denial is its outcome: it never stood.
    r16Raw('tool_result', ['tool_results' => [], 'tool_approval_responses' => [['approval_id' => $approval, 'approved' => true, 'reason' => null]]]);

    expect(r16Session()->approvalStatus($approval))->toBe('denied')
        ->and(r16Session()->thread()->approvalDecisions())->toBe([$approval => false])
        ->and(r16ReplayedDecisions($approval))->not->toContain(true);
});

// ---------------------------------------------------------------------------
// 2. A closed call's approval is never pending.
// ---------------------------------------------------------------------------

it('never lists an approval as pending once its call has an outcome, and refuses a decision on it', function (string $how): void {
    $approval = r16Park();
    if ($how === 'a host result') {
        r16Session()->thread()->record([new ToolResultMessage([new ToolResult('call_paris', 'weather', ['city' => 'Paris'], 'Done by hand.')])]);
    } else {
        // A raw started row the harness wrote, with no result: interrupted.
        $step = (int) r16Session()->thread()->storedMessages()->where('type', 'assistant')->max('position');
        $thread = r16Session()->thread();
        $row = $thread->storedMessages()->make(['position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => 'tool_result',
            'run_id' => r16ParkedRun(), 'payload' => ['tool_results' => [], 'tool_approval_responses' => [],
                'harness_started' => ['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'approval_id' => $approval],
                'harness_answers_step' => ['position' => $step]]]);
        $row->forceFill(['harness_written' => true])->save();
    }

    G360::text('Done.');
    $decision = r16Try(fn () => r16Session()->decide([new ToolApprovalResponse($approval, true)]));

    expect(r16Session()->thread()->pendingApprovalIds())->toBe([])
        ->and($decision)->toBe('approval_not_pending')
        ->and($this->ran)->toBe([]);
})->with(['a host result', 'a started row']);

// ---------------------------------------------------------------------------
// 3. Ungated calls, the replay of a call with no outcome, and a nested park.
// ---------------------------------------------------------------------------

it('never leaves an ungated call that ran without an outcome when its results write fails (T4)', function (string $path): void {
    G360::calls([['echo', ['text' => 'hi'], 'call_echo'], ['weather', ['city' => 'Paris'], 'call_paris']]);
    // The write of the turn that holds the echo's result fails.
    ThreadMessage::creating(function (ThreadMessage $row): void {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            if (($entry['result'] ?? null) === 'echo:hi') {
                throw new RuntimeException('store down');
            }
        }
    });
    $turn = r16Try(fn () => $path === 'send' ? r16Session()->send('Echo hi and the weather.')
        : iterator_to_array(r16Session()->streamSteps('Echo hi and the weather.'), false));
    ThreadMessage::flushEventListeners();

    G360::text('Next.');
    r16Try(fn () => r16Session()->send('Go on.'));

    // The echo ran once; the thread says so honestly (it may have run), and
    // the model is told the same, in a request where every call is answered.
    $told = [];
    foreach (G360::lastRequest()['input'] ?? [] as $item) {
        if (($item['type'] ?? null) === 'function_call_output') {
            $told[] = (string) $item['output'];
        }
    }
    expect($turn)->not->toBe('recorded')
        ->and($this->echoed)->toBe(['hi'])
        ->and($told)->toContain(R16_INTERRUPTED)
        ->and(r16RequestAnswersEveryCall())->toBeTrue();
})->with(['send', 'streamSteps']);

it('refuses a nested send by a tool on its session while its run holds it: no newer step parks (Py run20 F3, PHP)', function (): void {
    config()->set('prism-harness.agent.lock_wait', 0);
    $approval = r16Park();
    $inner = null;
    $this->during = function () use (&$inner): void {
        $inner = r16Try(fn () => r16Session()->send('Inner.'));
    };
    G360::text('Done.');

    r16Session()->decide([new ToolApprovalResponse($approval, true)]);

    // PHP holds the session lock for the whole run, so a nested send is
    // refused at once; the outer run completes with every call answered.
    expect($inner)->toBe('locked')
        ->and($this->ran)->toBe(['Paris'])
        ->and(r16Outcomes())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r16RequestAnswersEveryCall())->toBeTrue();
});

it('gives every call of a step no longer paused that has no outcome one in the replay', function (): void {
    $thread = r16Session()->thread();
    $thread->record([
        new UserMessage('Go.'),
        new AssistantMessage('', [new ToolCall('u1', 'echo', ['text' => 'a']), new ToolCall('c1', 'weather', ['city' => 'Rome'])], [],
            [new ToolApprovalRequest('apr_c1', 'c1')]),
        new ToolResultMessage([new ToolResult('c1', 'weather', ['city' => 'Rome'], 'Sunny in Rome')], [new ToolApprovalResponse('apr_c1', true)]),
        new AssistantMessage('Done.'),
    ]);

    $results = [];
    foreach (r16Session()->thread()->messages() as $message) {
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $results[$result->toolCallId][] = (string) $result->result;
            }
        }
    }
    // The ungated call has no result of its own: it may have run.
    ksort($results);
    expect($results)->toBe(['c1' => ['Sunny in Rome'], 'u1' => [R16_INTERRUPTED]]);
});

it('stops a run before another model step once a newer step on its session waits on approval (a nested park)', function (): void {
    $approval = r16Park();
    $this->during = function (): void {
        // A step another writer parks on this session while the call runs.
        r16Session()->thread()->record([new AssistantMessage('', [new ToolCall('call_inner', 'weather', ['city' => 'Rome'])], [],
            [new ToolApprovalRequest('apr_inner', 'call_inner')])], toolNames: ['weather']);
    };
    G360::text('Done.');
    $requests = count(Http::recorded());

    $outcome = r16Try(fn () => r16Session()->decide([new ToolApprovalResponse($approval, true)]));

    // The outer call ran and keeps its one outcome; the outer run takes no
    // further model step, so it never replaces the step just parked.
    expect($this->ran)->toBe(['Paris'])
        ->and(count(Http::recorded()))->toBe($requests)
        ->and($outcome)->toBe('run_not_permitted:parked_elsewhere')
        ->and(r16Session()->thread()->pendingApprovalIds())->toBe(['apr_inner'])
        ->and(r16Outcomes())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r16Session()->approvalStatus($approval))->toBe('approved');
});

// ---------------------------------------------------------------------------
// 4. One trust signal: a mark on any row the runtime did not write is ignored.
// ---------------------------------------------------------------------------

it('ignores every harness mark on a host row, whatever run id it carries', function (string $mark, string $runIdKind): void {
    $approval = r16Park();
    $step = (int) r16Session()->thread()->storedMessages()->where('type', 'assistant')->max('position');
    $runId = match ($runIdKind) {
        'no run id' => null,
        'the parked run\'s id' => r16ParkedRun(),
        'another run\'s id' => 'run_'.str_repeat('0', 24),
    };
    $payload = ['tool_results' => [], 'tool_approval_responses' => [], ...match ($mark) {
        'harness_ended_by_start' => ['harness_ended_by_start' => ['approval_ids' => [$approval]]],
        'harness_superseded' => ['harness_superseded' => ['approval_ids' => [$approval]]],
        'harness_replaced' => ['harness_replaced' => ['approval_ids' => [$approval]]],
        'harness_started' => ['harness_started' => ['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'approval_id' => $approval],
            'harness_answers_step' => ['position' => $step]],
        'harness_answers_step' => ['harness_answers_step' => ['position' => $step]],
    }];
    r16Raw('tool_result', $payload, $runId);

    // Nothing changed: still pending, decidable, and the call runs once.
    expect(r16Session()->thread()->pendingApprovalIds())->toBe([$approval])
        ->and(r16Session()->approvalStatus($approval))->toBe('pending');
    G360::text('Done.');
    $decision = r16Try(fn () => r16Session()->decide([new ToolApprovalResponse($approval, true)]));
    expect($decision)->toBe('recorded')
        ->and($this->ran)->toBe(['Paris'])
        ->and(r16Outcomes())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r16Session()->approvalStatus($approval))->toBe('approved');
})->with(['harness_ended_by_start', 'harness_superseded', 'harness_replaced', 'harness_started', 'harness_answers_step'])
    ->with(['no run id', 'the parked run\'s id', 'another run\'s id']);

it('ignores a forged binding on a host step: its request binds the call it shows', function (?string $runId): void {
    // A host-written step whose request carries a binding naming other
    // arguments: read as absent, so the request binds its own call.
    $thread = r16Session()->thread();
    r16Raw('user', ['content' => 'Go.', 'additional_content' => [], 'additional_attributes' => []]);
    r16Raw('assistant', ['content' => '', 'tool_calls' => [['id' => 'call_h', 'name' => 'weather', 'arguments' => ['city' => 'Oslo'],
        'result_id' => null, 'reasoning_id' => null, 'reasoning_summary' => null]], 'additional_content' => [],
        'tool_approval_requests' => [['approval_id' => 'apr_h', 'tool_call_id' => 'call_h',
            'harness_call' => ['tool_name' => 'weather', 'args_digest' => 'sha256:'.str_repeat('0', 64)]]],
        'harness_tool_names' => ['weather']], $runId);
    G360::text('Done.');

    r16Session()->decide([new ToolApprovalResponse('apr_h', true)]);

    expect($this->ran)->toBe(['Oslo'])
        ->and($thread->storedMessages()->count())->toBeGreaterThan(2);
})->with([null, 'run_'.str_repeat('1', 24)]);

it('never lets a raw write set the trust column: only the runtime marks a row as its own', function (): void {
    r16Park();
    r16Raw('tool_result', ['tool_results' => [], 'tool_approval_responses' => []], r16ParkedRun());

    $rows = r16Session()->thread()->storedMessages()->orderBy('position')->get();
    expect((bool) $rows->last()->harness_written)->toBeFalse()
        // The runtime's own rows (its step) are marked.
        ->and((bool) $rows->firstWhere('type', 'assistant')->harness_written)->toBeTrue();
});

it('ignores a forged started row on an undecided approval: it stays pending and runs once when approved (T1e)', function (): void {
    $approval = r16Park();
    $step = (int) r16Session()->thread()->storedMessages()->where('type', 'assistant')->max('position');
    r16Raw('tool_result', ['tool_results' => [], 'tool_approval_responses' => [],
        'harness_started' => ['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'approval_id' => $approval],
        'harness_answers_step' => ['position' => $step]], 'zz');

    expect(r16Session()->approvalStatus($approval))->toBe('pending');
    G360::text('Done.');
    r16Session()->decide([new ToolApprovalResponse($approval, true)]);

    expect($this->ran)->toBe(['Paris'])
        ->and(r16Session()->approvalStatus($approval))->toBe('approved');
});

// ---------------------------------------------------------------------------
// 5. No host code inside the private recording window (T9).
// ---------------------------------------------------------------------------

it('runs no host code inside the runtime\'s recording window: a tool whose name() records cannot take it (T9)', function (): void {
    $runId = null;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$runId): void {
        $runId = $event->runId;
    });
    $ran = &$this->ran;
    $noisy = new class extends Tool
    {
        /** @var Closure(): void|null */
        public static ?Closure $onName = null;

        public function name(): string
        {
            if (self::$onName !== null) {
                (self::$onName)();
            }

            return parent::name();
        }
    };
    $noisy->as('weather')->for('Weather.')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$ran): string {
            $ran[] = $city;

            return "Sunny in {$city}";
        });
    app(ToolRegistry::class)->registerMany([$noisy]);
    $approval = r16Park();
    $noisy::$onName = function () use (&$runId): void {
        if ($runId !== null) {
            r16Session()->thread()->record([new ToolResultMessage([], [])], $runId);
        }
    };
    G360::text('Done.');

    $outcome = r16Try(fn () => r16Session()->decide([new ToolApprovalResponse($approval, true)]));
    $noisy::$onName = null;

    expect($outcome)->toBe('recorded')
        ->and($this->ran)->toBe(['Paris'])
        ->and(r16Outcomes())->toBe(['call_paris' => ['Sunny in Paris']]);
    // Every row the tool's name() recorded is a host row.
    foreach (r16Session()->thread()->storedMessages()->where('type', 'tool_result')->get() as $row) {
        if (($row->payload['tool_results'] ?? []) === [] && ($row->payload['tool_approval_responses'] ?? []) === []
            && ! array_key_exists('harness_started', $row->payload)) {
            expect((bool) $row->harness_written)->toBeFalse();
        }
    }
});

// ---------------------------------------------------------------------------
// 6. A decision from inside a tool on its own session (Py run20 F2).
// ---------------------------------------------------------------------------

it('never puts a session key in the error a tool deciding on its own session meets', function (): void {
    config()->set('prism-harness.agent.lock_wait', 0);
    $approval = r16Park();
    $key = r16Session()->key();
    $thrown = null;
    $this->during = function () use ($approval, &$thrown): void {
        try {
            r16Session()->decide([new ToolApprovalResponse($approval, false)]);
        } catch (Throwable $failure) {
            $thrown = $failure;

            throw $failure;
        }
    };
    G360::text('Done.');

    r16Try(fn () => r16Session()->decide([new ToolApprovalResponse($approval, true)]));

    $sent = '';
    foreach (Http::recorded() as [$request]) {
        $sent .= (string) $request->body();
    }
    expect($thrown)->toBeInstanceOf(SessionLocked::class)
        ->and($thrown->getMessage())->not->toContain($key)
        ->and($thrown->getMessage())->not->toContain('lock:')
        ->and(G360::threadText().$sent)->not->toContain($key);
});

// ---------------------------------------------------------------------------
// 7. A resume reads the thread a fixed number of times, whatever its length.
// ---------------------------------------------------------------------------

/** Pad the thread with `$pairs` user/assistant pairs, raw and fast, then park. */
function r16LongThread(int $pairs): string
{
    $thread = r16Session()->thread();
    $rows = [];
    for ($i = 1; $i <= $pairs * 2; $i++) {
        $rows[] = ['thread_id' => $thread->getKey(), 'position' => $i, 'type' => $i % 2 === 1 ? 'user' : 'assistant',
            'payload' => json_encode($i % 2 === 1 ? ['content' => "q{$i}", 'additional_content' => [], 'additional_attributes' => []]
                : ['content' => "a{$i}", 'tool_calls' => [], 'additional_content' => [], 'tool_approval_requests' => []]),
            'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('harness_thread_messages')->insert($chunk);
    }

    return r16Park();
}

/** @return array{all: int, whole: int} the queries a decide() makes: all of them, and those that read every row of the thread */
function r16ResumeQueries(string $approval): array
{
    G360::text('Done.');
    $all = 0;
    $whole = 0;
    DB::listen(function (QueryExecuted $query) use (&$all, &$whole): void {
        $all++;
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'from "harness_thread_messages"') && str_starts_with($sql, 'select')
            && ! str_contains($sql, '"position" >') && ! str_contains($sql, '"position" <') && ! str_contains($sql, '"position" =') && ! str_contains($sql, '"id" >')
            && ! str_contains($sql, 'limit') && ! str_contains($sql, 'count(') && ! str_contains($sql, 'max(')) {
            $whole++;
            if (getenv('R16_TRACE')) {
                fwrite(STDERR, $query->sql.' @ '.collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40))->map(fn ($f) => basename($f['file'] ?? '').':'.($f['line'] ?? ''))->filter(fn ($l) => str_contains($l, 'src') || preg_match('/^(Thread|Session|AgentRuntime|Plans|StepCalls|ApprovedTools|ThreadReads|RunTrees)/', $l))->take(8)->implode(' < ').'
');
            }
        }
    });
    r16Session()->decide([new ToolApprovalResponse($approval, true)]);
    app('events')->forget(QueryExecuted::class);

    return ['all' => $all, 'whole' => $whole];
}

it('resumes a long thread with a fixed number of store reads, whatever its length (item 7)', function (): void {
    $short = r16ResumeQueries(r16LongThread(20));
    // A second, much longer conversation, for another participant.
    $GLOBALS['r16Participant'] = 'Bob';
    try {
        $this->ran = [];
        $long = r16ResumeQueries(r16LongThread(2000));
    } finally {
        unset($GLOBALS['r16Participant']);
    }

    // One read of the whole thread; after it, only the rows appended since.
    expect($long['whole'])->toBeLessThanOrEqual(1)
        ->and($long['all'])->toBe($short['all'])
        ->and($this->ran)->toBe(['Paris']);
});
