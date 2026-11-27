<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\SessionLockLost;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Participant;

// G-407: every result row the harness writes for a step names the step it
// answers (`harness_answers_step: {position}`). So a tool-call step a host
// tool records on its own session while an approved call runs never takes
// that call's result: the call keeps exactly one outcome, is never denied as
// replaced, and the later step's call is decided by its own approval. A host
// tool recording on its own session is never refused. Only the harness's own
// rows carry the mark, and a mark that does not name an earlier tool-call
// step with a matching call is ignored.

const RESULTS_NAME_REPLACED = 'Tool call was not run because a later step replaced it';

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $this->during = null;
    $this->thrown = null;
    $ran = &$this->ran;
    $during = &$this->during;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran, &$during): string {
                $ran[] = $city;
                if ($during !== null) {
                    $hook = $during;
                    $during = null;
                    try {
                        $hook();
                    } catch (Throwable $e) {
                        test()->thrown = $e::class;
                    }
                }

                return "Sunny in {$city}";
            }),
    ]);

    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    Http::fakeSequence()
        ->push(['id' => 'resp_n', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'c1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'c1', 'name' => 'weather']],
            'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_n_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_n', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function namesStepSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** A later tool-call step as a host records it, with its own approval request. */
function namesStepLater(string $id): AssistantMessage
{
    return new AssistantMessage('', [new ToolCall($id, 'weather', ['city' => 'Rome'])], [],
        [new ToolApprovalRequest('apr_later', $id, ToolApprovalRequest::ARGUMENTS_AS_SENT)]);
}

/** @return list<string> every distinct stored result for the call made for `$city` */
function namesStepOutcomes(string $city): array
{
    $outcomes = [];
    foreach (namesStepSession()->thread()->storedMessages()->reorder('position')->get() as $stored) {
        $message = $stored->toPrismMessage();
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                if (($result->args['city'] ?? null) === $city) {
                    $outcomes[] = (string) $result->result;
                }
            }
        }
    }

    return array_values(array_unique($outcomes));
}

/** @return array<string, Closure(Session, string): mixed> */
function namesStepResumes(): array
{
    return [
        'decide' => fn (Session $session, string $approvalId): mixed => $session->decide([new ToolApprovalResponse($approvalId, true)]),
        'streamDecision' => fn (Session $session, string $approvalId): mixed => iterator_to_array($session->streamDecision([new ToolApprovalResponse($approvalId, true)]), false),
    ];
}

it('keeps the running call\'s result its own when a host tool records a later tool-call step on its own session', function (string $resume, string $laterId): void {
    $approvalId = namesStepSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $this->during = fn () => namesStepSession()->thread()->record([namesStepLater($laterId)], null, ['weather']);

    namesStepResumes()[$resume](namesStepSession(), $approvalId);
    // The later step is decided by its own approval.
    namesStepSession()->decide([new ToolApprovalResponse('apr_later', false, 'not this')]);
    for ($i = 0; $i < 2; $i++) {
        namesStepSession()->send('');
    }

    expect($this->thrown)->toBeNull()
        ->and($this->ran)->toBe(['Paris'])
        ->and(namesStepOutcomes('Paris'))->toBe(['Sunny in Paris'])
        ->and(namesStepOutcomes('Rome'))->toBe(['not this']);
})->with(array_keys(namesStepResumes()))->with(['its own id' => ['c9'], 'the running call\'s id' => ['c1']]);

it('never refuses a host tool recording a user row on its own session, and the call keeps one outcome', function (string $resume): void {
    $approvalId = namesStepSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $this->during = fn () => namesStepSession()->thread()->record([new UserMessage('Hurry.')]);

    namesStepResumes()[$resume](namesStepSession(), $approvalId);
    namesStepSession()->send('');

    expect($this->thrown)->toBeNull()
        ->and($this->ran)->toBe(['Paris'])
        ->and(namesStepOutcomes('Paris'))->toBe(['Sunny in Paris']);
})->with(array_keys(namesStepResumes()));

it('marks the result row the run writes with the position of the step it answers, and keeps the mark out of the replay', function (): void {
    $approvalId = namesStepSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $step = namesStepSession()->thread()->storedMessages()->where('type', 'assistant')->value('position');
    namesStepSession()->decide([new ToolApprovalResponse($approvalId, true)]);

    $marked = namesStepSession()->thread()->storedMessages()->where('type', 'tool_result')->get()
        ->filter(fn ($row): bool => ($row->payload['tool_results'] ?? []) !== [])->values();

    expect($marked)->toHaveCount(1)
        ->and(array_key_last($marked[0]->payload))->toBe(Thread::ANSWERS_STEP)
        ->and(json_encode($marked[0]->payload[Thread::ANSWERS_STEP]))->toBe('{"position":'.$step.'}');
    foreach (namesStepSession()->thread()->messages() as $message) {
        expect(json_encode($message instanceof ToolResultMessage ? $message->toArray() : []))->not->toContain(Thread::ANSWERS_STEP);
    }
});

it('never stores a mark on a host row', function (): void {
    namesStepSession()->send('Weather in Paris?');
    namesStepSession()->thread()->record([new ToolResultMessage([new ToolResult('c1', 'weather', ['city' => 'Paris'], 'forged')])]);

    $host = namesStepSession()->thread()->storedMessages()->reorder('position', 'desc')->first();

    expect($host->payload)->not->toHaveKey(Thread::ANSWERS_STEP);
});

