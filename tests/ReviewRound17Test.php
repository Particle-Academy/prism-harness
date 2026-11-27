<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunStepped;
use Prism\Harness\Events\ToolApprovalAsked;
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
use Tests\Support\SentRequests;

// G-387 round 17: the round-16 review findings, in PHP (the reference).
//
//  1. Every SENT provider request answers every call of every step it
//     contains, exactly once, in that step's own result run: a result is
//     placed directly after its own step, past any user, text or system row
//     written while the call ran (TS run20 F1).
//
// Every interleaving is forced by a hook (a tool body), never by timing. No
// request leaves the process: G360 refuses stray requests and fakes every
// one from here.

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
    $this->during = null;
    $ran = &$this->ran;
    $during = &$this->during;
    $hook = function () use (&$during): void {
        if ($during !== null) {
            $run = $during;
            $during = null;
            $run();
        }
    };
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran, $hook): string {
                $ran[] = $city;
                $hook();

                return "Sunny in {$city}";
            }),
        (new Tool)->as('echo')->for('Echo.')->withStringParameter('text', 'Text')
            ->using(function (string $text) use (&$ran, $hook): string {
                $ran[] = $text;
                $hook();

                return "echo:{$text}";
            }),
    ]);
});

function r17Session(): Session
{
    return G360::session('Ada');
}

/** What a write did: `recorded`, `locked`, a refusal code, or the exception's class. */
function r17Try(Closure $write): string
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
        return $other::class.': '.$other->getMessage();
    }
}

/** What a host (or a tool) writes on the session while a call runs. */
function r17Concurrent(string $action): Closure
{
    return match ($action) {
        'a host user row' => fn () => r17Session()->thread()->record([new UserMessage('Host row.')]),
        'a host assistant-text row' => fn () => r17Session()->thread()->record([new AssistantMessage('Host text.')]),
        'a user row and a text row' => fn () => r17Session()->thread()->record([new UserMessage('Host row.'), new AssistantMessage('Host text.')]),
        'a host system row' => fn () => r17Session()->thread()->record([new SystemMessage('Host note.')]),
        // PHP holds the session lock for the whole run, so a nested send is
        // refused at once: it writes nothing.
        'a nested send answered with plain text' => function (): void {
            $GLOBALS['r17Nested'] = r17Try(fn () => r17Session()->send('Inner.'));
        },
    };
}

const R17_ACTIONS = ['a host user row', 'a host assistant-text row', 'a user row and a text row', 'a host system row', 'a nested send answered with plain text'];

// ---------------------------------------------------------------------------
// 1. A result is placed directly after its own step (TS run20 F1).
// ---------------------------------------------------------------------------

it('sends every later request with an approved call answered in its own step, whatever was written while it ran', function (string $action, string $path): void {
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);
    $approval = r17Session()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $this->during = r17Concurrent($action);
    G360::text('Done.');
    $decision = r17Try(fn () => $path === 'decide' ? r17Session()->decide([new ToolApprovalResponse($approval, true)])
        : iterator_to_array(r17Session()->streamDecision([new ToolApprovalResponse($approval, true)]), false));
    G360::text('Later.');
    $later = r17Try(fn () => r17Session()->send('And later?'));

    expect($this->ran)->toBe(['Paris'])
        ->and($decision)->toBe('recorded')
        ->and($later)->toBe('recorded')
        ->and(SentRequests::since())->toBe([])
        ->and(SentRequests::ofReplay(r17Session()->thread()->messages()))->toBe([])
        ->and($GLOBALS['r17Nested'] ?? 'locked')->toBe('locked');
    unset($GLOBALS['r17Nested']);
})->with(R17_ACTIONS)->with(['decide', 'streamDecision']);

it('sends every later request with an ungated call answered in its own step, whatever was written while it ran', function (string $action, string $path): void {
    G360::calls([['echo', ['text' => 'hi'], 'call_echo']]);
    G360::text('Echoed.');
    $this->during = r17Concurrent($action);
    $turn = r17Try(fn () => $path === 'send' ? r17Session()->send('Echo hi.')
        : iterator_to_array(r17Session()->streamSteps('Echo hi.'), false));
    G360::text('Later.');
    $later = r17Try(fn () => r17Session()->send('And later?'));

    expect($this->ran)->toBe(['hi'])
        ->and($turn)->toBe('recorded')
        ->and($later)->toBe('recorded')
        ->and(SentRequests::since())->toBe([])
        ->and(SentRequests::ofReplay(r17Session()->thread()->messages()))->toBe([])
        ->and($GLOBALS['r17Nested'] ?? 'locked')->toBe('locked');
    unset($GLOBALS['r17Nested']);
})->with(R17_ACTIONS)->with(['send', 'streamSteps']);

