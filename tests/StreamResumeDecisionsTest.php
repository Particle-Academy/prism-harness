<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationErrored;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Tool;
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