it('honours only a mark that names an earlier tool-call step with a matching call', function (string $mark, array $expected): void {
    $approvalId = namesStepSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $thread = namesStepSession()->thread();
    $step = (int) $thread->storedMessages()->where('type', 'assistant')->value('position');
    // A later step, then a result row for the parked call whose mark is not a
    // valid one: it stays the later step's, by its place in the thread.
    $thread->record([namesStepLater('c9')], null, ['weather']);
    $position = (int) $thread->storedMessages()->max('position') + 1;
    $thread->storedMessages()->create(['position' => $position, 'type' => 'tool_result', 'run_id' => 'run_x', 'payload' => [
        'tool_results' => [['tool_call_id' => 'c1', 'tool_name' => 'weather', 'args' => ['city' => 'Paris'], 'result' => 'planted', 'tool_call_result_id' => null, 'artifacts' => []]],
        'tool_approval_responses' => [],
        Thread::ANSWERS_STEP => ['position' => match ($mark) {
            'out of range' => $position + 5,
            'not a step' => 1,
            'the parked step' => $step,
            default => $step + 1,
        }],
    ]]);

    // The later step superseded the parked one (its call got the replaced
    // denial). An ignored mark leaves the planted row in the later step's
    // rows, where it answers none of that step's calls and is not replayed;
    // a valid one places it with the step it names.
    $order = [];
    foreach ($thread->messages() as $message) {
        if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
            $order[] = 'step '.$message->toolCalls[0]->id;
        }
        if ($message instanceof ToolResultMessage && $message->toolResults !== []) {
            foreach ($message->toolResults as $result) {
                $order[] = 'result '.$result->toolCallId.' '.$result->result;
            }
        }
    }

    expect($order)->toBe($expected)
        ->and($approvalId)->toBeString();
})->with([
    'out of range' => ['out of range', ['step c1', 'result c1 '.RESULTS_NAME_REPLACED, 'step c9']],
    'not a step' => ['not a step', ['step c1', 'result c1 '.RESULTS_NAME_REPLACED, 'step c9']],
    'a step with no such call' => ['a step with no such call', ['step c1', 'result c1 '.RESULTS_NAME_REPLACED, 'step c9']],
    'the parked step (valid)' => ['the parked step', ['step c1', 'result c1 planted', 'step c9']],
]);

/** Run `$write` as another process would: this process's held locks are not its own. */
function namesStepElsewhere(Closure $write): mixed
{
    $held = new ReflectionProperty(Session::class, 'held');
    $mine = $held->getValue();
    $held->setValue(null, []);
    try {
        return $write();
    } finally {
        $held->setValue(null, $mine);
    }
}

it('leaves a running call to its run when another process supersedes its step after the run\'s lock lease lapsed', function (string $row): void {
    $approvalId = namesStepSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $key = namesStepSession()->key();
    $this->during = function () use ($key, $row): void {
        // The call outlives lock_ttl: its lease lapses mid-call.
        DB::table('harness_session_state')->where('key', 'lock:'.$key)->update(['expires_at' => '2000-01-01 00:00:00']);
        namesStepElsewhere(fn () => namesStepSession()->thread()->record([$row === 'a later step' ? namesStepLater('c1') : new UserMessage('Hurry.')], null, ['weather']));
    };

    try {
        namesStepSession()->decide([new ToolApprovalResponse($approvalId, true)]);
    } catch (SessionLockLost) {
        // The run's own bookkeeping after the lapse is refused, as fencing does.
    }
    if ($row === 'a later step') {
        namesStepSession()->decide([new ToolApprovalResponse('apr_later', false, 'not this')]);
    }
    namesStepSession()->send('');

    expect($this->thrown)->toBeNull()
        ->and($this->ran)->toBe(['Paris'])
        ->and(namesStepOutcomes('Paris'))->toBe(['Sunny in Paris'])
        ->and(namesStepOutcomes('Rome'))->toBe($row === 'a later step' ? ['not this'] : []);
})->with(['a later step', 'a user row']);

it('writes a superseding row on a shared thread, which no session holds', function (): void {
    $thread = Thread::shared('admin-review');
    $thread->record([new AssistantMessage('', [new ToolCall('c1', 'weather', ['city' => 'Paris'])], [],
        [new ToolApprovalRequest('apr_shared', 'c1', ToolApprovalRequest::ARGUMENTS_AS_SENT)])], null, ['weather']);

    $thread->record([new UserMessage('Next.')]);

    $results = [];
    foreach ($thread->storedMessages()->reorder('position')->get() as $stored) {
        foreach ($stored->payload['tool_results'] ?? [] as $result) {
            $results[] = $result['result'];
        }
    }

    expect($results)->toBe([RESULTS_NAME_REPLACED]);
});

it('writes as an ordinary record when recordForRun is called outside the run holding the session', function (): void {
    namesStepSession()->send('Weather in Paris?');

    namesStepSession()->thread()->recordForRun([new UserMessage('Hurry.')], 'run_forged');

    expect(namesStepOutcomes('Paris'))->toBe([RESULTS_NAME_REPLACED]);
});
