<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Illuminate\Support\Str;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Structured\PendingRequest as StructuredPending;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Text\PendingRequest as TextPending;

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
        // Consume ONLY the resolution events. Core merges after yielding its
        // results; exposing them before that merge/writeback could replay a
        // side effect if the consumer abandons the generator at that yield.
        //
        // Core reports each call it runs here with a ToolInvoked telemetry
        // event, but only under a telemetry context, which core opens inside
        // its own generation. This resolution runs before that, so it runs
        // under a context of its own: the events reach their listeners as
        // they did when core resolved approvals itself, and no generation is
        // opened for a step that makes no request.
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
            $events = iterator_to_array($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()), false);
        } finally {
            Telemetry::end($context);
        }
        if ($request->messages() !== $before) {
            $record($request->messages());
            $generation->withMessages($request->messages());
        }

        return $events;
    }
}
