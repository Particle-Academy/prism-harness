<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Generator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Prism\Harness\Exceptions\ResolutionAbandoned;
use Prism\Harness\Exceptions\ResolutionNotRecorded;
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
     * Run one approved tool under the deferral the host had before the
     * resolution step, not the step's own.
     *
     * The resolver's own ToolInvoked for a call is dispatched after the tool
     * returns, and only that one is held. A tool that runs a generation of its
     * own (an agent built as a tool, a subagent) reports that generation's
     * calls while it runs, in that generation's trace; they pass through as on
     * 0.26.7: live, or, when the host has its own Event::defer() open, into
     * the host's deferral (handed back to it when the step ends). Core's only
     * per-call execution path goes through here.
     *
     * Coupling: this replaces core's private CallsTools::invokeToolCall
     * (core ^0.154). A core release that renames it or changes its signature
     * breaks this class; one that stops routing tool execution through it
     * brings back held nested events (NestedGenerationTelemetryTest).
     *
     * @param  Tool[]  $tools
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}
     */
    private static function invokeToolCall(array $tools, ToolCall $toolCall, string $messageId): array
    {
        $run = static fn (): array => self::coreInvokeToolCall($tools, $toolCall, $messageId);
        $dispatcher = DeferredEvents::dispatcher();
        if (self::$host === null) {
            return $run();
        }
        if ($dispatcher === null) {
            return Event::defer($run, []);
        }
        $deferred = [];
        try {
            return DeferredEvents::under($dispatcher, self::$host, $run, $deferred);
        } finally {
            self::$forHost = [...self::$forHost, ...$deferred];
        }
    }

    /**
     * The host's deferral state when the current resolver step began, and
     * what the approved tool deferred under it, to hand back to the host.
     *
     * @var array{deferring: bool, events: list<string>|null}|null
     */
    private static ?array $host = null;

    /** @var list<array<int, mixed>> */
    private static array $forHost = [];

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
     * of it, so the events are still in a generation's trace (see settle()).
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
            // A stream generation is failed with the tool's own exception, as
            // core's asStream() failed it on 0.26.7; text and structured are
            // left as core's were (ended, not failed).
            $this->settle($this->inGeneration === TelemetryOperation::Stream ? $failure : null);

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
                // though the record did not land. The stand-in is failed with
                // a content-free exception: the record's own failure (a
                // database exception carries the bound row, tool arguments
                // and result included) never reaches telemetry.
                $this->settle(ResolutionNotRecorded::because($unrecorded));

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
        $from = $this->deferredCount();
        $failure = null;
        try {
            return $run();
        } catch (Throwable $thrown) {
            $failure = $thrown;

            throw $thrown;
        } finally {
            $this->leftOver($from, $failure);
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
        $from = $this->deferredCount();
        $failure = null;
        try {
            yield from $open();
        } catch (Throwable $thrown) {
            $failure = $thrown;

            throw $thrown;
        } finally {
            $this->leftOver($from, $failure);
        }
    }

    /**
     * Send whatever is still held because the turn ended before the
     * generation that follows the resolution started (a stream abandoned
     * before it opened). See settle().
     *
     * @internal for AgentRuntime's plain stream
     */
    public function flush(): void
    {
        $this->settle(ResolutionAbandoned::before());
    }

    /**
     * What is still held once the generation's run is over: when the host
     * deferred the generation's own GenerationStarted (an Event::defer() of
     * its own around the turn), the hook never ran, so the events go into the
     * host's deferred list right after that GenerationStarted, in its trace,
     * where core put them on 0.26.7; otherwise the generation never started,
     * and they are settled in a stand-in closed as abandoned.
     */
    private function leftOver(int $from, ?Throwable $failure): void
    {
        self::$waiting = array_values(array_filter(self::$waiting, fn (self $waiting): bool => $waiting !== $this));
        if ($this->held === [] || $this->intoDeferredGeneration($from)) {
            return;
        }
        $this->settle(ResolutionAbandoned::before($failure));
    }

    /** The length of the host's deferred list, when it is deferring. */
    private function deferredCount(): int
    {
        $dispatcher = DeferredEvents::dispatcher();

        return $dispatcher === null ? 0 : DeferredEvents::count($dispatcher);
    }

    private function intoDeferredGeneration(int $from): bool
    {
        $dispatcher = DeferredEvents::dispatcher();
        if ($dispatcher === null) {
            return false;
        }
        $state = DeferredEvents::state($dispatcher);
        if (! DeferredEvents::defers($state, GenerationStarted::class)) {
            return false;
        }
        $held = $this->held;
        $deferTools = DeferredEvents::defers($state, ToolInvoked::class);
        $started = DeferredEvents::insertAfter($dispatcher, $from,
            static fn (mixed $event): bool => $event instanceof GenerationStarted,
            static fn (object $event): array => $deferTools && $event instanceof GenerationStarted ? self::retargeted($held, $event->context, 0) : []);
        if (! $started instanceof GenerationStarted) {
            return false;
        }
        $this->held = [];
        if (! $deferTools) {
            self::dispatchEach(self::retargeted($held, $started->context, 0));
        }

        return true;
    }

    /**
     * Send what is held when no generation took it. On the plain paths the
     * generation the resolution stood in for is started for them, and ended:
     * failed with $closeWith when given (a content-free harness exception,
     * or for a stream whose resolution threw, the tool's own exception, as
     * core's asStream() did), left as core leaves a text or structured
     * generation a tool threw out of otherwise. Elsewhere they are dispatched
     * in their own context.
     */
    private function settle(?Throwable $closeWith): void
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
            if ($closeWith !== null && $generation !== null) {
                try {
                    Telemetry::failed($generation, $closeWith);
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
        self::dispatchEach($generation === null ? $held : self::retargeted($held, $generation, Telemetry::stack()->stepFor($generation->traceId)));
    }

    /**
     * The held events, with each ToolInvoked moved into the generation.
     *
     * @param  list<array<int, mixed>>  $held
     * @return list<array<int, mixed>>
     */
    private static function retargeted(array $held, TelemetryContext $generation, int $step): array
    {
        return array_map(static function (array $args) use ($generation, $step): array {
            $event = $args[0] ?? null;

            return $event instanceof ToolInvoked ? [new ToolInvoked(
                $generation->withStep($step),
                $event->toolName,
                $event->toolCallId,
                $event->durationMs,
                $event->toolCall,
                $event->toolResult,
                $event->failed,
                $event->failure,
            )] : $args;
        }, $held);
    }

    /**
     * Dispatch each, on its own; a listener failure is reported, never thrown.
     *
     * @param  list<array<int, mixed>>  $events
     */
    private static function dispatchEach(array $events): void
    {
        foreach ($events as $args) {
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
        $dispatcher = DeferredEvents::dispatcher();
        $previousHost = self::$host;
        $previousForHost = self::$forHost;
        self::$host = $dispatcher === null ? ['deferring' => false, 'events' => []] : DeferredEvents::state($dispatcher);
        self::$forHost = [];
        $done = false;
        $result = false;
        try {
            Event::defer(function () use ($step, $dispatcher, &$done, &$result): void {
                try {
                    $result = $step();
                    $done = true;
                } finally {
                    if ($dispatcher !== null) {
                        $this->held = [...$this->held, ...DeferredEvents::take($dispatcher)];
                    }
                }
            }, [ToolInvoked::class]);
        } catch (Throwable $failure) {
            if (! $done) {
                throw $failure;
            }
            report($failure);
        } finally {
            // What the approved tool deferred under the host's own deferral
            // goes back to the host, now that its deferral is the live one.
            $forHost = self::$forHost;
            self::$host = $previousHost;
            self::$forHost = $previousForHost;
            self::dispatchEach($forHost);
        }

        return $result;
    }
}