// ---------------------------------------------------------------------------
// 3. A nested park from inside an UNGATED call (PHP run19 #3, TS run20 F3a).
// ---------------------------------------------------------------------------

/** A step another writer (a tool's own work, or the host) parks on this session. */
function r17ParkInner(): void
{
    r17Session()->thread()->record([new AssistantMessage('', [new ToolCall('call_inner', 'weather', ['city' => 'Rome'], 'call_inner')], [],
        [new ToolApprovalRequest('apr_inner', 'call_inner')])], toolNames: ['weather']);
}

/** @return array<string, list<string>> every stored result, by call id, oldest first */
function r17Outcomes(): array
{
    $out = [];
    foreach (r17Session()->thread()->storedMessages()->where('type', 'tool_result')->orderBy('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            $out[$entry['tool_call_id']][] = is_string($entry['result']) ? $entry['result'] : json_encode($entry['result']);
        }
    }

    return $out;
}

it('never replaces a step a tool parked from inside an ungated call, and takes no further model step', function (string $path, bool $gatedSibling): void {
    G360::calls($gatedSibling
        ? [['echo', ['text' => 'hi'], 'call_echo'], ['weather', ['city' => 'Paris'], 'call_paris']]
        : [['echo', ['text' => 'hi'], 'call_echo']]);
    G360::text('Echoed.');
    $this->during = fn () => r17ParkInner();

    $turn = r17Try(fn () => $path === 'send' ? r17Session()->send('Echo hi.')
        : iterator_to_array(r17Session()->streamSteps('Echo hi.'), false));

    // The run stops: one model request, nothing of its turn written after
    // the step the tool parked, and that step still waits on its approval.
    expect($turn)->toBe('run_not_permitted:parked_elsewhere')
        ->and(SentRequests::count())->toBe(1)
        ->and($this->ran)->toBe(['hi'])
        ->and(r17Session()->thread()->pendingApprovalIds())->toBe(['apr_inner'])
        ->and(r17Outcomes())->toBe([])
        ->and(r17Session()->thread()->storedMessages()->where('type', 'user')->count())->toBe(0);

    // The parked step is answerable: approved, its call runs once.
    G360::text('Done.');
    $decision = r17Try(fn () => r17Session()->decide([new ToolApprovalResponse('apr_inner', true)]));
    expect($decision)->toBe('recorded')
        ->and($this->ran)->toBe(['hi', 'Rome'])
        ->and(r17Outcomes())->toBe(['call_inner' => ['Sunny in Rome']])
        ->and(SentRequests::since())->toBe([]);
})->with(['send', 'streamSteps'])->with(['alone' => false, 'beside a gated call' => true]);

it('stops an approved call\'s run on a nested park and sends no request that leaves a call unanswered', function (string $path): void {
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);
    $approval = r17Session()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $this->during = fn () => r17ParkInner();
    G360::text('Done.');
    $requests = SentRequests::count();

    $decision = r17Try(fn () => $path === 'decide' ? r17Session()->decide([new ToolApprovalResponse($approval, true)])
        : iterator_to_array(r17Session()->streamDecision([new ToolApprovalResponse($approval, true)]), false));

    expect($decision)->toBe('run_not_permitted:parked_elsewhere')
        ->and(SentRequests::count())->toBe($requests)
        ->and(r17Session()->thread()->pendingApprovalIds())->toBe(['apr_inner'])
        ->and(r17Outcomes())->toBe(['call_paris' => ['Sunny in Paris']]);
    G360::text('Later.');
    r17Try(fn () => r17Session()->send('And later?'));
    expect(SentRequests::since())->toBe([]);
})->with(['decide', 'streamDecision']);

// ---------------------------------------------------------------------------
// 4. A standing journal row replays with provider-valid ids (PHP run19 #4).
// ---------------------------------------------------------------------------

