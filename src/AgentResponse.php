<?php

declare(strict_types=1);

namespace Prism\Harness;

use Prism\Harness\Enums\Durability;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\ToolApprovalRequest;

/**
 * One run's result, and where that run sat in its tree.
 *
 * The lineage is exposed HERE rather than pushed through Prism's telemetry,
 * which carries only a user and session id. Widening that would mean teaching
 * the provider shuttle about a harness concept, and the boundary this package
 * holds is that the shuttle stays a shuttle.
 *
 * It exists so a consuming application can correlate its OWN live stream —
 * thinking, tool calls, tool results, rendered as they happen — against this
 * package's durable record, without routing its streaming through here. The
 * same three identifiers are on the stored rows (`run_id` on messages,
 * `parent_thread_id` and `root_run_id` on threads), so the join works after the
 * fact as well as during.
 */
final readonly class AgentResponse
{
    public function __construct(
        public string $runId,
        public Response $response,
        /** Null for a root run — this run was not called by another. */
        public ?string $parentRunId = null,
        /** The run at the top of the tree. Equals `$runId` for a root. */
        public ?string $rootRunId = null,
    ) {}

    public function text(): string
    {
        return $this->response->text;
    }

    /**
     * The identifiers a consuming app joins its stream on.
     *
     * @return array{run_id: string, parent_run_id: string|null, root_run_id: string}
     */
    public function correlation(): array
    {
        return [
            'run_id' => $this->runId,
            'parent_run_id' => $this->parentRunId,
            'root_run_id' => $this->rootRunId ?? $this->runId,
        ];
    }

    public function isChildRun(): bool
    {
        return $this->parentRunId !== null;
    }

    /**
     * Whether this run stopped on a tool that needs a human.
     *
     * NOT a failure, and the distinction matters more here than almost
     * anywhere else in the package: a caller that treats this as an error will
     * retry, and retrying discards the half-executed action a person was asked
     * to authorise. That is the exact loss the durable state slot exists to
     * prevent — see {@see Durability}.
     */
    public function awaitingApproval(): bool
    {
        return $this->response->finishReason === FinishReason::Pause
            && $this->pendingApprovals() !== [];
    }

    /**
     * The approvals this run is waiting on, oldest first.
     *
     * Read from the steps rather than stored separately, so there is one
     * source of truth: the thread rows and this list are the same facts, and
     * a resume on another worker rebuilds it from storage identically.
     *
     * @return list<ToolApprovalRequest>
     */
    public function pendingApprovals(): array
    {
        $pending = [];

        foreach ($this->response->steps as $step) {
            foreach ($step->toolApprovalRequests as $request) {
                $pending[] = $request;
            }
        }

        return $pending;
    }
}
