<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Support\CallPairing;
use Prism\Harness\Support\PausedStep;
use Prism\Harness\Support\StepRows;
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
    /** What a call that may not run reads: one that is no longer open (the thread keeps the outcome it has), or one no request of its own binds. */
    public const NOT_OPEN = 'Not run: this call is not the one that was approved, or it is no longer open.';

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
    public function resolve(TextPending|StructuredPending $generation, Closure $record, ?Closure $started = null): array
    {
        $this->announce();
        $request = $generation->toRequest();
        $announcement = new ResolutionAnnouncement(
            $request,
            $generation instanceof StructuredPending ? [TelemetryOperation::Structured] : [TelemetryOperation::Text, TelemetryOperation::Stream],
            $generation->providerKey(),
            $request->model(),
        );
        $announcement->starter = $started;
        $this->announcement = $announcement;

        $events = [];
        $answered = false;
        try {
            $this->collect($request, $announcement, $events, $answered);
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
        // Recorded when anything resolved, or when the request now answers
        // a call it did not (round 17: a host's unanswered ungated call).
        // Known from what was done, never from comparing the message list
        // (core rebuilds some messages on every read).
        if ($events !== [] || $answered) {
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
     * @param  bool  $answered  set when an unanswered ungated call was answered (round 17)
     */
    private function collect(TextRequest|StructuredRequest $request, ResolutionAnnouncement $announcement, array &$events, bool &$answered = false): void
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
            //
            // And only the PAUSED step is resolved, chosen by the one rule
            // every paused-step reader uses (G-397): a step the conversation
            // moved past (an answer, a user turn, a system row after it) has
            // nothing a decision can still apply to, so nothing resolves it.
            $paused = PausedStep::indexIn($request->messages());
            $resolution = $paused === null
                ? []
                : (self::repeatsCallIds($request)
                    ? $this->resolveRepeatedStep($request, EventID::generate())
                    : $this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()));
            foreach ($resolution as $event) {
                $events[] = $event;
            }
            if ($paused !== null) {
                $answered = self::answerUngated($request, $paused);
                if ($events !== [] || $answered) {
                    self::answerInPlace($request, $paused);
                }
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
     * Answer the paused step's UNGATED calls that still have no result
     * (G-387 round 17, item 1): the resolver answers every gated call (run,
     * denied, or denied by default) and skips an ungated one, which the
     * runtime always stores with its step. A step a host recorded can carry
     * an ungated call it never answered; resuming it would send that call
     * with no output, a request no provider accepts. Nothing ran it here, and
     * it may have run where the host runs its own calls: it is answered
     * `interrupted`, recorded with the resolution as its one outcome.
     */
    private static function answerUngated(TextRequest|StructuredRequest $request, int $paused): bool
    {
        $messages = $request->messages();
        $step = $messages[$paused] ?? null;
        if (! $step instanceof AssistantMessage || $step->toolCalls === []) {
            return false;
        }
        $results = [];
        for ($i = $paused + 1, $n = count($messages); $i < $n; $i++) {
            if ($messages[$i] instanceof ToolResultMessage) {
                $results[] = CallPairing::ofResults($messages[$i]->toolResults);
            }
        }
        $calls = array_values($step->toolCalls);
        $answered = CallPairing::answered(CallPairing::ofCalls($calls), $results);
        // Only a call of a tool this request offers that needs no approval:
        // any other call is the resolver's (gated) or not this run's to
        // answer (a tool it does not offer), as before.
        $ungated = collect($request->tools())->reject(fn (Tool $tool): bool => $tool->hasApprovalConfigured())
            ->map(fn (Tool $tool): string => $tool->name())->values()->all();
        $missing = [];
        foreach ($calls as $at => $call) {
            if (! $answered[$at] && in_array($call->name, $ungated, true)) {
                $missing[] = new ToolResult($call->id, $call->name, $call->argumentsForResult(), StepRows::INTERRUPTED_RESULT, $call->resultId);
            }
        }
        if ($missing === []) {
            return false;
        }
        $last = array_key_last($messages);
        if ($messages[$last] instanceof ToolResultMessage && $last > $paused) {
            $merged = $messages[$last];
            $messages[$last] = new ToolResultMessage([...$merged->toolResults, ...$missing], $merged->toolApprovalResponses);
        } else {
            $messages[] = new ToolResultMessage($missing, []);
        }
        $request->setMessages(array_values($messages));

        return true;
    }

    /**
     * Place the resolution's result message directly after the paused step
     * (G-387 round 17, item 1). The resolver drops the step's result
     * messages and appends ONE merged message at the END of the request; a
     * row the replay keeps after the step (a system row, or any other row
     * that does not move the conversation past it) would then sit between
     * the step and its results, a request no provider accepts. Only the
     * resolver's own message moves; every other message keeps its order.
     */
    private static function answerInPlace(TextRequest|StructuredRequest $request, int $paused): void
    {
        $messages = $request->messages();
        $last = array_key_last($messages);
        if ($last === null || $last <= $paused + 1 || ! $messages[$last] instanceof ToolResultMessage) {
            return;
        }
        for ($i = $paused + 1; $i < $last; $i++) {
            if ($messages[$i] instanceof ToolResultMessage) {
                // Not the resolver's merge (it leaves no result message
                // after the step but its own): leave the request as it is.
                return;
            }
        }
        $answer = $messages[$last];
        array_splice($messages, $last, 1);
        array_splice($messages, $paused + 1, 0, [$answer]);
        $request->setMessages(array_values($messages));
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
        // The write-ahead started row (G-387 round 15), recorded before the
        // call runs. A call that is no longer open (a superseding write
        // denied it first, or it already started) does not run.
        $started = $announcement->starter === null ? true : ($announcement->starter)($toolCall);
        if ($started !== true) {
            $text = is_string($started) ? $started : self::NOT_OPEN;
            $refused = new ToolResult($toolCall->id, $toolCall->name, $toolCall->argumentsForResult(), $text, $toolCall->resultId);

            return ['toolResult' => $refused, 'events' => [new ToolResultEvent(id: EventID::generate(), timestamp: time(),
                toolResult: $refused, messageId: $messageId, success: false, error: $text)],
                'durationMs' => 0.0, 'failed' => true, 'failure' => 'tool_error'];
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
        // The paused step, by the shared rule (G-397), never merely the
        // newest step with tool calls.
        $messages = $request->messages();
        $at = PausedStep::indexIn($messages);
        $step = $at === null ? null : $messages[$at];

        return $step instanceof AssistantMessage ? [$step, $at] : null;
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
        $approvals = array_map(static fn ($approval): string => $approval->approvalId, $step[0]->toolApprovalRequests);

        return count($ids) !== count(array_unique($ids)) || count($asked) !== count(array_unique($asked))
            || count($approvals) !== count(array_unique($approvals));
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
            if (PausedStep::kindOfMessage($messages[$i]) !== PausedStep::RESULT) {
                // The conversation moved on past this step: it was settled
                // (and the replay folds its same-id results into one, so they
                // can no longer be counted). Nothing here is open.
                return;
            }
            if (! $messages[$i] instanceof ToolResultMessage) {
                // This turn's own prompt, or a system row.
                continue;
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
        $after = [];
        $results = [];
        $responses = [];
        foreach ($messages as $index => $message) {
            if ($index > $assistant && $message instanceof ToolResultMessage) {
                $results = [...$results, ...$message->toolResults];
                $responses = [...$responses, ...$message->toolApprovalResponses];

                continue;
            }
            if ($index > $assistant) {
                $after[] = $message;
            } else {
                $kept[] = $message;
            }
        }

        // Directly after the step (round 17), whatever rows follow it.
        return [...$kept, new ToolResultMessage([...$results, ...$resolved], $responses), ...$after];
    }
}
