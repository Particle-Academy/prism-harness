<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Streaming\EventID;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Structured\PendingRequest as StructuredPending;
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
        $events = iterator_to_array($this->resolveToolApprovalsAndYieldEvents($request, EventID::generate()), false);
        if ($request->messages() !== $before) {
            $record($request->messages());
            $generation->withMessages($request->messages());
        }

        return $events;
    }
}
