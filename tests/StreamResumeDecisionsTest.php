<?php

declare(strict_types=1);

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Events\NullDispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\UnsupportedEventDispatcher;
use Prism\Harness\Models\ThreadMessage;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationErrored;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ToolResultEvent;
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
    $failed = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$started): void {
        $started[$event->context->traceId] = true;
    });
    $reasons = [];
    Event::listen(GenerationFailed::class, function (GenerationFailed $event) use (&$failed, &$reasons): void {
        $failed[] = $event->context->traceId;
        $reasons[] = $event->exception->getMessage();
    });
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$started, &$seen, &$failed): void {
        $seen[] = [$event->toolName, isset($started[$event->context->traceId])];
        $failed[] = 'event:'.$event->context->traceId;
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
    // The stand-in generation is closed, not left open, with a failure that
    // names no content.
    expect($failed)->toHaveCount(2)->and($failed[0])->toBe('event:'.$failed[1])
        ->and($reasons[0])->toStartWith($exit === 'failed record' ? 'resolution_not_recorded:' : 'resolution_abandoned:');
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

// A failed record write never carries its content into telemetry. A database
// exception's message holds the statement with its bindings: the row being
// written, with the approved tool's arguments and result. With content capture
// off (the default) none of that may reach a telemetry or harness event: not
// an event's fields, not an exception's message, chain or trace.
it('keeps a failed record write\'s content out of every telemetry event', function (string $route): void {
    $marker = 'MARKER-'.bin2hex(random_bytes(6));
    app(ToolRegistry::class)->register((new Tool)->as('transfer')->for('Transfer.')->withStringParameter('amount', 'Amount')
        ->using(function (string $amount) use ($marker): string {
            $this->runs++;

            return 'sent '.$marker;
        }));
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('Send five.')->pendingApprovals();
    $failWrite = true;
    ThreadMessage::creating(function (ThreadMessage $row) use (&$failWrite): void {
        if ($failWrite && $row->type === 'tool_result' && ($row->payload['tool_results'] ?? []) !== []) {
            $failWrite = false;
            // What the database layer throws: the statement with the row bound.
            throw new QueryException('testing', 'insert into "thread_messages" ("payload") values (?)', [json_encode($row->payload)], new PDOException('disk I/O error'));
        }
    });

    $leaks = [];
    $scan = function (mixed $value, string $where, array &$seen) use (&$scan, &$leaks, $marker): void {
        if (is_string($value)) {
            if (str_contains($value, $marker)) {
                $leaks[] = $where;
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $scan($item, $where.'.'.$key, $seen);
            }

            return;
        }
        if (! is_object($value) || isset($seen[spl_object_id($value)])) {
            return;
        }
        $seen[spl_object_id($value)] = true;
        if ($value instanceof Closure) {
            return;
        }
        // Every property, private and protected included: an exception's
        // message, its whole trace with each frame's arguments untruncated,
        // and its previous exception; an event's every field.
        foreach ((array) $value as $field => $item) {
            $scan($item, $where.'->'.str_replace(chr(0), '', (string) $field), $seen);
        }
    };
    $telemetry = [];
    Event::listen('*', function (string $name, array $payload) use ($scan, &$telemetry): void {
        if (str_starts_with($name, 'Prism\\Prism\\Events\\Telemetry\\') || str_starts_with($name, 'Prism\\Harness\\Events\\')) {
            $telemetry[] = class_basename($name);
            $seen = [];
            $scan($payload, class_basename($name), $seen);
        }
    });

    $threw = null;
    try {
        match ($route) {
            'approve' => $session->approve($pending[0]->approvalId),
            'stream' => (function () use ($session, $pending): void {
                $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending[0]->approvalId, true)])], $session->run()['id'] ?? null);
                iterator_to_array($session->stream(''), false);
            })(),
        };
    } catch (Throwable $failure) {
        $threw = $failure;
    }

    // The scan does reach into an exception's private trace and chain: it
    // finds the marker in the caller's own exception.
    $control = [];
    $leaksBefore = $leaks;
    $scan($threw, 'caller', $control);
    $found = array_values(array_diff($leaks, $leaksBefore));
    $leaks = $leaksBefore;
    expect($found)->not->toBe([]);

    // The caller still gets the database's own failure; telemetry hears of it
    // only by class.
    expect($threw)->toBeInstanceOf(QueryException::class)
        ->and(str_contains($threw->getMessage(), $marker))->toBeTrue()
        ->and($this->runs)->toBe(1)
        ->and($telemetry)->toContain('ToolInvoked', 'GenerationFailed')
        ->and($leaks)->toBe([]);
})->with(['approve', 'stream']);

