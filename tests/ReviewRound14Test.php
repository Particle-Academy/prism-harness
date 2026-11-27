<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Models\Thread;
use Prism\Harness\Models\ThreadMessage;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Plans\Plans;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Support\InRunRecording;
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
use Tests\Support\G360;

// G-387 round 14: the round-13 reviews' findings, in PHP.
//
//  - G-404: an approved call that is running never also gets a "replaced"
//    denial (PR1, PR2). Round 14 refused the superseding host row with the
//    session lock; round 15 replaced that lock with the write-ahead started
//    row, so the host row is recorded and the call is answered-in-progress. A new message writes the replaced denials
//    and its own user row in ONE write (W1), and no decision lands in
//    between (W2). Every pending reader honours the harness's marks.
//  - G-403: the replay keeps every result of calls that share an id.
//  - Item 5: the replay emits each result once, in its own segment.
//  - G-406: only the runtime's own record call is inside a run, never a
//    host record() made during it (ZI1).
//
// Every interleaving is forced by a hook (a tool body, the scripted model's
// request hook, a model event), never by timing. No request leaves the
// process: G360 refuses stray requests and fakes every one from here.

const R14_REPLACED = 'Tool call was not run because a later step replaced it';

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
                    ($during)();
                }

                return "Sunny in {$city}";
            }),
        (new Tool)->as('echo')->for('Echo.')->withStringParameter('text', 'Text')
            ->using(fn (string $text): string => "echo:{$text}"),
    ]);
});

function r14Session(): Session
{
    return G360::session();
}

/** @return array<string, list<string>> every stored result, by call id, oldest first */
function r14Outcomes(): array
{
    $out = [];
    foreach (r14Session()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            $out[$entry['tool_call_id']][] = (string) $entry['result'];
        }
    }

    return $out;
}

/** @return list<string> the stored row types, oldest first, a marked denial row read as `replaced` */
function r14Rows(): array
{
    return r14Session()->thread()->storedMessages()->orderBy('position')->get()
        ->map(fn ($row): string => array_key_exists('harness_superseded', $row->payload) ? 'replaced' : (string) $row->type)->all();
}

/** Park on one gated weather call (`call_paris`). @return string its approval id */
function r14Park(): string
{
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);

    return r14Session()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
}

/** What a host write did: `recorded`, `locked`, or the exception's class. */
function r14Try(Closure $write): string
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

/** @param  array<string, mixed>  $body  @return array<string, int> function_call_output items in a Responses request, by call id */
function r14OutputCounts(array $body): array
{
    $counts = [];
    foreach ($body['input'] ?? [] as $item) {
        if (($item['type'] ?? null) === 'function_call_output') {
            $counts[$item['call_id']] = ($counts[$item['call_id']] ?? 0) + 1;
        }
    }

    return $counts;
}

/**
 * The replay's tool results, keyed as the fold keys them (id, tool name,
 * canonical arguments), with the segment (the index of the step they follow).
 *
 * @return list<array{0: int, 1: string, 2: string, 3: string}>
 */
function r14Replay(Thread $thread): array
{
    $out = [];
    $segment = -1;
    foreach ($thread->messages() as $message) {
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $segment++;
        }
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $out[] = [$segment, $result->toolCallId, $result->toolName.' '.CanonicalJson::digest($result->args), (string) $result->result];
            }
        }
    }

    return $out;
}

// ---------------------------------------------------------------------------
// G-404: one lock for a superseding write and the resolver.
// ---------------------------------------------------------------------------

it('gives an approved call that is running when a host row would supersede its step exactly one outcome (PR1)', function (string $when): void {
    $approval = r14Park();
    $host = null;
    $write = function () use (&$host): void {
        $host = r14Try(fn () => r14Session()->thread()->record([new UserMessage('A host row.')]));
    };
    if ($when === 'while the tool runs') {
        $this->during = $write;
    } else {
        // After the tool ran, while the provider answers. The resolution is
        // recorded BEFORE that request (one model step per request), so the
        // call already has its result: the row supersedes nothing and needs
        // no lock.
        G360::$onRequest = function (int $n) use ($write): void {
            if ($n === 2) {
                $write();
            }
        };
    }
    G360::text('Done.');

    r14Session()->decide([new ToolApprovalResponse($approval, true)]);

    // Round 15 (the write-ahead started row): the host row is RECORDED, never
    // refused; the running call is answered-in-progress, so it gets no
    // replaced denial and keeps its one outcome.
    expect($host)->toBe('recorded')
        ->and($this->ran)->toBe(['Paris'])
        ->and(r14Outcomes())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r14Session()->approvalStatus($approval))->not->toBe('superseded')
        ->and(r14Session()->thread()->approvalDecisions())->toBe([$approval => true]);

    // Once the run released the lock the host can record its row; the call
    // has its result, so nothing is denied.
    $this->during = null;
    G360::$onRequest = null;
    r14Session()->thread()->record([new UserMessage('A host row.')]);
    expect(r14Outcomes())->toBe(['call_paris' => ['Sunny in Paris']]);
})->with(['while the tool runs', 'while the provider answers']);