it('replays an ungated call whose results write failed with provider-valid ids on every provider map', function (string $path): void {
    G360::calls([['echo', ['text' => 'hi'], 'call_echo'], ['weather', ['city' => 'Paris'], 'call_paris']]);
    // The write of the turn that holds the echo's result fails.
    ThreadMessage::creating(function (ThreadMessage $row): void {
        foreach ($row->payload['tool_results'] ?? [] as $entry) {
            if (($entry['result'] ?? null) === 'echo:hi') {
                throw new RuntimeException('store down');
            }
        }
    });
    $turn = r17Try(fn () => $path === 'send' ? r17Session()->send('Echo hi and the weather.')
        : iterator_to_array(r17Session()->streamSteps('Echo hi and the weather.'), false));
    ThreadMessage::flushEventListeners();
    $journal = (int) r17Session()->thread()->storedMessages()->where('type', 'tool_result')
        ->get()->first(fn (ThreadMessage $row): bool => isset($row->payload['harness_journal']))?->position;

    G360::text('Next.');
    $next = r17Try(fn () => r17Session()->send('Go on.'));

    // The journal's call, as each provider map sends it.
    $mapped = SentRequests::mapped(r17Session()->thread()->messages());
    $responses = collect($mapped['openai responses']['input'])->firstWhere('type', 'function_call');
    $output = collect($mapped['openai responses']['input'])->firstWhere('type', 'function_call_output');
    $chat = collect($mapped['openai chat']['messages'])->first(fn (array $m): bool => ($m['tool_calls'] ?? []) !== []);
    $tool = collect($mapped['openai chat']['messages'])->firstWhere('role', 'tool');
    $anthropic = collect($mapped['anthropic']['messages'])->flatMap(fn (array $m): array => is_array($m['content']) ? $m['content'] : []);

    expect($turn)->not->toBe('recorded')
        ->and($next)->toBe('recorded')
        ->and($journal)->toBeGreaterThan(0)
        ->and($this->ran)->toBe(['hi'])
        // OpenAI Responses: an `fc` item id and a string call_id, the same on
        // the call and on its output.
        ->and($responses['id'])->toBe('fc_harness_journal_'.$journal)
        ->and($responses['call_id'])->toBe('call_harness_journal_'.$journal)
        ->and($output['call_id'])->toBe('call_harness_journal_'.$journal)
        // OpenAI Chat Completions and Anthropic: the call's own id.
        ->and($chat['tool_calls'][0]['id'])->toBe('fc_harness_journal_'.$journal)
        ->and($tool['tool_call_id'])->toBe('fc_harness_journal_'.$journal)
        ->and($anthropic->firstWhere('type', 'tool_use')['id'])->toBe('fc_harness_journal_'.$journal)
        ->and($anthropic->firstWhere('type', 'tool_result')['tool_use_id'])->toBe('fc_harness_journal_'.$journal)
        // And every request actually sent, and the replay on every map, is valid.
        ->and(SentRequests::since())->toBe([])
        ->and(SentRequests::ofReplay(r17Session()->thread()->messages()))->toBe([]);
})->with(['send', 'streamSteps']);

// ---------------------------------------------------------------------------
// 2. ONE trust signal, completed (PHP run19 #1 and #2, TS run20 F2, Py run21 F1).
// ---------------------------------------------------------------------------

/** The migration that adds the trust column. */
function r17TrustMigration(): object
{
    return require dirname(__DIR__).'/database/migrations/0001_01_01_000008_add_harness_written_to_thread_messages.php';
}

/** The schema as it was before the trust column: dropped, and forgotten. */
function r17Unmigrate(): void
{
    r17TrustMigration()->down();
    ThreadMessage::forgetSchema();
}

