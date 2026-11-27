<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Generator;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Structured\PendingRequest as StructuredPending;
use Prism\Prism\Text\PendingRequest as TextPending;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;

/**
 * Core resolves approvals before HTTP, potentially expanding input after our
 * bound. Reuse its resolver before admission and persist the merge first.
 * This protected core seam is exercised on the minimum and current versions.
 */
final class ApprovedTools
{
    /** What a call reads that may not run: no started row could be written for it (G-410). */
    public const NOT_OPEN = 'Not run: this call is not the one that was approved, or it is no longer open.';

    use CallsTools {
        executeToolCall as private coreExecuteToolCall;
    }

    /**
     * Asked right before an approved call runs, while resolve() drains the
     * resolver (G-410). Static because core's executeToolCall() is.
     *
     * @var (Closure(ToolCall): bool)|null
     */
    private static ?Closure $starter = null;

    /**
     * @param  Closure(list<Message>):void  $record
     * @param  (Closure(ToolCall): bool)|null  $started  asked right before an approved call runs; false: it does not run (G-410)
     * @return list<StreamEvent>
     */
    public function resolve(TextPending|StructuredPending $generation, Closure $record, ?Closure $started = null): array
    {
        $request = $generation->toRequest();
        $before = $request->messages();
        // Consume ONLY the resolution events. Core merges after yielding its
        // results; exposing them before that merge/writeback could replay a
        // side effect if the consumer abandons the generator at that yield.
        $events = [];
        $outer = self::$starter;
        self::$starter = $started;
        try {
            self::drain($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()), $events);
        } catch (Throwable $failure) {
            // A tool that throws its own exception (one built
            // withoutErrorHandling()) stops the resolution before core merges
            // anything. The calls resolved before it have run, so their
            // results are recorded first, as one tool result row with only
            // what is new; otherwise the next resume would run them again.
            $resolved = [];
            foreach ($events as $event) {
                if ($event instanceof ToolResultEvent) {
                    $resolved[] = $event->toolResult;
                }
            }
            $partial = ResolvedApprovals::merged($request->messages(), $resolved);
            if ($partial !== null) {
                try {
                    $record($partial);
                    $generation->withMessages($partial);
                } catch (Throwable $unrecorded) {
                    report($unrecorded);
                }
            }

            throw $failure;
        } finally {
            self::$starter = $outer;
        }
        if ($request->messages() !== $before) {
            $record($request->messages());
            $generation->withMessages($request->messages());
        }

        return $events;
    }

    /**
     * Core's call of one approved tool, after its write-ahead started row
     * (G-410). A call no started row could be written for does not run: it
     * reads {@see self::NOT_OPEN} as its one outcome.
     *
     * @param  array<int, Tool>  $tools
     * @return array{toolResult: ToolResult, events: array<int, mixed>, durationMs: float, failed: bool, failure: ?string}
     */
    protected static function executeToolCall(array $tools, ToolCall $toolCall, string $messageId, bool $deferClassification = false): array
    {
        if (self::$starter !== null && ! (self::$starter)($toolCall)) {
            $refused = new ToolResult($toolCall->id, $toolCall->name, $toolCall->argumentsForResult(), self::NOT_OPEN, $toolCall->resultId);

            return ['toolResult' => $refused, 'events' => [new ToolResultEvent(id: EventID::generate(), timestamp: time(),
                toolResult: $refused, messageId: $messageId, success: false, error: self::NOT_OPEN)],
                'durationMs' => 0.0, 'failed' => true, 'failure' => 'tool_error'];
        }

        return self::coreExecuteToolCall($tools, $toolCall, $messageId, $deferClassification);
    }

    /**
     * Collect the resolver's events into $events as they come, so that what
     * was resolved before a failure is still there when the resolver throws.
     *
     * @param  Generator<StreamEvent>  $resolver
     * @param  list<StreamEvent>  $events
     *
     * @param-out list<StreamEvent> $events
     */
    private static function drain(Generator $resolver, array &$events): void
    {
        foreach ($resolver as $event) {
            $events[] = $event;
        }
    }
}
