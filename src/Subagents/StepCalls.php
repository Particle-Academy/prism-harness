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
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\StreamCollector;
use Prism\Prism\Structured\PendingRequest as StructuredPending;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\ResponseBuilder as StructuredBuilder;
use Prism\Prism\Structured\Step as StructuredStep;
use Prism\Prism\Text\PendingRequest as TextPending;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\ResponseBuilder as TextBuilder;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/** One-call step admission for explicit/shared trees; pricing stays in BudgetedCalls. */
final readonly class StepCalls
{
    /** @param Closure(TextResponse):void $record
     * @param  Closure(list<Message>):void  $resolved
     */
    public function text(TextPending $generation, RunContext $run, Closure $record, Closure $resolved): TextResponse
    {
        $this->assertUncapped($run);
        $builder = new TextBuilder;
        try {
            do {
                $run->ledger->assertCallAvailable($run->budget);
                (new ApprovedTools)->resolve($generation, $resolved);
                // A resolved approval may itself delegate and consume the remainder.
                $run->ledger->assertCallAvailable($run->budget);
                $generation->withMaxSteps(1);
                $request = $generation->toRequest();
                $run->ledger->recordSteps(1);
                $response = $generation->asText();
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
            } while ($this->continues($response->finishReason, $response->toolCalls, $response->toolResults)
                && $run->ledger->steps() < $run->budget->maxSteps);

            return $combined;
        } finally {
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
        $this->assertUncapped($run);
        $builder = new StructuredBuilder;
        try {
            do {
                $run->ledger->assertCallAvailable($run->budget);
                (new ApprovedTools)->resolve($generation, $resolved);
                $run->ledger->assertCallAvailable($run->budget);
                $generation->withMaxSteps(1);
                $request = $generation->toRequest();
                $run->ledger->recordSteps(1);
                $response = $generation->asStructured();
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
                $last = $builder->steps->last();
                if ($last !== null) {
                    $messages = $last->messages;
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
     * @param  Closure(list<Message>, TextResponse):void  $record
     * @param  Closure(list<Message>):void  $resolved
     * @return Generator<StreamEvent>
     */
    public function stream(TextPending $generation, RunContext $run, Closure $record, Closure $resolved): Generator
    {
        $this->assertUncapped($run);
        do {
            $run->ledger->assertCallAvailable($run->budget);
            foreach ((new ApprovedTools)->resolve($generation, $resolved) as $event) {
                yield $event;
            }
            $run->ledger->assertCallAvailable($run->budget);
            $generation->withMaxSteps(1);
            $request = $generation->toRequest();
            $collected = null;
            $approvals = [];
            $final = false;
            $failed = false;
            $failureFinish = null;
            $run->ledger->recordSteps(1);
            $collector = new StreamCollector($generation->asStream(), null,
                function (mixed $pending, Collection $messages, TextResponse $response) use (&$collected): void {
                    $collected = ['messages' => $messages->all(), 'response' => $response];
                });
            foreach ($collector->collect() as $event) {
                if ($event instanceof ErrorEvent) {
                    $failed = true;
                    $failureFinish = FinishReason::Error;
                } elseif ($event instanceof StreamEndEvent) {
                    if (in_array($event->finishReason, [FinishReason::Error, FinishReason::Unknown], true)
                        || $event->usage === null) {
                        $failed = true;
                        $failureFinish ??= $event->finishReason === FinishReason::Error
                            ? FinishReason::Error : FinishReason::Unknown;
                    }
                    $final = ! $failed;
                }
                if ($event instanceof ToolApprovalRequestEvent) {
                    $approvals[] = new ToolApprovalRequest($event->approvalId, $event->toolCall->id);
                }
                yield $event;
            }
            if ($collected === null) {
                return;
            }
            $messages = $collected['messages'];
            $response = $collected['response'];
            if ($failed || ! $final) {
                $approvals = [];
                [$messages, $response] = $this->refuseFailedStream($messages, $response, $failureFinish ?? FinishReason::Unknown);
            }
            if ($approvals !== []) {
                foreach ($messages as $index => $message) {
                    if ($message instanceof AssistantMessage) {
                        $ids = array_map(fn (ToolCall $call): string => $call->id, $message->toolCalls);
                        $pending = array_values(array_filter($approvals, fn (ToolApprovalRequest $approval): bool => in_array($approval->toolCallId, $ids, true)));
                        $messages[$index] = new AssistantMessage($message->content, $message->toolCalls, $message->additionalContent, $pending);
                    }
                }
            }
            $record($messages, $response);
            $generation->withMessages([...$request->messages(), ...$messages]);
        } while ($final && ! $failed && $approvals === []
            && $this->continues($response->finishReason, $response->toolCalls, $response->toolResults, stream: true)
            && $run->ledger->steps() < $run->budget->maxSteps);
    }

    /** @param list<Message> $messages
     * @return array{list<Message>, TextResponse}
     */
    private function refuseFailedStream(array $messages, TextResponse $response, FinishReason $finish): array
    {
        foreach ($messages as $index => $message) {
            if ($message instanceof AssistantMessage && $message->toolApprovalRequests !== []) {
                $clean = new AssistantMessage($message->content, $message->toolCalls, $message->additionalContent);
                /** @var array<string, mixed> $options */
                $options = $message->providerOptions();
                $clean->withProviderOptions($options);
                if (($stability = $message->cacheStability()) !== null) {
                    $clean->withCacheHint($stability, $message->cacheTtl());
                }
                $messages[$index] = $clean;
            }
        }
        $answered = array_fill_keys(array_map(fn (ToolResult $result): string => $result->toolCallId, $response->toolResults), true);
        $refused = [];
        foreach ($response->toolCalls as $call) {
            if (! isset($answered[$call->id])) {
                $refused[] = new ToolResult($call->id, $call->name, $call->arguments(),
                    'Not run: model step failed ('.$finish->value.').', $call->resultId);
            }
        }
        if ($refused !== []) {
            $messages[] = new ToolResultMessage($refused);
        }

        return [$messages, new TextResponse($response->steps, $response->text, $finish,
            $response->toolCalls, [...$response->toolResults, ...$refused], $response->usage,
            $response->meta, collect($messages), $response->additionalContent, $response->raw)];
    }

    private function assertUncapped(RunContext $run): void
    {
        if ($run->budget->maxCostUsd !== null || $run->observer !== null
            || $run->ledger->inheritCostObserver(null) !== null) {
            throw RunNotPermitted::callBudget('step_only_requires_uncapped_tree');
        }
    }

    /** @param list<ToolCall> $calls
     * @param  list<ToolResult>  $results
     */
    private function continues(FinishReason $finish, array $calls, array $results, bool $stream = false): bool
    {
        // Core continues an Anthropic server-tool pause without a local tool call.
        if ($finish === FinishReason::Pause && $calls === []) {
            return true;
        }
        if (! in_array($finish, $stream ? [FinishReason::ToolCalls, FinishReason::Stop] : [FinishReason::ToolCalls], true)
            || $calls === []) {
            return false;
        }
        $answered = array_map(fn (ToolResult $result): string => $result->toolCallId, $results);
        foreach ($calls as $call) {
            if (! in_array($call->id, $answered, true)) {
                return false;
            }
        }

        return true;
    }
}
