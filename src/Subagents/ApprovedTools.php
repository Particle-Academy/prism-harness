<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Generator;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Structured\PendingRequest as StructuredPending;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Text\PendingRequest as TextPending;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;
use WeakMap;

/**
 * Core resolves approvals before HTTP, potentially expanding input after our
 * bound. Reuse its resolver before admission and persist the merge first.
 * This protected core seam is exercised on the minimum and current versions.
 */
final class ApprovedTools
{
    use CallsTools {
        invokeToolCall as private coreInvokeToolCall;
    }

    /**
     * Run one approved tool with the hold lifted.
     *
     * The resolver's own ToolInvoked for a call is dispatched after the tool
     * returns, and only that one is held. A tool that runs a generation of its
     * own (an agent built as a tool, a subagent) reports that generation's
     * calls while it runs, in that generation's trace; they pass through live,
     * as on 0.26.7. Core's only per-call execution path goes through here.
     *
     * @param  Tool[]  $tools
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}
     */
    private static function invokeToolCall(array $tools, ToolCall $toolCall, string $messageId): array
    {
        return Event::defer(static fn (): array => self::coreInvokeToolCall($tools, $toolCall, $messageId), []);
    }

    /**
     * The resolution's ToolInvoked events (dispatch arguments), held until
     * its result is recorded.
     *
     * @var list<array<int, mixed>>
     */
    private array $held = [];

    /** The resolved request, for a generation the held events may need. */
    private TextRequest|StructuredRequest|null $request = null;

    /** @var list<self> resolutions whose events wait for their generation */
    private static array $waiting = [];

    /** @var WeakMap<object, true>|null dispatchers the GenerationStarted hook is on */
    private static ?WeakMap $hooked = null;

    /**
     * @param  TelemetryOperation|null  $inGeneration  the operation of the core
     *                                                 generation this resolution stands in for (the plain paths)
     * @param  string|null  $sessionId  that generation's telemetry session id
     */
    public function __construct(
        private readonly ?TelemetryOperation $inGeneration = null,
        private readonly ?string $sessionId = null,
    ) {}

    /**
     * Resolve the approvals the request carries, record the result message
     * core appends, and return the resolution's events.
     *
     * Whether anything was resolved is read from what the resolver yielded,
     * never from the message list: core rebuilds some messages on every read
     * (an answered call with unparseable arguments), so a list comparison
     * would see a change that never happened.
     *
     * Core reports each call it runs with a ToolInvoked telemetry event, and
     * only under a telemetry context. The resolver is stepped one result at a
     * time and the resolver's own ToolInvoked events are held, not dispatched,
     * until the resolution's result is recorded (a generation the approved
     * tool runs itself reports live; see invokeToolCall). No listener runs
     * between an
     * approved call and its record, so not even a listener that ends the
     * process in a way PHP cannot catch can lose the record (an approved call
     * whose result was lost would run again on the next resume). Each held
     * event is then dispatched on its own, and a listener that throws is
     * reported, never thrown, so it can neither undo the resolution nor keep
     * the other calls' events from being sent.
     *
     * When the resolution stands in for core's generation ($inGeneration, the
     * plain send and stream paths) it opens a context of its own if none is
     * open, so the calls are reported at all, and its events are dispatched
     * inside the generation that follows (see within()), in that generation's
     * trace, as core reported them on 0.26.7 when it resolved inside the
     * generation. If the resolution fails, no generation follows; it starts
     * that generation itself and ends it, as core's did when a tool threw out
     * of it, so the events are still in a generation's trace.
     * The step-by-step paths (subagents, a cost cap, every stream) open none,
     * as on 0.26.7, and dispatch what a host context of their own produced
     * once the record is written.
     *
     * @param  Closure(list<Message>):void  $record
     * @return list<StreamEvent>
     */
    public function resolve(TextPending|StructuredPending $generation, Closure $record): array
    {
        $request = $this->request = $generation->toRequest();
        $context = null;
        if ($this->inGeneration !== null && Telemetry::enabled() && Telemetry::current() === null) {
            $context = new TelemetryContext(
                traceId: (string) Str::uuid(),
                operation: $this->inGeneration,
                provider: (string) $request->provider(),
                model: $request->model(),
                startedAt: microtime(true),
                sessionId: $this->sessionId,
            );
            Telemetry::stack()->push($context);
        }
        $events = [];
        try {
            // Consume ONLY the resolution events. Core merges after yielding
            // its results; exposing them before that merge/writeback could
            // replay a side effect if the consumer abandons the generator at
            // that yield.
            $resolver = $this->resolveToolApprovalsAndYieldEvents($request, EventID::generate());
            while ($this->step(static fn (): bool => $resolver->valid())) {
                $events[] = $resolver->current();
                $this->step(static function () use ($resolver): bool {
                    $resolver->next();

                    return true;
                });
            }
        } catch (Throwable $failure) {
            // A tool that throws its own exception (one built
            // withoutErrorHandling()) stops the resolution before core merges
            // anything. A call resolved before it has already run, so its
            // result is recorded first, merged as core would have merged it;
            // otherwise the next resume would run that call again.
            $partial = self::merged($request->messages(), $events);
            if ($partial !== null) {
                try {
                    $record($partial);
                    $generation->withMessages($partial);
                } catch (Throwable $unrecorded) {
                    report($unrecorded);
                }
            }
            $this->flush($failure);

            throw $failure;
        } finally {
            Telemetry::end($context);
        }
        if ($events !== []) {
            $messages = $request->messages();
            try {
                $record($messages);
            } catch (Throwable $unrecorded) {
                // The calls ran; their events describe what happened even
                // though the record did not land.
                $this->flush($unrecorded, failGeneration: true);

                throw $unrecorded;
            }
            $generation->withMessages($messages);
        }
        if ($this->inGeneration === null) {
            $this->announce(null);
        }

        return $events;
    }