it('refuses a new message sent while an approved call is running, so the call keeps one outcome (PR2)', function (): void {
    $approval = r14Park();
    $sent = null;
    $this->during = function () use (&$sent): void {
        $sent = r14Try(fn () => r14Session()->send('A new message.'));
    };
    G360::text('Done.');

    r14Session()->decide([new ToolApprovalResponse($approval, true)]);

    expect($sent)->toBe('locked')
        ->and($this->ran)->toBe(['Paris'])
        ->and(r14Outcomes())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r14Session()->approvalStatus($approval))->not->toBe('superseded');
});

it('writes a new message and the denials of the step it replaces in one write, so a decision after a failed turn is refused (W1)', function (string $path): void {
    $approval = r14Park();
    G360::$queue[] = G360::FAIL;

    $turn = r14Try(fn () => match ($path) {
        'send' => r14Session()->send('Something else.'),
        'streamSteps' => iterator_to_array(r14Session()->streamSteps('Something else.'), false),
        'sendStructured' => r14Session()->sendStructured('Something else.', new ObjectSchema('doc', 'A document.',
            [new BooleanSchema('ok', 'Ok.')], ['ok'])),
    });
    G360::text('Done.');
    $decision = r14Try(fn () => r14Session()->decide([new ToolApprovalResponse($approval, true)]));

    expect($turn)->toBe(RuntimeException::class)
        // The replaced denial, then the message that replaced the step.
        ->and(r14Rows())->toBe(['user', 'assistant', 'replaced', 'user'])
        ->and($decision)->toBe('approval_not_pending')
        ->and($this->ran)->toBe([])
        ->and(r14Outcomes())->toBe(['call_paris' => [R14_REPLACED]])
        ->and(r14Session()->thread()->pendingApprovalIds())->toBe([]);
})->with(['send', 'streamSteps', 'sendStructured']);

