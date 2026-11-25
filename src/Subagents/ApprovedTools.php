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

    /**
     * @param  Closure(list<Message>):void  $record
     * @return list<StreamEvent>
     */
    public function resolve(TextPending|StructuredPending $generation, Closure $record): array
    {
        $request = $generation->toRequest();
        $before = $request->messages();
        $events = null;
        // Core reports each call it runs here with a ToolInvoked telemetry
        // event, but only under a telemetry context, which core opens inside
        // its own generation. This resolution runs before that, so it runs
        // under a context of its own that opens no generation.
        //
        // Those events are held until the merge below is recorded: a
        // listener that throws must not stop a call that has already run
        // from being recorded, or the next resume would run it again. A
        // listener failure after that is reported, never thrown.
        try {
            Event::defer(function () use ($generation, $record, $request, $before, &$events): void {
                $context = null;
                if (Telemetry::enabled() && Telemetry::current() === null) {
                    $context = new TelemetryContext(
                        traceId: (string) Str::uuid(),
                        operation: $generation instanceof StructuredPending ? TelemetryOperation::Structured : TelemetryOperation::Text,
                        provider: (string) $request->provider(),
                        model: $request->model(),
                        startedAt: microtime(true),
                    );
                    Telemetry::stack()->push($context);
                }
                try {
                    // Consume ONLY the resolution events. Core merges after
                    // yielding its results; exposing them before that
                    // merge/writeback could replay a side effect if the
                    // consumer abandons the generator at that yield.
                    $resolved = iterator_to_array($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()), false);
                } finally {
                    Telemetry::end($context);
                }
                if ($request->messages() !== $before) {
                    $record($request->messages());
                    $generation->withMessages($request->messages());
                }
                $events = $resolved;
            }, [ToolInvoked::class]);
        } catch (Throwable $failure) {
            if ($events === null) {
                throw $failure;
            }
            report($failure);
        }

        return $events;
    }
}