    /**
     * Run the generation that follows the resolution, dispatching the held
     * events when it starts, in its trace: as GenerationStarted reaches its
     * listeners, after the ones the application registered at boot (a tracing
     * bridge opens the trace there). Whatever no generation took is flushed
     * once the run ends.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $run
     * @return TResult
     */
    public function within(Closure $run): mixed
    {
        if ($this->held === []) {
            return $run();
        }
        self::hook();
        self::$waiting[] = $this;
        $failure = null;
        try {
            return $run();
        } catch (Throwable $thrown) {
            $failure = $thrown;

            throw $thrown;
        } finally {
            self::$waiting = array_values(array_filter(self::$waiting, fn (self $waiting): bool => $waiting !== $this));
            $this->flush($failure);
        }
    }

    /**
     * within() for a streamed generation: the stream is opened only once the
     * hook is waiting, since a stream may announce its generation as it is
     * opened or as it is first read.
     *
     * @param  Closure(): iterable<array-key, StreamEvent>  $open
     * @return Generator<array-key, StreamEvent>
     */
    public function streamWithin(Closure $open): Generator
    {
        if ($this->held === []) {
            yield from $open();

            return;
        }
        self::hook();
        self::$waiting[] = $this;
        $failure = null;
        try {
            yield from $open();
        } catch (Throwable $thrown) {
            $failure = $thrown;

            throw $thrown;
        } finally {
            self::$waiting = array_values(array_filter(self::$waiting, fn (self $waiting): bool => $waiting !== $this));
            $this->flush($failure);
        }
    }

    /**
     * Dispatch whatever is still held because no generation took it: the
     * resolution failed, its record did not land, or the turn ended (a
     * stream abandoned, a generation that failed) before its generation
     * started. On the plain paths the generation the resolution stood in for
     * is started for them and ended, as core's was on 0.26.7 when the
     * resolution ran inside it: a stream generation is failed first when
     * there is a failure (core's asStream does), a text or structured one is
     * not (core's does not, for anything but a provider request failure).
     * After a failed record ($failGeneration) it is failed whatever the
     * operation: on 0.26.7 the record came after a completed generation, and
     * a stand-in that produced no response cannot be completed honestly, so
     * it is closed with the failure instead of being left open.
     * Elsewhere they are dispatched in their own context.
     */
    public function flush(?Throwable $failure = null, bool $failGeneration = false): void
    {
        if ($this->held === []) {
            return;
        }
        $generation = null;
        if ($this->inGeneration !== null && $this->request !== null) {
            $request = $this->request;
            try {
                $generation = Telemetry::start($this->inGeneration, (string) $request->provider(), $request->model(), $request, null, $this->sessionId);
            } catch (Throwable $listener) {
                report($listener);
                $generation = Telemetry::current();
            }
        }
        try {
            $this->announce($generation);
            if ($failure !== null && ($failGeneration || $this->inGeneration === TelemetryOperation::Stream)) {
                try {
                    Telemetry::failed($generation, $failure);
                } catch (Throwable $listener) {
                    report($listener);
                }
            }
        } finally {
            Telemetry::end($generation);
        }
    }

