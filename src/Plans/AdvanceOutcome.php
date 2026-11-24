<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

/**
 * What one advance() did (G-360).
 *
 * `ran`: one turn ran on `stepId`, as run `runId`. Call advance() again to
 * settle it and run the next step.
 * `waiting`: the head step waits on a person (an approval or an answer).
 * `blocked`: the plan stopped on a limit; `reason` says which.
 * `done`: every step is closed.
 * `not_active`: there is no active plan; `planStatus` says what there is.
 */
final readonly class AdvanceOutcome
{
    public function __construct(
        public string $status,
        public ?string $planStatus,
        public ?string $stepId = null,
        public ?string $runId = null,
        public ?string $reason = null,
    ) {}

    public function ran(): bool
    {
        return $this->status === 'ran';
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['status' => $this->status, 'planStatus' => $this->planStatus, 'stepId' => $this->stepId, 'runId' => $this->runId, 'reason' => $this->reason];
    }
}