// The generation the held events wait for is the one the resolution stands in
// for: the same operation and the same telemetry session. Under a host
// deferral of GenerationStarted, an ordinary tool of the outer generation that
// runs a generation of its own inside an Event::defer() of its own gets that
// nested GenerationStarted dispatched live while the outer one waits in the
// host's list. The approved call must not be reported in the nested trace.
it('never reports the approved call in a nested generation\'s trace', function (string $innerDefer): void {
    config()->set('prism-harness.agent.modes.chat.tools', ['transfer', 'helper']);
    app(ToolRegistry::class)->register((new Tool)->as('helper')->for('Helper.')->withStringParameter('h', 'H')
        ->using(fn (string $h): string => Event::defer(fn (): string => Prism::text()->using('openai', 'gpt-4o')->withPrompt('Inner.')->asText()->text, $innerDefer === 'all' ? null : ['app.none'])));
    $call = fn (string $id, string $tool, string $args): array => ['id' => 'c-'.$id, 'object' => 'chat.completion', 'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => $id, 'type' => 'function', 'function' => ['name' => $tool, 'arguments' => $args]],
        ]], 'finish_reason' => 'tool_calls']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]];
    // Its own HTTP fake: this file's shared sequence is for streams.
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push($call('call_transfer', 'transfer', '{"amount":"5"}'))
        // The resume: the outer generation calls the helper, whose agent answers; then the outer answers.
        ->push($call('call_helper', 'helper', '{"h":"y"}'))
        ->push(streamResumeChat('inner done'))
        ->push(streamResumeChat('done'));
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('Send five.')->pendingApprovals();
    expect($pending)->toHaveCount(1);

    $sessions = [];
    $approved = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$sessions): void {
        $sessions[$event->context->traceId] = $event->context->sessionId;
    });
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$approved): void {
        if ($event->toolName === 'transfer') {
            $approved[] = $event->context->traceId;
        }
    });

    Event::defer(fn () => $session->decide([new ToolApprovalResponse($pending[0]->approvalId, true)]), [GenerationStarted::class]);

    // Reported once, in the session's own generation; never the nested one,
    // which has no session. Read once the host has flushed every start.
    expect($this->runs)->toBe(1)
        ->and(count($sessions))->toBe(2)
        ->and(array_map(fn (string $trace): ?string => array_key_exists($trace, $sessions) ? $sessions[$trace] : 'unknown trace', $approved))
        ->toBe([$session->key()]);
})->with(['inner list' => 'list', 'inner all' => 'all']);

// A host whose Event::defer() over ToolInvoked ends while a plain stream is
// still mid-way (it consumed the resolution's events, then returned) must not
// get the approved call's event in a trace that never starts: it belongs to
// the stream's generation, as on 0.26.7, or, if the stream is abandoned, to a
// stand-in that is opened and closed. Never before the record.
it('reports a host-deferred stream resolution in the stream generation, or a closed stand-in', function (string $end): void {
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('Send five.')->pendingApprovals();
    $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending[0]->approvalId, true)])], $session->run()['id'] ?? null);

    $traces = [];
    $seen = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$traces): void {
        $traces[$event->context->traceId] = [$event->context->operation->value, 'open'];
    });
    Event::listen(GenerationFailed::class, function (GenerationFailed $event) use (&$traces): void {
        $traces[$event->context->traceId][1] = 'failed:'.strtok($event->exception->getMessage(), ':');
    });
    Event::listen(GenerationCompleted::class, function (GenerationCompleted $event) use (&$traces): void {
        $traces[$event->context->traceId][1] = 'completed';
    });
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($session, &$traces, &$seen): void {
        $seen[] = [$event->toolName, $event->context->traceId, $session->thread()->storedMessages()->where('type', 'tool_result')->get()
            ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
            ->where('tool_call_id', $event->toolCallId)->count()];
    });

    $stream = $session->stream('');
    Event::defer(function () use ($stream): void {
        // Consume up to the resolution's own event, then return.
        foreach ($stream as $event) {
            if ($event instanceof ToolResultEvent) {
                break;
            }
        }
    }, [ToolInvoked::class]);
    if ($end === 'finished outside') {
        while ($stream->valid()) {
            $stream->next();
        }
    }
    unset($stream);
    gc_collect_cycles();

    expect($this->runs)->toBe(1)->and($seen)->toHaveCount(1);
    [$tool, $trace, $recorded] = $seen[0];
    expect($tool)->toBe('transfer')
        ->and($recorded)->toBe(1)
        ->and($traces[$trace] ?? 'a trace that never started')->toBe($end === 'finished outside' ? ['stream', 'completed'] : ['stream', 'failed:resolution_abandoned']);
})->with(['finished outside', 'abandoned']);