it('records neither the denials nor the message when that one write fails, so the step stays decidable (W1, store failure)', function (): void {
    $approval = r14Park();
    DB::statement("CREATE TRIGGER r14_no_user BEFORE INSERT ON harness_thread_messages
        WHEN NEW.type = 'user' BEGIN SELECT RAISE(ABORT, 'outage'); END");
    $turn = r14Try(fn () => r14Session()->send('Something else.'));
    DB::statement('DROP TRIGGER r14_no_user');
    G360::text('Done.');

    r14Session()->decide([new ToolApprovalResponse($approval, true)]);

    expect($turn)->not->toBe('recorded')
        ->and($this->ran)->toBe(['Paris'])
        ->and(r14Outcomes())->toBe(['call_paris' => ['Sunny in Paris']]);
});

it('records no decision between a new message\'s denials and its turn (W2)', function (string $path): void {
    $approval = r14Park();
    $decision = null;
    G360::$onRequest = function (int $n) use (&$decision, $approval, $path): void {
        if ($n === 2 && $decision === null) {
            $decision = r14Try(fn () => match ($path) {
                'decide' => r14Session()->decide([new ToolApprovalResponse($approval, true)]),
                'streamDecision' => iterator_to_array(r14Session()->streamDecision([new ToolApprovalResponse($approval, true)]), false),
                'approve' => r14Session()->approve($approval),
            });
        }
    };
    G360::text('Answered.');

    r14Session()->send('Something else.');

    expect($decision)->toBeIn(['locked', 'approval_not_pending'])
        ->and(r14Session()->thread()->approvalDecisions())->toBe([])
        ->and($this->ran)->toBe([])
        ->and(r14Outcomes())->toBe(['call_paris' => [R14_REPLACED]]);
})->with(['decide', 'streamDecision', 'approve']);

// Every reader of what is pending honours the harness's own marks: a step
// whose open calls the harness already denied as replaced (or ended by a
// start) has nothing pending, even with nothing after that row.
it('reads an approval the harness marked as answered, whatever row follows it', function (string $mark): void {
    $thread = r14Session()->thread();
    $thread->record([
        new UserMessage('Weather?'),
        new AssistantMessage('', [new ToolCall('call_paris', 'weather', ['city' => 'Paris'])], [], [new ToolApprovalRequest('apr_r14', 'call_paris')]),
    ], toolNames: ['weather']);
    $thread->storedMessages()->create([
        'position' => (int) $thread->storedMessages()->max('position') + 1, 'type' => 'tool_result', 'run_id' => null,
        'payload' => ['tool_results' => [['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'args' => ['city' => 'Paris'],
            'result' => R14_REPLACED, 'tool_call_result_id' => null, 'artifacts' => []]], 'tool_approval_responses' => [],
            $mark => ['approval_ids' => ['apr_r14']]],
    ]);
    G360::text('Done.');

    $decision = r14Try(fn () => r14Session()->decide([new ToolApprovalResponse('apr_r14', true)]));

    expect(r14Session()->thread()->pendingApprovalIds())->toBe([])
        ->and(r14Session()->thread()->unansweredApprovals())->toBe([])
        ->and(r14Session()->thread()->pendingApprovalCalls())->toBe([])
        ->and($decision)->toBe('approval_not_pending')
        ->and($this->ran)->toBe([]);
})->with(['harness_superseded', 'harness_ended_by_start', 'harness_replaced']);

// ---------------------------------------------------------------------------
// G-403: the replay keeps every result of calls that share an id.
// ---------------------------------------------------------------------------

it('replays every result of two calls that share an id but not their tool or arguments', function (string $shape): void {
    [$first, $second] = match ($shape) {
        'gated + gated' => [['weather', ['city' => 'Paris']], ['weather', ['city' => 'Rome']]],
        'ungated + gated' => [['echo', ['text' => 'hi']], ['weather', ['city' => 'Rome']]],
        'ungated + ungated' => [['echo', ['text' => 'one']], ['echo', ['text' => 'two']]],
    };
    $thread = r14Session()->thread();
    $thread->record([
        new UserMessage('Go.'),
        new AssistantMessage('', [new ToolCall('dup', $first[0], $first[1]), new ToolCall('dup', $second[0], $second[1])]),
        new ToolResultMessage([new ToolResult('dup', $first[0], $first[1], 'first')]),
        new ToolResultMessage([new ToolResult('dup', $second[0], $second[1], 'second')]),
        new AssistantMessage('Both done.'),
    ]);
    G360::text('Next.');

    r14Session()->send('And then?');

    expect(array_map(fn (array $r): string => $r[3], r14Replay(r14Session()->thread())))->toBe(['first', 'second'])
        ->and(array_sum(r14OutputCounts(G360::lastRequest())))->toBe(2);
})->with(['gated + gated', 'ungated + gated', 'ungated + ungated']);

// ---------------------------------------------------------------------------
// Item 5: the replay emits each result exactly once, in its own segment.
// ---------------------------------------------------------------------------

it('replays each result once when a resolution is recorded after a row that is not a result', function (string $between): void {
    G360::calls([['echo', ['text' => 'hi'], 'call_echo'], ['weather', ['city' => 'Paris'], 'call_paris']]);
    $approval = r14Session()->send('Echo and weather?')->pendingApprovals()[0]->approvalId;
    $session = r14Session();
    (fn () => $this->recordDecisions([new ToolApprovalResponse($approval, true)]))->call($session);
    r14Session()->thread()->record([$between === 'a system row' ? new SystemMessage('A note.') : new ToolResultMessage([])]);
    G360::text('Done.');
    r14Session()->send('');
    G360::text('Next.');

    r14Session()->send('And then?');

    $replay = r14Replay(r14Session()->thread());
    $keys = array_map(fn (array $r): string => json_encode([$r[0], $r[1], $r[2]]), $replay);
    expect($this->ran)->toBe(['Paris'])
        ->and(count($keys))->toBe(count(array_unique($keys)))
        ->and(r14OutputCounts(G360::lastRequest()))->toBe(['call_echo' => 1, 'call_paris' => 1]);
})->with(['a system row', 'an empty result row']);

it('replays each result once after a new message replaced a step with a result of its own', function (): void {
    G360::calls([['echo', ['text' => 'hi'], 'call_echo'], ['weather', ['city' => 'Paris'], 'call_paris']]);
    r14Session()->send('Echo and weather?');
    G360::text('Replaced.');
    r14Session()->send('Something else.');
    G360::text('Next.');

    r14Session()->send('And then?');

    expect(r14OutputCounts(G360::lastRequest()))->toBe(['call_echo' => 1, 'call_paris' => 1])
        ->and(r14Outcomes())->toBe(['call_echo' => ['echo:hi'], 'call_paris' => [R14_REPLACED]]);
});

// ---------------------------------------------------------------------------
// G-406: a host record() made DURING the runtime's own write is outside.
// ---------------------------------------------------------------------------

it('never reads a host row recorded inside the runtime\'s own write as inside the run (ZI1)', function (): void {
    $host = null;
    ThreadMessage::created(function (ThreadMessage $row) use (&$host): void {
        if ($host === null && $row->type === 'assistant' && ($row->payload['tool_approval_requests'] ?? []) !== []) {
            $host = 'pending';
            $runId = (string) $row->run_id;
            $host = r14Try(fn () => r14Session()->thread()->record([new UserMessage('A note from a listener.')], $runId));
        }
    });
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris'], ['weather', ['city' => 'Rome'], 'call_rome']]);

    r14Session()->send('Weather in Paris and Rome?');

    $outcomes = r14Outcomes();
    // Refused while the run holds the lock, or recorded as a host row (outside):
    // either way every gated call has exactly one outcome or is still pending.
    if ($host === 'recorded') {
        expect($outcomes)->toBe(['call_paris' => [R14_REPLACED], 'call_rome' => [R14_REPLACED]]);
    } else {
        expect($host)->toBe('locked')
            ->and($outcomes)->toBe([])
            ->and(r14Session()->thread()->pendingApprovalIds())->toHaveCount(2);
    }
    expect($this->ran)->toBe([]);
});

// ---------------------------------------------------------------------------
// New channels the fixes could open, each pinned.
// ---------------------------------------------------------------------------

// Channel: a host naming the RUNNING run's id to pass for the runtime. A run
// id proves nothing; only the runtime's claimed token does. Round 15: the row
// is recorded (no lock any more), as a host row, and the call that runs keeps
// its one outcome.
it('records as outside a superseding host row that names the running run\'s own id', function (): void {
    $approval = r14Park();
    $host = null;
    $this->during = function () use (&$host): void {
        $running = (string) (r14Session()->run()['id'] ?? '');
        $host = r14Try(fn () => r14Session()->thread()->record([new UserMessage('A host row.')], $running));
    };
    G360::text('Done.');

    r14Session()->decide([new ToolApprovalResponse($approval, true)]);

    expect($host)->toBe('recorded')
        ->and(r14Outcomes())->toBe(['call_paris' => ['Sunny in Paris']])
        ->and(r14Rows())->not->toContain('replaced');
});

// Channel: a host claiming the runtime's token itself, from inside the
// runtime's write. The token was claimed on entry by the runtime's own call,
// so a later claim gets nothing, and nothing a host records there is inside.
it('gives a claim made inside the runtime\'s own write nothing', function (): void {
    $claimed = null;
    ThreadMessage::created(function (ThreadMessage $row) use (&$claimed): void {
        if ($claimed === null && is_string($row->run_id)) {
            $claimed = InRunRecording::claim($row->run_id);
        }
    });
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);

    r14Session()->send('Weather in Paris?');

    expect($claimed)->toBeFalse()
        ->and(r14Session()->thread()->pendingApprovalIds())->toHaveCount(1);
});

