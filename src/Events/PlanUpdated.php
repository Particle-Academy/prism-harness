<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * A plan was written (G-360): drafted, decided, settled, answered, resumed,
 * cancelled or swept, inside a run or outside one.
 *
 * COUNTS AND IDS ONLY, and a fixed size, so it fits a broadcast payload cap
 * with room to spare. A client that sees a new revision fetches the plan from
 * the host (PlanHandle::current()). No title, instruction, note, question or
 * answer travels here. `runId` is the run that made the write, or '' for a
 * host call made outside a run.
 */
final readonly class PlanUpdated extends HarnessEvent
{
    /**
     * @param  array{total: int, todo: int, running: int, held: int, waiting: int, done: int, failed: int, skipped: int}  $counts
     */
    public function __construct(
        string $sessionKey,
        string $runId,
        public string $planId,
        public int $revision,
        public string $status,
        public ?string $holdingApprovalId,
        public array $counts,
        ?string $harnessAgent = null,
    ) {
        parent::__construct($sessionKey, $runId, harnessAgent: $harnessAgent);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->correlation(),
            'plan_id' => $this->planId,
            'revision' => $this->revision,
            'status' => $this->status,
            'holding_approval_id' => $this->holdingApprovalId,
            'counts' => $this->counts,
        ];
    }
}