/** A row stored raw, with no trust column to set (a writer with table access, or a row from before it). */
function r17RawRow(string $type, array $payload, ?string $runId): int
{
    $thread = r17Session()->thread();
    $position = (int) DB::table('harness_thread_messages')->where('thread_id', $thread->getKey())->max('position') + 1;

    return (int) DB::table('harness_thread_messages')->insertGetId(['thread_id' => $thread->getKey(), 'position' => $position, 'type' => $type,
        'run_id' => $runId, 'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()]);
}

const R17_MARKS = ['harness_ended_by_start', 'harness_superseded', 'harness_replaced', 'harness_started', 'harness_answers_step', 'harness_journal'];

/** @return array<array-key, mixed> a tool_result payload carrying `$mark` for the parked call */
function r17Marked(string $mark, string $approval, int $step): array
{
    return ['tool_results' => [], 'tool_approval_responses' => [], ...match ($mark) {
        'harness_ended_by_start', 'harness_superseded', 'harness_replaced' => [$mark => ['approval_ids' => [$approval]]],
        'harness_started' => ['harness_started' => ['tool_call_id' => 'call_paris', 'tool_name' => 'weather', 'approval_id' => $approval],
            'harness_answers_step' => ['position' => $step]],
        'harness_answers_step' => ['harness_answers_step' => ['position' => $step]],
        'harness_journal' => ['harness_journal' => ['tool_name' => 'weather', 'args' => ['city' => 'Paris']]],
    }];
}

it('honours no harness mark on a schema without the trust column, and starts no run there', function (string $mark, string $runIdKind): void {
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);
    $approval = r17Session()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $step = (int) r17Session()->thread()->storedMessages()->where('type', 'assistant')->max('position');
    $parkedRun = (string) r17Session()->thread()->storedMessages()->where('type', 'assistant')->value('run_id');
    r17Unmigrate();
    try {
        r17RawRow('tool_result', r17Marked($mark, $approval, $step), match ($runIdKind) {
            'no run id' => null, 'the parked run id' => $parkedRun, default => 'run_'.str_repeat('0', 24),
        });

        // Nothing changed: still pending, and no run starts to act on it.
        $pending = r17Session()->thread()->pendingApprovalIds();
        G360::text('Done.');
        $decision = r17Try(fn () => r17Session()->decide([new ToolApprovalResponse($approval, true)]));
        $send = r17Try(fn () => r17Session()->send('Anything?'));

        expect($pending)->toBe([$approval])
            ->and($decision)->toBe('run_not_permitted:schema_not_migrated')
            ->and($send)->toBe('run_not_permitted:schema_not_migrated')
            ->and($this->ran)->toBe([])
            ->and(SentRequests::count())->toBe(1);
    } finally {
        ThreadMessage::forgetSchema();
    }
})->with(R17_MARKS)->with(['no run id', 'the parked run id', 'another run id']);

it('marks as the harness own, at migration, only a top-level harness_replaced on a tool_result row', function (): void {
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);
    $approval = r17Session()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $step = (int) r17Session()->thread()->storedMessages()->where('type', 'assistant')->max('position');
    r17Unmigrate();
    $ids = [];
    try {
        // A host row carrying each mark name, from before the column.
        foreach (R17_MARKS as $mark) {
            $ids[$mark] = r17RawRow('tool_result', r17Marked($mark, $approval, $step), null);
        }
        // A model-chosen tool argument and result named after every mark.
        $nested = array_fill_keys(R17_MARKS, ['approval_ids' => [$approval]]);
        $ids['in arguments'] = r17RawRow('tool_result', ['tool_results' => [['tool_call_id' => 'x', 'tool_name' => 'echo',
            'args' => ['text' => 'x', ...$nested], 'result' => json_encode($nested), 'tool_call_result_id' => null, 'artifacts' => []]],
            'tool_approval_responses' => []], null);
        // The mark on a row that is not a tool_result row.
        $ids['user row'] = r17RawRow('user', ['content' => '"harness_replaced"', 'harness_replaced' => ['approval_ids' => [$approval]],
            'additional_content' => [], 'additional_attributes' => []], null);
        // A key that merely CONTAINS the mark name, and the name in another case.
        $ids['longer key'] = r17RawRow('tool_result', ['tool_results' => [], 'tool_approval_responses' => [], 'harness_replaced_x' => ['approval_ids' => [$approval]]], null);
        $ids['case'] = r17RawRow('tool_result', ['tool_results' => [], 'tool_approval_responses' => [], 'HARNESS_REPLACED' => ['approval_ids' => [$approval]]], null);

        r17TrustMigration()->up();
    } finally {
        ThreadMessage::forgetSchema();
    }

    $flagged = DB::table('harness_thread_messages')->whereIn('id', array_values($ids))->where('harness_written', true)->pluck('id')->all();
    expect(array_keys(array_intersect($ids, $flagged)))->toBe(['harness_replaced']);
});

it('reads a parked step offered tools only from a step the runtime wrote: a host step resumes with the legacy scope', function (?string $runId): void {
    // A host step that names a WIDER offer than its one request.
    r17Session()->thread()->record([new UserMessage('Go.'), new AssistantMessage('', [new ToolCall('call_h', 'weather', ['city' => 'Oslo'], 'call_h')], [],
        [new ToolApprovalRequest('apr_h', 'call_h')])], $runId, toolNames: ['weather', 'echo']);
    G360::text('Done.');

    r17Session()->decide([new ToolApprovalResponse('apr_h', true)]);

    // The continuation is offered only the approved request's tool.
    expect($this->ran)->toBe(['Oslo'])
        ->and(G360::offered(0))->toBe(['weather']);
})->with(['no run id' => null, 'another run' => 'run_'.str_repeat('1', 24)]);

