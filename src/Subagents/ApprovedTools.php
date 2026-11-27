<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Support\CallPairing;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\ArtifactEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Structured\PendingRequest as StructuredPending;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Telemetry\ContextStack;
use Prism\Prism\Telemetry\Telemetry;
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
 *
 * RECORD, THEN ANNOUNCE. Core reports each call it runs with a ToolInvoked
 * telemetry event, synchronously, after the call ran and before anything is
 * recorded. A listener that ends the process there (a time limit, out of
 * memory, a killed worker) would lose the result of a call that already ran,
 * and the next resume would run it again. So the resolution runs with no
 * telemetry context visible to core, which then dispatches nothing; each
 * report core would have sent is collected here instead, and sent only once
 * the results are recorded.
 *
 * The reports go out under the telemetry context of the run's NEXT generation
 * (the one the results are sent in), so a tracer that builds tool spans only
 * inside an open generation keeps them. They are handed to that generation
 * only for the length of the run's own call ({@see self::arm()}), never while
 * the host has control, so no other generation (the host's own, another
 * session's) can take them. When no generation takes them, they go out under
 * a bare context of their own. Each is dispatched
 * on its own: a listener that throws is reported, never thrown, and costs no
 * other call its event.
 *
 * Call {@see self::arm()} immediately before the next generation call and
 * {@see self::announce()} right after it returns or fails, in a `finally`.
 */
final class ApprovedTools
{
    use CallsTools {
        executeToolCall as private coreExecuteToolCall;
        failedToolExecution as private coreFailedToolExecution;
    }

    /** @var list<ResolutionAnnouncement> resolutions in progress, innermost last */
    private static array $resolving = [];

    /**
     * Recorded resolutions whose run's own generation call is in progress,
     * innermost last, each with the telemetry stack depth it was armed at.
     *
     * @var list<array{ResolutionAnnouncement, int}>
     */
    private static array $armed = [];

    /** @var WeakMap<object, true>|null */
    private static ?WeakMap $listening = null;

    private ?ResolutionAnnouncement $announcement = null;

    /**
     * Resolve the approvals the request carries, record the result message
     * core appends, and return the resolution's events.
     *
     * Whether anything was resolved is read from what the resolver yielded,
     * never from the message list: core rebuilds some messages on every read
     * (an answered call with unparseable arguments), so a list comparison
     * would see a change that never happened.
     *
     * A tool that throws its own exception (one built withoutErrorHandling())
     * stops the resolution before core merges anything. Calls resolved before
     * it have already run, so their results are recorded first, merged as core
     * would have merged them, and then the exception is rethrown unchanged.
     *
     * @param  Closure(list<Message>):void  $record
     * @return list<StreamEvent>
     */
    public function resolve(TextPending|StructuredPending $generation, Closure $record): array
    {
        $this->announce();
        $request = $generation->toRequest();
        $announcement = new ResolutionAnnouncement(
            $request,
            $generation instanceof StructuredPending ? [TelemetryOperation::Structured] : [TelemetryOperation::Text, TelemetryOperation::Stream],
            $generation->providerKey(),
            $request->model(),
        );
        $this->announcement = $announcement;

        $events = [];
        try {
            $this->collect($request, $announcement, $events);
        } catch (Throwable $failure) {
            $partial = self::merged($request->messages(), $events);
            if ($partial !== null) {
                try {
                    $record($partial);
                    $generation->withMessages($partial);
                } catch (Throwable $unrecorded) {
                    report($unrecorded);
                }
            }
            // No generation follows a resolution that threw.
            $this->announce();

            throw $failure;
        }
        if ($events !== []) {
            $messages = $request->messages();
            $record($messages);
            $generation->withMessages($messages);
        }

        return $events;
    }

    /**
     * Hand the last resolution's reports to the generation the run's next
     * call starts. Call it immediately before that call, with nothing between
     * that could return control to the host, and {@see self::announce()}
     * right after the call returns or throws (on a stream, at its first
     * event). Only a generation pushed directly on the telemetry stack as it
     * is now takes them: one something else starts while that generation
     * starts (a listener, say) sits above it and is passed over.
     */
    public function arm(): void
    {
        $announcement = $this->announcement;
        if ($announcement === null || ! $announcement->pending() || ! Telemetry::enabled()) {
            return;
        }
        foreach (self::$armed as [$armed]) {
            if ($armed === $announcement) {
                return;
            }
        }
        self::listen();
        self::$armed[] = [$announcement, ContextDepth::of(Telemetry::stack())];
    }

    /**
     * Send what the last resolution has not sent yet, under a bare context.
     * A no-op once its generation has taken the reports.
     */
    public function announce(): void
    {
        $announcement = $this->announcement;
        if ($announcement === null) {
            return;
        }
        $this->announcement = null;
        self::disarm($announcement);
        $announcement->send();
    }

    /**
     * Run core's resolver to the end with no telemetry context visible to it,
     * collecting its events; the reports it would have dispatched are
     * collected by {@see self::executeToolCall()} and
     * {@see self::failedToolExecution()}.
     *
     * @param  list<StreamEvent>  $events
     */
    private function collect(TextRequest|StructuredRequest $request, ResolutionAnnouncement $announcement, array &$events): void
    {
        $previous = Telemetry::enabled() ? app(ContextStack::class) : null;
        if ($previous !== null) {
            app()->instance(ContextStack::class, new ContextStack);
        }
        self::$resolving[] = $announcement;
        try {
            // Consume ONLY the resolution events. Core merges after yielding
            // its results; exposing them before that merge/writeback could
            // replay a side effect if the consumer abandons the generator at
            // that yield.
            // A step whose call ids repeat is resolved here, never by core's
            // resolver, which keys results and requests by call id (G-387
            // round 13): every gated call in it gets ONE recorded outcome, the
            // repeated-id denial, and none of them runs.
            $resolution = self::repeatsCallIds($request)
                ? $this->resolveRepeatedStep($request, EventID::generate())
                : $this->resolveToolApprovalsAndYieldEvents($request, EventID::generate());
            foreach ($resolution as $event) {
                $events[] = $event;
            }
        } finally {
            self::$resolving = array_values(array_filter(self::$resolving,
                static fn (ResolutionAnnouncement $resolving): bool => $resolving !== $announcement));
            if ($previous !== null) {
                app()->instance(ContextStack::class, $previous);
            }
        }
    }

    /**
     * Core's execution of an approved call, with the report core sends for it
     * collected rather than dispatched.
     *
     * @param  Tool[]  $tools
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}
     */
    protected static function executeToolCall(array $tools, ToolCall $toolCall, string $messageId, bool $deferClassification = false): array
    {
        $announcement = self::$resolving[array_key_last(self::$resolving) ?? -1] ?? null;
        if ($announcement === null) {
            return self::coreExecuteToolCall($tools, $toolCall, $messageId, $deferClassification);
        }
        $announcement->executing++;
        $startedAt = microtime(true);
        try {
            $result = self::coreExecuteToolCall($tools, $toolCall, $messageId, $deferClassification);
        } catch (Throwable $failure) {
            $announcement->invocations[] = ['call' => $toolCall, 'result' => null,
                'durationMs' => (microtime(true) - $startedAt) * 1000, 'failed' => true, 'failure' => self::toolExceptionKind($failure)];

            throw $failure;
        } finally {
            $announcement->executing--;
        }
        $announcement->invocations[] = ['call' => $toolCall, 'result' => $result['toolResult'],
            'durationMs' => $result['durationMs'], 'failed' => $result['failed'], 'failure' => $result['failure']];

        return $result;
    }

    /**
     * Core's refusal of an approved call whose stored arguments cannot be run.
     * Core reports it without its arguments, with the refusal's kind when it
     * has one; collected the same way. A failure inside a running tool is
     * that tool's own, and {@see self::executeToolCall()} collects it.
     *
     * @return array{toolResult: ToolResult, events: array<int, ToolResultEvent|ArtifactEvent>, durationMs: float, failed: bool, failure: ?string}
     */
    protected static function failedToolExecution(ToolCall $toolCall, PrismException $failure, string $messageId, float $startedAt): array
    {
        $result = self::coreFailedToolExecution($toolCall, $failure, $messageId, $startedAt);
        $announcement = self::$resolving[array_key_last(self::$resolving) ?? -1] ?? null;
        if ($announcement !== null && $announcement->executing === 0) {
            $announcement->invocations[] = ['call' => $toolCall, 'result' => $result['toolResult'],
                'durationMs' => $result['durationMs'], 'failed' => true,
                'failure' => self::refusalKind($announcement->request, $toolCall) ?? $result['failure']];
        }

        return $result;
    }

    /**
     * The last tool-calling step of `$request`, and its index, or null.
     *
     * @return array{0: AssistantMessage, 1: int}|null
     */
    private static function lastStep(TextRequest|StructuredRequest $request): ?array
    {
        $messages = $request->messages();
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
                return [$messages[$i], $i];
            }
        }

        return null;
    }

    /** Whether the step being resumed repeats a call id among its calls or its approval requests (G-392). */
    private static function repeatsCallIds(TextRequest|StructuredRequest $request): bool
    {
        $step = self::lastStep($request);
        if ($step === null) {
            return false;
        }
        $ids = array_map(static fn (ToolCall $call): string => $call->id, $step[0]->toolCalls);
        $asked = array_map(static fn ($approval): string => $approval->toolCallId, $step[0]->toolApprovalRequests);

        return count($ids) !== count(array_unique($ids)) || count($asked) !== count(array_unique($asked));
    }

    /**
     * Resolve a step whose call ids repeat (G-387 round 13). A decision cannot
     * say which of its calls it answers, so none of its gated calls runs: each
     * gated call that has no result of its OWN (matched by position, never by
     * id) gets the repeated-id denial, once. An ungated call is never given a
     * denial: it ran when the step was taken. Recorded as core records a
     * resolution: one result message after the step.
     *
     * @return \Generator<int, ToolResultEvent>
     */
    private function resolveRepeatedStep(TextRequest|StructuredRequest $request, string $messageId): \Generator
    {
        $step = self::lastStep($request);
        if ($step === null) {
            return;
        }
        [$assistant, $at] = $step;
        $tools = collect($request->tools())->keyBy(fn (Tool $tool): string => $tool->name());
        $messages = $request->messages();
        $indexes = [];
        $rows = [];
        $results = [];
        $responses = [];
        for ($i = $at + 1, $n = count($messages); $i < $n; $i++) {
            if (! $messages[$i] instanceof ToolResultMessage) {
                // The conversation moved on past this step: it was settled
                // (and the replay folds its same-id results into one, so they
                // can no longer be counted). Nothing here is open.
                return;
            }
            $indexes[] = $i;
            $rows[] = CallPairing::ofResults($messages[$i]->toolResults);
            $results = [...$results, ...$messages[$i]->toolResults];
            $responses = [...$responses, ...$messages[$i]->toolApprovalResponses];
        }
        $answered = CallPairing::answered(CallPairing::ofCalls($assistant->toolCalls), $rows);
        $denied = [];
        foreach (array_values($assistant->toolCalls) as $index => $call) {
            if ($answered[$index] || $tools->get($call->name)?->hasApprovalConfigured() !== true) {
                continue;
            }
            $denied[] = $result = new ToolResult(
                toolCallId: $call->id,
                toolName: $call->name,
                args: $call->argumentsForResult(),
                result: Session::REPEATED_CALL_ID_REASON,
                toolCallResultId: $call->resultId,
            );
            yield new ToolResultEvent(
                id: EventID::generate(),
                timestamp: time(),
                toolResult: $result,
                messageId: $messageId,
                success: false,
                error: Session::REPEATED_CALL_ID_REASON,
            );
        }
        if ($denied === []) {
            return;
        }
        if ($indexes !== []) {
            $request->setMessages(array_values(array_filter($messages,
                static fn (int $index): bool => ! in_array($index, $indexes, true), ARRAY_FILTER_USE_KEY)));
        }
        $request->addMessage(new ToolResultMessage([...$results, ...$denied], $responses));
    }

    /** The kind core gives the refusal of this stored call, when it gives one. */
    private static function refusalKind(TextRequest|StructuredRequest $request, ToolCall $refused): ?string
    {
        $messages = $request->messages();
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];
            if (! $message instanceof AssistantMessage || $message->toolCalls === []) {
                continue;
            }
            foreach ($message->toolCalls as $call) {
                if ($call->id !== $refused->id) {
                    continue;
                }
                $approval = null;
                foreach ($message->toolApprovalRequests as $candidate) {
                    if ($candidate->toolCallId === $call->id) {
                        $approval = $candidate;
                    }
                }
                foreach ($request->tools() as $tool) {
                    if ($tool->name() === $call->name) {
                        return self::storedArgumentsRefusal($tool, $call, $approval)['kind'] ?? null;
                    }
                }
            }

            return null;
        }

        return null;
    }

    private static function disarm(ResolutionAnnouncement $announcement): void
    {
        self::$armed = array_values(array_filter(self::$armed,
            static fn (array $armed): bool => $armed[0] !== $announcement));
    }

    /** Listen, once per dispatcher, for the generation an armed resolution's results are sent in. */
    private static function listen(): void
    {
        $events = app('events');
        self::$listening ??= new WeakMap;
        if (isset(self::$listening[$events])) {
            return;
        }
        self::$listening[$events] = true;
        $events->listen(GenerationStarted::class, static function (GenerationStarted $event): void {
            $stack = Telemetry::stack();
            if ($stack->current() !== $event->context) {
                return;
            }
            $depth = ContextDepth::of($stack);
            for ($i = count(self::$armed) - 1; $i >= 0; $i--) {
                [$announcement, $armedAt] = self::$armed[$i];
                if ($depth === $armedAt + 1 && $announcement->belongsTo($event->context)) {
                    self::disarm($announcement);
                    $announcement->send($event->context);

                    return;
                }
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
}
