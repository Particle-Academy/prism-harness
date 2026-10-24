<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\StreamCollector;
use Prism\Prism\Structured\PendingRequest as StructuredPending;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder as StructuredBuilder;
use Prism\Prism\Structured\Step as StructuredStep;
use Prism\Prism\Text\PendingRequest as TextPending;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder as TextBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/** Only capped runs take this path; core still assembles each call's messages. */
final readonly class BudgetedCalls
{
    public function __construct(private CallBudget $price) {}

    /** @param Closure(TextResponse):void $record
     * @param  Closure(list<Message>):void  $resolved
     */
    public function text(TextPending $generation, RunContext $run, Closure $record, Closure $resolved): TextResponse
    {
        $builder = new TextBuilder;
        try {
            do {
                $run->ledger->assertCallAvailable($run->budget);
                (new ApprovedTools)->resolve($generation, $resolved);
                $generation->withMaxSteps(1)->withMaxTokens($this->price->maxOutputTokens);
                $request = $generation->toRequest();
                [$reservation, $call] = $this->reserve($run, $request, 'text');
                $usage = null;
                $outcome = 'failed';
                try {
                    $run->ledger->recordSteps(1);
                    $response = $generation->asText();
                    $usage = $response->usage;
                    $outcome = 'completed';
                    // Prism::fake may omit steps. Real handlers supply one per call.
                    if ($response->steps->isEmpty()) {
                        $builder->addStep(new Step($response->text, $response->finishReason, $response->toolCalls,
                            $response->toolResults, [], $response->usage, $response->meta, $request->messages(),
                            $request->systemPrompts(), $response->additionalContent, $response->raw));
                    } else {
                        foreach ($response->steps as $step) {
                            $builder->addStep($step);
                        }
                    }
                    $combined = $builder->toResponse();
                    $generation->withMessages($combined->messages->all());
                } finally {
                    $this->settle($run, $reservation, $call, $usage, $outcome);
                }
            } while ($this->continues($response->finishReason, $response->toolCalls, $response->toolResults)
                && $run->ledger->steps() < $run->budget->maxSteps);

            return $combined;
        } finally {
            // Includes completed calls when admission refuses the NEXT call.
            if ($builder->steps->isNotEmpty()) {
                $record($builder->toResponse());
            }
        }
    }

    /** @param Closure(StructuredResponse):void $record
     * @param  Closure(list<Message>):void  $resolved
     */
    public function structured(StructuredPending $generation, RunContext $run, Closure $record, Closure $resolved): StructuredResponse
    {
        $builder = new StructuredBuilder;
        try {
            do {
                $run->ledger->assertCallAvailable($run->budget);
                (new ApprovedTools)->resolve($generation, $resolved);
                $generation->withMaxSteps(1)->withMaxTokens($this->price->maxOutputTokens);
                $request = $generation->toRequest();
                [$reservation, $call] = $this->reserve($run, $request, 'structured');
                $usage = null;
                $outcome = 'failed';
                try {
                    $run->ledger->recordSteps(1);
                    $response = $generation->asStructured();
                    $usage = $response->usage;
                    $outcome = 'completed';
                    if ($response->steps->isEmpty()) {
                        $messages = [...$request->messages(), new AssistantMessage($response->text, $response->toolCalls, $response->additionalContent)];
                        if ($response->toolResults !== []) {
                            $messages[] = new ToolResultMessage($response->toolResults);
                        }
                        $builder->addStep(new StructuredStep(text: $response->text, finishReason: $response->finishReason,
                            usage: $response->usage, meta: $response->meta, messages: $messages, systemPrompts: $request->systemPrompts(),
                            structured: $response->structured ?? [], toolCalls: $response->toolCalls, toolResults: $response->toolResults,
                            additionalContent: $response->additionalContent, raw: $response->raw));
                    } else {
                        foreach ($response->steps as $step) {
                            $builder->addStep($step);
                        }
                    }
                    // Continuation uses core's step messages and its actual tool
                    // results, not a fresh user prompt or another tool execution.
                    $last = $builder->steps->last();
                    if ($last !== null) {
                        $messages = $last->messages;
                        // Providers differ on whether the last step includes its
                        // assistant/results. Append only the missing objects.
                        $tail = end($messages);
                        if (! $tail instanceof ToolResultMessage && $response->toolResults !== []) {
                            if (! $tail instanceof AssistantMessage) {
                                $messages[] = new AssistantMessage($last->text, $last->toolCalls, $last->additionalContent,
                                    $last->toolApprovalRequests);
                            }
                            $messages[] = new ToolResultMessage($last->toolResults);
                        }
                        $generation->withMessages(array_values($messages));
                    }
                } finally {
                    $this->settle($run, $reservation, $call, $usage, $outcome);
                }
            } while ($this->continues($response->finishReason, $response->toolCalls, $response->toolResults)
                && $run->ledger->steps() < $run->budget->maxSteps);

            return $builder->steps->isEmpty() ? $response : $builder->toResponse();
        } finally {
            if ($builder->steps->isNotEmpty()) {
                $record($builder->toResponse());
            } elseif (isset($response)) {
                $record($response);
            }
        }
    }

    /**
     * @param  Closure(list<Message>, TextResponse):void  $record  Each completed call's core-built messages.
     * @param  Closure(list<Message>):void  $resolved
     * @return Generator<StreamEvent>
     */
    public function stream(TextPending $generation, RunContext $run, Closure $record, Closure $resolved): Generator
    {
        do {
            $run->ledger->assertCallAvailable($run->budget);
            foreach ((new ApprovedTools)->resolve($generation, $resolved) as $event) {
                yield $event;
            }
            $generation->withMaxSteps(1)->withMaxTokens($this->price->maxOutputTokens);
            $request = $generation->toRequest();
            [$reservation, $call] = $this->reserve($run, $request, 'stream');
            $usage = null;
            $outcome = 'abandoned';
            $collected = null;
            $approvals = [];
            // Reported usage is only final once the stream has ENDED normally
            // with no error along the way. Decided in `finally`, so a consumer
            // that stops reading early, or an exception, cannot skip it.
            $final = false;
            $failed = false;
            try {
                $run->ledger->recordSteps(1);
                $collector = new StreamCollector($generation->asStream(), null,
                    function (mixed $pending, Collection $messages, TextResponse $response) use (&$collected): void {
                        $collected = ['messages' => $messages->all(), 'response' => $response];
                    });
                foreach ($collector->collect() as $event) {
                    if (($event instanceof StepFinishEvent || $event instanceof StreamEndEvent) && $event->usage !== null) {
                        $usage = $event->usage;
                    }
                    // Older cores mark a provider error recoverable and still end
                    // with Stop, so the error event itself is the evidence.
                    // StepFinishEvent has no finish reason before core 0.132, so
                    // the stream's end carries the verdict on every core.
                    if ($event instanceof ErrorEvent || ($event instanceof StreamEndEvent
                        && in_array($event->finishReason, [FinishReason::Error, FinishReason::Unknown], true))) {
                        $failed = true;
                    }
                    if ($event instanceof StreamEndEvent) {
                        $final = ! $failed;
                    }
                    if ($event instanceof ToolApprovalRequestEvent) {
                        $approvals[] = new ToolApprovalRequest($event->approvalId, $event->toolCall->id);
                    }
                    yield $event;
                }
                $outcome = 'completed';
                if ($collected === null) {
                    return;
                }
                $messages = $collected['messages'];
                // Core's collector does not retain approval events on messages.
                // Preserve those IDs without rebuilding its text/tool transcript.
                if ($approvals !== []) {
                    foreach ($messages as $index => $message) {
                        if ($message instanceof AssistantMessage) {
                            $ids = array_map(fn ($call): string => $call->id, $message->toolCalls);
                            $pending = array_values(array_filter($approvals, fn ($approval): bool => in_array($approval->toolCallId, $ids, true)));
                            $messages[$index] = new AssistantMessage($message->content, $message->toolCalls, $message->additionalContent, $pending);
                        }
                    }
                }
                $response = $collected['response'];
                $record($messages, $response);
                $generation->withMessages([...$request->messages(), ...$messages]);
            } catch (Throwable $failure) {
                $outcome = 'failed';
                throw $failure;
            } finally {
                // Anything short of a normal end may have generated output its
                // reported usage does not cover: charge the reserved maximum.
                $this->settle($run, $reservation, $call, $usage, $outcome, trusted: $final && ! $failed);
            }
        } while ($approvals === [] && $this->continues($response->finishReason, $response->toolCalls, $response->toolResults, stream: true)
            && $run->ledger->steps() < $run->budget->maxSteps);
    }

    /** @param 'text'|'structured'|'stream' $kind
     * @return array{int, CallReservation}
     */
    private function reserve(RunContext $run, TextRequest|StructuredRequest $request, string $kind): array
    {
        $call = new CallReservation(bin2hex(random_bytes(16)),
            $request->provider() ?? throw new \LogicException('A capped call needs a provider.'),
            $request->model(), $this->price->worstCase($request), $kind);
        $reservation = $run->ledger->reserveCost($run->budget, $call->worstCaseUsd);
        try {
            $run->observer?->reserving($call, $run);
        } catch (Throwable $failure) {
            $run->ledger->releaseCost($reservation);
            throw RunNotPermitted::refusedByHost($failure);
        }

        return [$reservation, $call];
    }

    /** @param 'completed'|'failed'|'abandoned' $outcome */
    /** An untrusted usage is still reported to the observer, but never priced. */
    private function settle(RunContext $run, int $reservation, CallReservation $call, ?Usage $usage, string $outcome, bool $trusted = true): void
    {
        $cost = $trusted ? $this->price->costOf($usage) : null;
        $charged = $run->ledger->chargeReservation($reservation, $cost);
        $run->observer?->settled(new CallSettlement($call->callId, $charged, $cost !== null, $usage, $outcome), $run);
    }

    /**
     * @param  array<ToolCall>  $calls
     * @param  array<ToolResult>  $results
     */
    private function continues(FinishReason $finish, array $calls, array $results, bool $stream = false): bool
    {
        // Core 0.123.0's Anthropic stream reports Stop even when maxSteps(1)
        // ends on executed tools; later core reports ToolCalls. Both are allowed
        // by our dependency floor. The pin test checks this real handler seam;
        // complete tool/result pairs, not that finish literal, drive its loop.
        if ((! in_array($finish, $stream ? [FinishReason::ToolCalls, FinishReason::Stop] : [FinishReason::ToolCalls], true)) || $calls === []) {
            return false;
        }
        $answered = array_map(fn ($result): string => $result->toolCallId, $results);

        foreach ($calls as $call) {
            if (! in_array($call->id, $answered, true)) {
                return false; // approval-required or client-executed tool: wait for its owner
            }
        }

        return true;
    }
}