it('stops at a nested park recorded under the running turn OWN run id: a caller-passed run id is never ownership', function (string $path): void {
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);
    $approval = r17Session()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $runId = null;
    $this->during = function () use (&$runId): void {
        // The host stamps the step it parks with the outer run's own id (the
        // session's current run, which any host code can read).
        $runId = r17Session()->run()['id'] ?? null;
        r17Session()->thread()->record([new AssistantMessage('', [new ToolCall('call_inner', 'weather', ['city' => 'Rome'], 'call_inner')], [],
            [new ToolApprovalRequest('apr_inner', 'call_inner')])], $runId, toolNames: ['weather']);
    };
    G360::text('Done.');
    $requests = SentRequests::count();

    $decision = r17Try(fn () => $path === 'decide' ? r17Session()->decide([new ToolApprovalResponse($approval, true)])
        : iterator_to_array(r17Session()->streamDecision([new ToolApprovalResponse($approval, true)]), false));

    expect($runId)->not->toBeNull()
        ->and(r17Session()->thread()->storedMessages()->where('payload->tool_approval_requests', '!=', '[]')->where('run_id', $runId)->exists())->toBeTrue()
        ->and($decision)->toBe('run_not_permitted:parked_elsewhere')
        ->and(SentRequests::count())->toBe($requests)
        ->and(r17Session()->thread()->pendingApprovalIds())->toBe(['apr_inner']);
})->with(['decide', 'streamDecision']);

// ---------------------------------------------------------------------------
// 3 (cont.). A row written while a step parks (Py run21 F2/F3, TS run20 F3a).
// ---------------------------------------------------------------------------

it('gives every call of a parking step one honest outcome, and lists only approvals still pending, whatever a listener writes', function (string $event, string $rows, string $path): void {
    G360::calls([['echo', ['text' => 'hi'], 'call_echo'], ['weather', ['city' => 'Paris'], 'call_paris']]);
    $wrote = false;
    Event::listen($event, function () use (&$wrote, $rows): void {
        if ($wrote) {
            return;
        }
        $wrote = true;
        // A notification posted into the thread ("waiting for your approval").
        r17Session()->thread()->record(match ($rows) {
            'a user row' => [new UserMessage('The agent is waiting for your approval.')],
            'a text row' => [new AssistantMessage('Waiting for your approval.')],
        });
    });

    $response = $path === 'send' ? r17Session()->send('Echo hi and the weather.')
        : r17Session()->sendStructured('Echo hi and the weather.', new ObjectSchema('doc', 'A document.', [new BooleanSchema('ok', 'Ok.')], ['ok']));

    $listed = array_map(fn ($request): string => $request->approvalId, $response->pendingApprovals());
    $pending = r17Session()->thread()->pendingApprovalIds();
    // The ungated call ran once and has exactly one outcome, its real one:
    // never "not run" and then run.
    expect($this->ran)->toBe(['hi'])
        ->and(r17Outcomes()['call_echo'] ?? [])->toBe(['echo:hi'])
        ->and(count(r17Outcomes()['call_paris'] ?? []))->toBeLessThanOrEqual(1)
        // What the response lists is exactly what is still pending.
        ->and($listed)->toBe($pending);
})->with([ToolApprovalAsked::class, RunStepped::class, RunFinished::class])
    ->with(['a user row', 'a text row'])->with(['send', 'sendStructured']);

// ---------------------------------------------------------------------------
// 5. The start gate never decides on a stale kept read (PHP run19 Info 5b, Py run21 I1).
// ---------------------------------------------------------------------------