    /**
     * Dispatch the held events, each on its own, into the given generation's
     * context or, without one, in the context they were made in.
     */
    private function announce(?TelemetryContext $generation): void
    {
        $held = $this->held;
        $this->held = [];
        foreach ($held as $args) {
            $event = $args[0] ?? null;
            if ($generation !== null && $event instanceof ToolInvoked) {
                $args = [new ToolInvoked(
                    $generation->withStep(Telemetry::stack()->stepFor($generation->traceId)),
                    $event->toolName,
                    $event->toolCallId,
                    $event->durationMs,
                    $event->toolCall,
                    $event->toolResult,
                    $event->failed,
                    $event->failure,
                )];
            }
            try {
                Event::dispatch(...$args);
            } catch (Throwable $failure) {
                report($failure);
            }
        }
    }

    private static function hook(): void
    {
        $events = Event::getFacadeRoot();
        if (! is_object($events)) {
            return;
        }
        self::$hooked ??= new WeakMap;
        if (isset(self::$hooked[$events])) {
            return;
        }
        self::$hooked[$events] = true;
        Event::listen(GenerationStarted::class, static function (GenerationStarted $started): void {
            $waiting = end(self::$waiting);
            if ($waiting instanceof self) {
                $waiting->announce($started->context);
            }
        });
    }

    /**
     * The messages with the results the resolver yielded so far merged into
     * the tool results after the last assistant tool call, as core merges
     * them once every call is resolved; null when nothing was resolved.
     *
     * @param  list<Message>  $messages
     * @param  list<StreamEvent>  $events
     * @return list<Message>|null
     */
    private static function merged(array $messages, array $events): ?array
    {
        $resolved = [];
        foreach ($events as $event) {
            if ($event instanceof ToolResultEvent) {
                $resolved[] = $event->toolResult;
            }
        }
        $assistant = null;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
                $assistant = $i;

                break;
            }
        }
        if ($resolved === [] || $assistant === null) {
            return null;
        }
        $kept = [];
        $results = [];
        $responses = [];
        foreach ($messages as $index => $message) {
            if ($index > $assistant && $message instanceof ToolResultMessage) {
                $results = [...$results, ...$message->toolResults];
                $responses = [...$responses, ...$message->toolApprovalResponses];

                continue;
            }
            $kept[] = $message;
        }
        $kept[] = new ToolResultMessage([...$results, ...$resolved], $responses);

        return $kept;
    }

    /**
     * One step of the resolver, with the resolver's own ToolInvoked events
     * taken into the held list as it returns (the tool body runs with the hold
     * lifted; see invokeToolCall). A listener failure is reported, never
     * thrown.
     *
     * Laravel's Event::defer holds the step's events, but dispatches them all
     * in one loop as the step returns (before the record, and one throwing
     * listener stops the rest), so they are taken out of its deferred list
     * first. Where that list cannot be read (a dispatcher that is not
     * Laravel's), the deferral dispatches them as the step returns.
     *
     * @param  Closure(): bool  $step
     */
    private function step(Closure $step): bool
    {
        $done = false;
        $result = false;
        try {
            Event::defer(function () use ($step, &$done, &$result): void {
                try {
                    $result = $step();
                    $done = true;
                } finally {
                    $this->held = [...$this->held, ...(self::takeDeferred() ?? [])];
                }
            }, [ToolInvoked::class]);
        } catch (Throwable $failure) {
            if (! $done) {
                throw $failure;
            }
            report($failure);
        }

        return $result;
    }

    /**
     * Empty the dispatcher's deferred events and return them.
     *
     * @return list<array<int, mixed>>|null null when they cannot be read
     */
    private static function takeDeferred(): ?array
    {
        $events = Event::getFacadeRoot();
        if ($events instanceof EventFake) {
            $events = $events->dispatcher;
        }
        if (! $events instanceof Dispatcher || ! property_exists(Dispatcher::class, 'deferredEvents')) {
            return null;
        }
        $take = Closure::bind(static function (Dispatcher $events): array {
            $deferred = array_values($events->deferredEvents);
            $events->deferredEvents = [];

            return $deferred;
        }, null, Dispatcher::class);

        return $take($events);
    }
}
