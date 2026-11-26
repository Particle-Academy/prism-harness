<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * The results of a resumed run's approved calls, as core resolves them inside
 * a plain send() or structured turn.
 *
 * Core runs those calls first in the generation and keeps their results to
 * itself until the generation returns. When it throws instead (a tool built
 * withoutErrorHandling(), a provider failure on a later step), the calls that
 * already ran had no result anywhere, and the next resume ran them again. The
 * recorder holds what each one returned, so the harness can record it before
 * the failure goes on.
 *
 * A call is matched in core's order: the approved calls of the last assistant
 * step that have no result yet. Each return of a watched tool is matched to
 * the next of those calls with the same tool name and the same arguments, and
 * the first return that matches none ends the matching. A call core refused,
 * or that threw, has no return, and no result is recorded for it.
 *
 * @internal
 */
final class ResolutionRecorder
{
    private int $next = 0;

    private bool $open = true;

    /** @var list<ToolResult> */
    private array $results = [];

    /** @param non-empty-list<ToolCall> $queue */
    private function __construct(private readonly array $queue) {}

    /**
     * A recorder for the calls core will resolve from $messages, or null when
     * there are none.
     *
     * @param  list<Message>  $messages
     */
    public static function for(array $messages): ?self
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $assistant = $messages[$i];
            if ($assistant instanceof AssistantMessage && $assistant->toolCalls !== []) {
                $done = [];
                $approved = [];
                foreach (array_slice($messages, $i + 1) as $message) {
                    if ($message instanceof ToolResultMessage) {
                        foreach ($message->toolResults as $result) {
                            $done[$result->toolCallId] = true;
                        }
                        foreach ($message->toolApprovalResponses as $response) {
                            $approved[$response->approvalId] ??= $response->approved;
                        }
                    }
                }
                $approvalOf = [];
                foreach ($assistant->toolApprovalRequests as $request) {
                    $approvalOf[$request->toolCallId] = $request->approvalId;
                }
                $queue = array_values(array_filter($assistant->toolCalls, fn (ToolCall $call): bool => ! isset($done[$call->id])
                    && isset($approvalOf[$call->id]) && ($approved[$approvalOf[$call->id]] ?? false) === true));

                return $queue === [] ? null : new self($queue);
            }
        }

        return null;
    }

    /**
     * $tools, with each tool a queued call names watched; null when one of
     * them cannot be watched (see ObservedTool), and the caller must resolve
     * the approvals itself before the turn.
     *
     * @param  list<Tool>  $tools
     * @return list<Tool>|null
     */
    public function watch(array $tools): ?array
    {
        $names = array_map(fn (ToolCall $call): string => $call->name, $this->queue);
        $watched = [];
        foreach ($tools as $tool) {
            if (! in_array($tool->name(), $names, true)) {
                $watched[] = $tool;

                continue;
            }
            $observed = ObservedTool::of($tool, $this);
            if ($observed === null) {
                return null;
            }
            $watched[] = $observed;
        }

        return $watched;
    }

    /** @param array<int|string, mixed> $args */
    public function completed(string $name, array $args, string|ToolOutput|ToolError $output): void
    {
        if (! $this->open) {
            return;
        }
        $count = count($this->queue);
        for ($i = $this->next; $i < $count; $i++) {
            $call = $this->queue[$i];
            if ($call->name === $name && $call->arguments() === $args) {
                $this->next = $i + 1;
                $this->open = $this->next < $count;
                $this->results[] = new ToolResult(
                    toolCallId: $call->id,
                    toolName: $call->name,
                    args: $call->arguments(),
                    result: match (true) {
                        $output instanceof ToolError => $output->message,
                        $output instanceof ToolOutput => $output->result,
                        default => $output,
                    },
                    toolCallResultId: $call->resultId,
                    artifacts: $output instanceof ToolOutput ? $output->artifacts : [],
                );

                return;
            }
        }
        $this->open = false;
    }

    /** @return list<ToolResult> */
    public function results(): array
    {
        return $this->results;
    }
}