// A host whose Event::defer() covers a whole plain-stream resume gets, in its
// flush, each approved call's ToolInvoked right after that call's own events
// and inside the stream generation's open trace (before its
// GenerationCompleted), as on 0.26.7, and only once the result is recorded.
it('puts a host-deferred stream resolution back in 0.26.7\'s place', function (string $mode): void {
    config()->set('prism-harness.agent.modes.chat.tools', ['transfer', 'refund']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['transfer', 'refund']);
    $ran = [];
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('transfer')->for('Transfer.')->withStringParameter('amount', 'Amount')->using(function (string $amount) use (&$ran): string {
            $ran[] = 'transfer';
            event('harness.test.audit', ['transfer']);

            return 'sent';
        }),
        (new Tool)->as('refund')->for('Refund.')->withStringParameter('amount', 'Amount')->using(function (string $amount) use (&$ran): string {
            $ran[] = 'refund';
            event('harness.test.audit', ['refund']);

            return 'refunded';
        }),
    ]);
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(['id' => 'c1', 'object' => 'chat.completion', 'model' => 'gpt-4o',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_transfer', 'type' => 'function', 'function' => ['name' => 'transfer', 'arguments' => '{"amount":"5"}']],
                ['id' => 'call_refund', 'type' => 'function', 'function' => ['name' => 'refund', 'arguments' => '{"amount":"2"}']],
            ]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]])
        ->push(streamResumeSse('streamed'), 200, ['Content-Type' => 'text/event-stream']);
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('Send five, refund two.')->pendingApprovals();
    expect($pending)->toHaveCount(2);
    $session->thread()->record([new ToolResultMessage(toolApprovalResponses: array_map(fn ($approval) => new ToolApprovalResponse($approval->approvalId, true), $pending))], $session->run()['id'] ?? null);

    $open = [];
    $order = [];
    Event::listen('harness.test.audit', function (string $tool) use (&$order): void {
        $order[] = 'audit:'.$tool;
    });
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$open, &$order): void {
        $open[$event->context->traceId] = true;
        $order[] = 'started:'.$event->context->operation->value;
    });
    Event::listen(GenerationCompleted::class, function (GenerationCompleted $event) use (&$open, &$order): void {
        unset($open[$event->context->traceId]);
        $order[] = 'completed';
    });
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($session, &$open, &$order): void {
        $recorded = $session->thread()->storedMessages()->where('type', 'tool_result')->get()
            ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
            ->where('tool_call_id', $event->toolCallId)->count();
        $order[] = 'invoked:'.$event->toolName.(isset($open[$event->context->traceId]) ? ':open' : ':closed').($recorded > 0 ? ':recorded' : ':unrecorded');
    });

    $events = match ($mode) {
        'all' => null,
        'start, complete, tools' => [GenerationStarted::class, GenerationCompleted::class, ToolInvoked::class, 'harness.test.audit'],
        'tools and audit' => [ToolInvoked::class, 'harness.test.audit'],
    };
    Event::defer(fn () => iterator_to_array($session->stream(''), false), $events);

    expect($ran)->toBe(['transfer', 'refund']);
    $calls = array_values(array_filter($order, fn (string $seen): bool => str_starts_with($seen, 'audit:') || str_starts_with($seen, 'invoked:')));
    // With GenerationCompleted live ('tools and audit'), the stream's trace has
    // closed by the host's flush, on 0.26.7 too; the order is still per call.
    $state = $mode === 'tools and audit' ? 'closed' : 'open';
    expect($calls)->toBe([
        'audit:transfer', "invoked:transfer:{$state}:recorded",
        'audit:refund', "invoked:refund:{$state}:recorded",
    ]);
    if ($mode !== 'tools and audit') {
        // Both inside the stream's trace: started first, completed last.
        expect($order[0])->toBe('started:stream')->and(end($order))->toBe('completed');
    }
})->with(['all', 'start, complete, tools', 'tools and audit']);

// Core runs, and reports, only an approved call with no result yet. A thread
// whose last tool step has nothing of that kind left (answered, or denied)
// needs no hold, so it is served on any dispatcher; a denial reports nothing.
it('needs the deferral internals only for an approved call still to run', function (string $state): void {
    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(['id' => 'c1', 'object' => 'chat.completion', 'model' => 'gpt-4o',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_transfer', 'type' => 'function', 'function' => ['name' => 'transfer', 'arguments' => '{"amount":"5"}']],
            ]], 'finish_reason' => 'tool_calls']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]])
        ->push(streamResumeChat('done'))->push(streamResumeChat('again'))->push(streamResumeChat('more'));
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('Send five.')->pendingApprovals();
    $seen = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$seen): void {
        $seen[] = $event->toolName;
    });
    if ($state === 'answered') {
        // Approved, run and answered, on Laravel's dispatcher.
        $session->decide([new ToolApprovalResponse($pending[0]->approvalId, true)]);
        expect($this->runs)->toBe(1);
    }
    if ($state === 'denied') {
        $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending[0]->approvalId, false, 'no')])], $session->run()['id'] ?? null);
    }
    if ($state === 'approved, still to run') {
        $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending[0]->approvalId, true)])], $session->run()['id'] ?? null);
    }
    $seen = [];
    $real = app('events');
    Event::swap(new NullDispatcher($real));
    try {
        $turn = fn () => $session->send('And now?');
        if ($state === 'approved, still to run') {
            expect($turn)->toThrow(UnsupportedEventDispatcher::class, 'unsupported_event_dispatcher');
            expect($this->runs)->toBe(0);
        } else {
            $turn();
            expect($this->runs)->toBe($state === 'answered' ? 1 : 0);
        }
    } finally {
        Event::swap($real);
    }
    // A denied call is answered without running and without a ToolInvoked.
    if ($state === 'denied') {
        $session->send('Once more?');
        expect($seen)->toBe([])->and($this->runs)->toBe(0);
    }
})->with(['answered', 'denied', 'approved, still to run']);
