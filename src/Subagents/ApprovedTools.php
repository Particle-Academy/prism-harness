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
        // Consume ONLY the resolution events. Core merges after yielding its
        // results; exposing them before that merge/writeback could replay a
        // side effect if the consumer abandons the generator at that yield.
        $events = [];
        try {
            self::drain($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()), $events);
        } catch (Throwable $failure) {
            // A tool that throws its own exception (one built
            // withoutErrorHandling()) stops the resolution before core merges
            // anything. The calls resolved before it have run, so their
            // results are recorded first, as core would have merged them;
            // otherwise the next resume would run them again.
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
        }
        if ($request->messages() !== $before) {
            $record($request->messages());
            $generation->withMessages($request->messages());
        }

        return $events;
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
