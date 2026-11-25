<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Structured\PendingRequest as StructuredPending;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Text\PendingRequest as TextPending;
use Throwable;

/**
 * Core resolves approvals before HTTP, potentially expanding input after our
 * bound. Reuse its resolver before admission and persist the merge first.
 * This protected core seam is exercised on the minimum and current versions.
 */
final class ApprovedTools
{
    use CallsTools;

    public function __construct(private readonly bool $inGeneration = false) {}

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
     * time and each step's events are dispatched after the step: a listener
     * that throws is reported, never thrown, so it can neither stop the
     * resolution before its result is recorded (an approved call whose result
     * was lost would run again on the next resume) nor keep the other calls'
     * events from being sent.
     *
     * Those events still reach their listeners before the record is written,
     * so a listener that ends the process in a way PHP cannot catch (a time
     * limit, running out of memory, a killed worker) loses the record. A
     * context of this resolution's own is therefore opened only when it stands
     * in for core's generation ($inGeneration, the plain send paths), which on
     * 0.26.7 ran the approved call under its own context and reported it the
     * same way. The step-by-step paths (subagents, a cost cap, every stream)
     * open none, as on 0.26.7: without a host context of its own, no listener
     * runs between an approved call and its record there.
     *
     * @param  Closure(list<Message>):void  $record
     * @return list<StreamEvent>
     */
    public function resolve(TextPending|StructuredPending $generation, Closure $record): array
    {
        $request = $generation->toRequest();
        $context = null;
        if ($this->inGeneration && Telemetry::enabled() && Telemetry::current() === null) {
            $context = new TelemetryContext(
                traceId: (string) Str::uuid(),
                operation: $generation instanceof StructuredPending ? TelemetryOperation::Structured : TelemetryOperation::Text,
                provider: (string) $request->provider(),
                model: $request->model(),
                startedAt: microtime(true),
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
            while (self::held(static fn (): bool => $resolver->valid())) {
                $events[] = $resolver->current();
                self::held(static function () use ($resolver): bool {
                    $resolver->next();

                    return true;
                });
            }
        } finally {
            Telemetry::end($context);
        }
        if ($events !== []) {
            $messages = $request->messages();
            $record($messages);
            $generation->withMessages($messages);
        }

        return $events;
    }

    /**
     * One step of the resolver, with core's ToolInvoked events held until it
     * returns and a listener failure reported, never thrown.
     *
     * @param  Closure(): bool  $step
     */
    private static function held(Closure $step): bool
    {
        $done = false;
        $result = false;
        try {
            Event::defer(function () use ($step, &$done, &$result): void {
                $result = $step();
                $done = true;
            }, [ToolInvoked::class]);
        } catch (Throwable $failure) {
            if (! $done) {
                throw $failure;
            }
            report($failure);
        }

        return $result;
    }
}
