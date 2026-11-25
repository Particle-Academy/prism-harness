<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Models\ThreadMessage;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationErrored;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// decide() records the decisions before it takes the session lock, so a
// decide() that then fails (SessionLocked here) leaves them recorded and
// unresolved. A plain-mode stream() resumes them. On 0.26.7 core resolved them
// inside the stream, and a ToolInvoked listener that threw there aborted the
// stream after the tool ran, losing its result, so the next resume ran the
// approved call again. The stream resume now resolves and records them first,
// then reports the call inside the stream's generation trace.

function streamResumeChat(string $text): array
{
    return ['id' => 'c-'.$text, 'object' => 'chat.completion', 'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]];
}

function streamResumeSse(string $text): string
{
    $line = fn (array $choice): string => 'data: '.json_encode(['id' => 's1', 'model' => 'gpt-4o', 'choices' => [$choice]])."\n\n";

    return $line(['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => null])
        .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop'])."data: [DONE]\n\n";
}

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.providers.openai.api_format', 'chat_completions');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.lock_wait', 0);
    config()->set('prism-harness.agent.modes.chat.tools', ['transfer']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['transfer']);
    $this->runs = 0;
    $runs = &$this->runs;
    app(ToolRegistry::class)->register((new Tool)->as('transfer')->for('Transfer.')->withStringParameter('amount', 'Amount')
        ->using(function (string $amount) use (&$runs): string {
            $runs++;

            return 'sent';
        }));
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(['id' => 'c1', 'object' => 'chat.completion', 'model' => 'gpt-4o',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_transfer', 'type' => 'function', 'function' => ['name' => 'transfer', 'arguments' => '{"amount":"5"}']],
            ]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]])
        ->push(streamResumeSse('streamed'), 200, ['Content-Type' => 'text/event-stream'])
        ->push(streamResumeChat('done'))
        ->push(streamResumeChat('done again'));
});

it('runs the approved call once when a plain stream resumes decisions a failed decide() recorded', function (bool $throws, string $prompt): void {
    $participant = Participant::query()->firstOrCreate(['name' => 'Ada']);
    $session = app(PrismHarness::class)->for($participant)->session('chat');
    $pending = $session->send('Send five.')->pendingApprovals();
    expect($pending)->toHaveCount(1);

    // Another request holds the session: decide() records, then refuses.
    $release = app(PrismHarness::class)->for($participant)->session('chat')->holdFencedLock(30, 0);
    try {
        expect(fn () => $session->decide([new ToolApprovalResponse($pending[0]->approvalId, true)]))->toThrow(SessionLocked::class);
    } finally {
        $release();
    }
    expect($this->runs)->toBe(0);

    $open = [];
    $seen = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$open): void {
        $open[$event->context->traceId] = true;
    });
    foreach ([GenerationCompleted::class, GenerationFailed::class, GenerationErrored::class] as $end) {
        Event::listen($end, function (object $event) use (&$open): void {
            unset($open[$event->context->traceId]);
        });
    }
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($session, &$open, &$seen, $throws): void {
        $seen[] = [
            'in_open_generation' => isset($open[$event->context->traceId]),
            'recorded' => $session->thread()->storedMessages()->where('type', 'tool_result')->get()
                ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
                ->where('tool_call_id', $event->toolCallId)->count(),
        ];
        if ($throws) {
            throw new RuntimeException('listener failed');
        }
    });

    try {
        iterator_to_array($session->stream($prompt), false);
    } catch (Throwable) {
    }
    try {
        $session->send('');
    } catch (Throwable) {
    }

    $prompts = $session->thread()->storedMessages()->where('type', 'user')->get()
        ->map(fn ($row): mixed => $row->payload['content'] ?? null)->all();
    expect($this->runs)->toBe(1)
        ->and($seen)->toBe([['in_open_generation' => true, 'recorded' => 1]])
        // The resumed turn's prompt is recorded once.
        ->and(array_count_values(array_map('strval', $prompts))[$prompt] ?? 0)->toBe(1);
})->with(['quiet listener' => false, 'throwing listener' => true])->with(['empty prompt' => '', 'new prompt' => 'And another thing.']);

// A resolution's held events are sent even when the turn never reaches the
// point where they are announced: a consumer that abandons the plain stream
// at the first resolution event, before the stream's generation opens, and a
// record write that fails after the approved call ran. On 0.26.7 core had
// already dispatched them live by then.
it('sends the held events when the turn ends before announcing them', function (string $exit): void {
    $participant = Participant::query()->firstOrCreate(['name' => 'Ada']);
    $session = app(PrismHarness::class)->for($participant)->session('chat');
    $pending = $session->send('Send five.')->pendingApprovals();
    $failWrite = false;
    ThreadMessage::creating(function (ThreadMessage $row) use (&$failWrite): void {
        if ($failWrite && $row->type === 'tool_result' && ($row->payload['tool_results'] ?? []) !== []) {
            $failWrite = false;
            throw new RuntimeException('store down');
        }
    });

    $started = [];
    $seen = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$started): void {
        $started[$event->context->traceId] = true;
    });
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$started, &$seen): void {
        $seen[] = [$event->toolName, isset($started[$event->context->traceId])];
    });

    if ($exit === 'abandoned stream') {
        $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending[0]->approvalId, true)])], $session->run()['id'] ?? null);
        $stream = $session->stream('');
        foreach ($stream as $event) {
            break;
        }
        unset($stream, $event);
        gc_collect_cycles();
    } else {
        $failWrite = true;
        expect(fn () => $session->approve($pending[0]->approvalId))->toThrow(RuntimeException::class, 'store down');
    }

    expect($this->runs)->toBe(1)->and($seen)->toBe([['transfer', true]]);
})->with(['abandoned stream', 'failed record']);

// On 0.26.7 core resolved a plain stream's recorded decisions inside asStream(),
// which fails its generation on any exception: a tool that threw out of the
// resolution left a stream trace closed by GenerationFailed, with the calls in
// it. The stand-in generation the resolution starts now closes the same way.
it('fails the stream generation a failed plain stream resolution stands in for', function (): void {
    app(ToolRegistry::class)->register((new Tool)->as('transfer')->for('Transfer.')->withStringParameter('amount', 'Amount')
        ->withoutErrorHandling()
        ->using(function (string $amount): string {
            throw new LogicException('bank down');
        }));
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('Send five.')->pendingApprovals();
    $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending[0]->approvalId, true)])], $session->run()['id'] ?? null);

    $traces = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$traces): void {
        $traces[$event->context->traceId] = [$event->context->operation->value, 'open', []];
    });
    Event::listen(GenerationFailed::class, function (GenerationFailed $event) use (&$traces): void {
        $traces[$event->context->traceId][1] = 'failed';
    });
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$traces): void {
        $traces[$event->context->traceId][2][] = $event->toolName;
    });

    expect(fn () => iterator_to_array($session->stream(''), false))->toThrow(LogicException::class, 'bank down');

    expect(array_values($traces))->toBe([['stream', 'failed', ['transfer']]]);
});
