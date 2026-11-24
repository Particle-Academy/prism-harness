<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

/**
 * The whole plan as stored, for the host's fetch-on-change endpoint (G-360).
 *
 * This is the one place the instruction, the step notes, the questions and
 * the answers are available. Events and run steps carry ids, titles, states
 * and counts only. Whoever serves this to a client decides who may read it.
 */
final readonly class PlanSnapshot
{
    /** @param array<string, mixed> $plan */
    public function __construct(private array $plan) {}

    public function id(): string
    {
        return $this->plan['id'];
    }

    public function status(): string
    {
        return $this->plan['status'];
    }

    public function revision(): int
    {
        return $this->plan['revision'];
    }

    public function holdingApprovalId(): ?string
    {
        return $this->plan['holdingApprovalId'];
    }

    public function blockedReason(): ?string
    {
        return $this->plan['blockedReason'];
    }

    /** @return list<array<string, mixed>> */
    public function steps(): array
    {
        return $this->plan['steps'];
    }

    /** @return array{total: int, todo: int, running: int, held: int, waiting: int, done: int, failed: int, skipped: int} */
    public function counts(): array
    {
        return PlanView::counts($this->plan);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $plan = $this->plan;
        unset($plan['draftDigest']);
        if (is_array($plan['pendingChange'])) {
            unset($plan['pendingChange']['digest']);
        }
        foreach ($plan['steps'] as $i => $step) {
            unset($plan['steps'][$i]['pauseDigest']);
        }

        return $plan;
    }
}