it('reads a decision changed in place while another approved call runs before it starts the next call', function (): void {
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris'], ['weather', ['city' => 'Rome'], 'call_rome']]);
    $pending = r17Session()->send('Weather in Paris and Rome?')->pendingApprovals();
    [$paris, $rome] = [$pending[0]->approvalId, $pending[1]->approvalId];
    $this->during = function () use ($rome): void {
        // Raw SQL, in place, keeping the row's updated_at: the kept read's
        // fingerprint cannot see it.
        $row = DB::table('harness_thread_messages')->where('type', 'tool_result')->orderByDesc('position')->get()
            ->first(fn (object $row): bool => str_contains((string) $row->payload, $rome));
        $payload = json_decode((string) $row->payload, true);
        foreach ($payload['tool_approval_responses'] as $k => $response) {
            if ($response['approval_id'] === $rome) {
                $payload['tool_approval_responses'][$k] = ['approval_id' => $rome, 'approved' => false, 'reason' => 'No'];
            }
        }
        DB::table('harness_thread_messages')->where('id', $row->id)->update(['payload' => json_encode($payload), 'updated_at' => $row->updated_at]);
    };
    G360::text('Done.');

    r17Session()->decide([new ToolApprovalResponse($paris, true), new ToolApprovalResponse($rome, true)]);

    // Rome's standing decision is now the denial: it never starts.
    expect($this->ran)->toBe(['Paris'])
        ->and(count(r17Outcomes()['call_rome'] ?? []))->toBe(1)
        ->and(r17Outcomes()['call_rome'][0])->not->toBe('Sunny in Rome');
});

it('says precisely why an approved call did not start: a denial that stands for it (Py run21 I3)', function (): void {
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris'], ['weather', ['city' => 'Rome'], 'call_rome']]);
    $pending = r17Session()->send('Weather in Paris and Rome?')->pendingApprovals();
    [$paris, $rome] = [$pending[0]->approvalId, $pending[1]->approvalId];
    $this->during = fn () => r17Session()->thread()->record([new ToolResultMessage([], [new ToolApprovalResponse($rome, false, 'No.')])]);
    G360::text('Done.');

    r17Session()->decide([new ToolApprovalResponse($paris, true), new ToolApprovalResponse($rome, true)]);

    expect($this->ran)->toBe(['Paris'])
        ->and(r17Outcomes()['call_rome'] ?? [])->toBe([Thread::START_DENIED]);
});

// ---------------------------------------------------------------------------
// DECISIONS 7 and 8 (coordinator, from Py #94).
// ---------------------------------------------------------------------------

it('sends the model the result the outcome readers count: the FIRST of two same-key results (decision 7)', function (): void {
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);
    $approval = r17Session()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    G360::text('Done.');
    r17Session()->decide([new ToolApprovalResponse($approval, true)]);
    // A host row, then a later result with the SAME key (id, tool, arguments).
    r17Session()->thread()->record([new UserMessage('Host row.')]);
    r17Session()->thread()->record([new ToolResultMessage([new ToolResult('call_paris', 'weather', ['city' => 'Paris'], 'Rainy in Paris')])]);

    $sent = [];
    foreach (r17Session()->thread()->messages() as $message) {
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $sent[] = (string) $result->result;
            }
        }
    }
    // The outcome the readers count is the first; the model is told the same.
    expect(r17Outcomes()['call_paris'])->toBe(['Sunny in Paris', 'Rainy in Paris'])
        ->and(r17Session()->approvalStatus($approval))->toBe('approved')
        ->and($sent)->toBe(['Sunny in Paris']);
    G360::text('Later.');
    r17Session()->send('And later?');
    expect(G360::outputs())->toBe(['call_paris' => 'Sunny in Paris'])
        ->and(SentRequests::since())->toBe([]);
});

it('returns the replaced status, never awaiting approval with nothing pending, when a row written meanwhile replaces the step (decision 8)', function (string $path, string $rows): void {
    G360::calls([['weather', ['city' => 'Paris'], 'call_paris']]);
    $wrote = false;
    Event::listen(RunFinished::class, function () use (&$wrote, $rows): void {
        if (! $wrote) {
            $wrote = true;
            r17Session()->thread()->record($rows === 'a user row'
                ? [new UserMessage('Something else.')]
                : [new AssistantMessage('', [new ToolCall('call_newer', 'weather', ['city' => 'Rome'], 'call_newer')], [],
                    [new ToolApprovalRequest('apr_newer', 'call_newer')])]);
        }
    });

    $response = $path === 'send' ? r17Session()->send('Weather in Paris?')
        : r17Session()->sendStructured('Weather in Paris?', new ObjectSchema('doc', 'A document.', [new BooleanSchema('ok', 'Ok.')], ['ok']));

    expect($response->awaitingApproval())->toBeFalse()
        ->and($response->pendingApprovals())->toBe([])
        ->and($response->stoppedBecause)->toBe($rows === 'a user row' ? 'replaced' : 'parked_elsewhere')
        ->and($this->ran)->toBe([]);
})->with(['send', 'sendStructured'])->with(['a user row', 'a newer step']);
