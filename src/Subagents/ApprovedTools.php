<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
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
 * when it starts (the one the results are sent in), so a tracer that builds
 * tool spans only inside an open generation keeps them. When no generation
 * follows, they go out under a bare context of their own. Each is dispatched
 * on its own: a listener that throws is reported, never thrown, and costs no
 * other call its event.
 *
 * Call {@see self::announce()} once the next generation has returned (or
 * failed), in a `finally`.
 */
final class ApprovedTools
{
    use CallsTools {
        executeToolCall as private coreExecuteToolCall;
        failedToolExecution as private coreFailedToolExecution;
    }

    /** @var list<ResolutionAnnouncement> resolutions in progress, innermost last */
    private static array $resolving = [];

    /** @var list<ResolutionAnnouncement> recorded resolutions waiting for their generation */
    private static array $waiting = [];

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
        if ($announcement->pending() && Telemetry::enabled()) {
            self::listen();
            self::$waiting[] = $announcement;
        }

        return $events;
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
        self::$waiting = array_values(array_filter(self::$waiting,
            static fn (ResolutionAnnouncement $waiting): bool => $waiting !== $announcement));
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
            foreach ($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()) as $event) {
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

    /** Listen, once per dispatcher, for the generation a waiting resolution's results are sent in. */
    private static function listen(): void
    {
        $events = app('events');
        self::$listening ??= new WeakMap;
        if (isset(self::$listening[$events])) {
            return;
        }
        self::$listening[$events] = true;
        $events->listen(GenerationStarted::class, static function (GenerationStarted $event): void {
            for ($i = count(self::$waiting) - 1; $i >= 0; $i--) {
                $announcement = self::$waiting[$i];
                if ($announcement->belongsTo($event->context)) {
                    array_splice(self::$waiting, $i, 1);
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