// Channel: the lock refusing host rows it has no reason to refuse. A row that
// supersedes nothing takes no lock, even while a run holds it.
it('records a host row that supersedes nothing while a run holds the lock', function (): void {
    $host = null;
    G360::$onRequest = function (int $n) use (&$host): void {
        if ($n === 1) {
            $host = r14Try(fn () => r14Session()->thread()->record([new UserMessage('A host row.')]));
        }
    };
    G360::text('Hello.');

    r14Session()->send('Hi.');

    expect($host)->toBe('recorded');
});

// Channel: the once-per-segment replay leaving out a result that is not a
// repeat. Results with the same id, tool AND arguments are one call's to the
// fold whether or not their rows are consecutive, exactly as before; results
// that differ in any of the three are all replayed.
it('treats rows across a system row exactly as consecutive rows when it replays them', function (): void {
    $thread = r14Session()->thread();
    $step = new AssistantMessage('', [new ToolCall('c', 'echo', ['text' => 'a']), new ToolCall('d', 'echo', ['text' => 'b'])]);
    $thread->record([new UserMessage('Go.'), $step,
        new ToolResultMessage([new ToolResult('c', 'echo', ['text' => 'a'], 'first')]),
        new SystemMessage('A note.'),
        new ToolResultMessage([new ToolResult('c', 'echo', ['text' => 'a'], 'first'), new ToolResult('d', 'echo', ['text' => 'b'], 'second')]),
        new AssistantMessage('Done.')]);

    expect(array_map(fn (array $r): string => $r[1].'='.$r[3], r14Replay(r14Session()->thread())))->toBe(['c=first', 'd=second']);
});
