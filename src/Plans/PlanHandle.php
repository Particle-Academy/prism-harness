<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

use Closure;
use Generator;
use Prism\Harness\Exceptions\PlanRefused;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Subagents\RunContext;

/**
 * One session's plan, for the host (G-360).
 *
 * advance() moves the plan at most ONE step: it settles the previous step
 * from what the thread and the run record say, checks the limits, and runs
 * one turn on the next step. Settling never trusts a job's return value and
 * never reads the model's prose: a step is done only when the agent called
 * plan_complete_step and the turn ended cleanly with nothing pending.
 *
 * answerStep(), resume() and cancel() are host calls. WHO MAY MAKE THEM IS
 * THE APPLICATION'S DECISION, as with approve(): authorize first, and name the
 * person as the RunContext's actor. The actor is never taken from the model.
 */
final class PlanHandle
{
    public function __construct(private readonly Session $session, private readonly Plans $plans) {}

    /** The whole plan, the target of fetch-on-change; null when there is none. */
    public function current(): ?PlanSnapshot
    {
        $plan = $this->plans->load($this->session);

        return $plan === null ? null : new PlanSnapshot($plan);
    }

    /** The plan context block as the model would read it now; '' when there is no live plan. */
    public function context(): string
    {
        $plan = $this->plans->load($this->session);

        return $plan === null ? '' : PlanView::context($plan, $this->plans->limits());
    }

    public function advance(?RunContext $context = null): AdvanceOutcome
    {
        return $this->locked(function () use ($context): AdvanceOutcome {
            [$outcome, $step] = $this->prepare();
            if ($step === null) {
                return $outcome;
            }
            $response = $this->session->send($this->prompt($step), context: $context);

            return new AdvanceOutcome('ran', 'active', $step['id'], $response->runId);
        });
    }

    /** An alias of advance(), the name the request used. */
    public function continuePlan(?RunContext $context = null): AdvanceOutcome
    {
        return $this->advance($context);
    }

    /**
     * advance(), delivered as run steps. Returns the outcome when exhausted.
     *
     * @return Generator<int, RunStep, mixed, AdvanceOutcome>
     */
    public function streamAdvance(?RunContext $context = null): Generator
    {
        $release = $this->session->holdPlanLock($this->lockTtl());
        try {
            [$outcome, $step] = $this->prepare();
            if ($step === null) {
                return $outcome;
            }
            $runId = null;
            foreach ($this->session->streamSteps($this->prompt($step), context: $context) as $runStep) {
                $runId ??= $runStep->runId;
                yield $runStep;
            }

            return new AdvanceOutcome('ran', 'active', $step['id'], $runId);
        } finally {
            $release();
        }
    }

    /**
     * A person answers a question step. The answer is data the agent reads
     * on its next turn; it is never an approval and releases nothing held.
     */
    public function answerStep(string $stepId, string $text, ?string $choice, RunContext $context): PlanSnapshot
    {
        $plan = $this->plans->mutate($this->session, fn (array $plan): array => PlanMachine::answer($plan, $stepId, $text, $choice, $context->actor, $this->plans->now()));
        if ($plan === null) {
            throw PlanRefused::because('plan.not_answerable');
        }

        return new PlanSnapshot($plan);
    }

    /** The host unblocks the plan. See PlanMachine::resume() for what is retried. */
    public function resume(RunContext $context): PlanSnapshot
    {
        $this->actor($context);
        $plan = $this->plans->mutate($this->session, fn (array $plan): array => PlanMachine::resume($plan, $this->plans->ids(), $this->plans->limits(), $this->plans->now()));
        if ($plan === null) {
            throw PlanRefused::because('plan.not_resumable');
        }

        return new PlanSnapshot($plan);
    }

    /** The host stops the plan: every open step is skipped. */
    public function cancel(RunContext $context): PlanSnapshot
    {
        $this->actor($context);
        $plan = $this->plans->mutate($this->session, fn (array $plan): array => PlanMachine::cancel($plan, $this->plans->now()));
        if ($plan === null) {
            throw PlanRefused::because('plan.not_live');
        }

        return new PlanSnapshot($plan);
    }

    /**
     * Settle, check limits, choose, and claim, under the plan lock.
     *
     * @return array{0: AdvanceOutcome, 1: array<string, mixed>|null}
     */
    private function prepare(): array
    {
        $plan = $this->plans->reconciled($this->session);
        if ($plan === null) {
            return [new AdvanceOutcome('not_active', null), null];
        }
        $run = $this->session->run();
        $runFailed = is_array($run) && ($run['status'] ?? null) === 'failed';
        $now = $this->plans->now();
        [$next, $outcome] = PlanMachine::prepare($plan, $runFailed, $this->plans->limits(), $now, null);
        if ($next !== $plan) {
            $this->plans->write($this->session, $next, $plan['revision']);
        }
        [$kind, $value] = $outcome;
        if ($kind !== 'run') {
            return [match ($kind) {
                'waiting' => new AdvanceOutcome('waiting', $next['status'], $value),
                'blocked' => new AdvanceOutcome('blocked', $next['status'], reason: $value),
                'done' => new AdvanceOutcome('done', $next['status']),
                default => new AdvanceOutcome('not_active', $next['status']),
            }, null];
        }
        $claimed = PlanMachine::claim($next, (string) $value, 'advance_'.bin2hex(random_bytes(6)), $now + $this->lockTtl(), $now);
        $this->plans->write($this->session, $claimed, $next['revision']);
        foreach ($claimed['steps'] as $step) {
            if ($step['id'] === $value) {
                return [new AdvanceOutcome('ran', 'active', $value), $step];
            }
        }

        throw PlanRefused::because('plan.conflict');
    }

    /** @param array<string, mixed> $step */
    private function prompt(array $step): string
    {
        // The host's fixed words. The step's own instruction reaches the
        // model only through the fenced plan context, never as the user turn.
        return sprintf('Continue the plan. Work on step %d.', $step['position']);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function locked(Closure $work): mixed
    {
        $release = $this->session->holdPlanLock($this->lockTtl());
        try {
            return $work();
        } finally {
            $release();
        }
    }

    private function lockTtl(): int
    {
        $ttl = config('prism-harness.agent.lock_ttl', 300);

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }

    private function actor(RunContext $context): void
    {
        if (! is_string($context->actor) || trim($context->actor) === '') {
            throw PlanRefused::because('plan.no_actor');
        }
    }
}
