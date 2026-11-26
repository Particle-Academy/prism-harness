<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Subagents\SubagentResult;
use RuntimeException;
use Throwable;

/**
 * Thrown when a run may not proceed — budget spent, tree cancelled, or a cost
 * cap that cannot be enforced.
 *
 * Thrown rather than returned, at the runtime boundary, for the reason
 * {@see ToolNotAvailable} gives: a model handed "you are out of budget" as a
 * tool RESULT reads it as information and tries again, spending the remainder
 * of a budget it has just been told is gone.
 *
 * A SUBAGENT call is the exception, and the difference is deliberate. There the
 * parent is a legitimate audience for the refusal — it can choose a cheaper
 * path or stop — so the subagent tool catches this and reports it as a framed
 * {@see SubagentResult} with an explicit outcome,
 * rather than letting it tear down the parent's run.
 */
final class RunNotPermitted extends RuntimeException implements HasErrorCode
{
    private string $errorCode = 'run_not_permitted';

    /**
     * Shared with {@see LeaseNotExtendable::budgetExhausted()}, which refuses a
     * lease extension for exactly this reason and carries exactly this code.
     * Two exception TYPES because the callers differ; one code, because the
     * fact does not. See decision 0004.
     */
    #[\Override]
    public function code(): string
    {
        return $this->errorCode;
    }

    /** Which limit stopped the run, for a refusal made by {@see self::exhausted()} or {@see self::workflowBudget()}. */
    private ?string $reason = null;

    public function reason(): ?string
    {
        return $this->reason;
    }

    public static function exhausted(string $reason): self
    {
        $failure = new self("This run may not proceed: {$reason}.");
        $failure->reason = $reason;

        return $failure;
    }

    /**
     * A workflow run's shared budget is spent (G-348). The reason names the
     * limit: steps, tool calls, cost, time, or a cost cap that cannot be
     * enforced. The code is in the message as well, because a workflow
     * engine reports a node's failure as text.
     */
    public static function workflowBudget(string $reason): self
    {
        $failure = new self("This workflow run may not proceed (workflow_budget_exhausted): {$reason}.");
        $failure->errorCode = 'workflow_budget_exhausted';
        $failure->reason = $reason;

        return $failure;
    }

    /**
     * A plan's shared budget is spent (G-366). `$limit` names which:
     * `steps`, `cost` or `cost_unmetered`.
     */
    public static function planBudget(string $limit): self
    {
        $failure = new self("This plan step may not proceed (plan_budget_exhausted): the plan's shared {$limit} budget is spent.");
        $failure->errorCode = 'plan_budget_exhausted';
        $failure->reason = $limit;

        return $failure;
    }

    /**
     * A person cancelled this run, or the run tree it belongs to (G-285).
     * Its own code, `run_cancelled`: a stop someone asked for is not a spent
     * budget, and a caller must never report it as one.
     */
    public static function cancelled(): self
    {
        $failure = new self('This run may not proceed (run_cancelled): it was cancelled.');
        $failure->errorCode = 'run_cancelled';
        $failure->reason = 'run cancelled';

        return $failure;
    }

    public static function callBudget(string $code): self
    {
        $failure = new self(match ($code) {
            'budget_unpriced' => 'The capped provider/model needs finite, non-negative input and output prices.',
            'budget_unbounded_output' => 'The capped call needs a positive maxOutputTokensPerCall.',
            'budget_input_unbounded' => 'The capped call contains input without a configured token bound.',
            'budget_compaction_unmetered' => 'Model-based compaction is not metered; disable it before using a hard cost cap.',
            'budget_observer_uncapped' => 'A call cost observer requires a hard cost cap.',
            'agent_spend_ceiling_exceeded' => "The next model call would exceed this agent's spend ceiling.",
            default => 'The next model call would exceed the remaining cost budget.',
        });
        $failure->errorCode = $code;

        return $failure;
    }

    /**
     * A subagent's child session, linked beneath its parent, has no saved
     * provider and model binding. Running it on the root rules would send the
     * child's conversation to a provider its declaration did not choose, so
     * it is refused instead.
     */
    /**
     * The subagent runner may not start a child again while a turn on it
     * holds its delegation count (G-387): the reset would wipe that turn's
     * hold.
     */
    public static function childBusy(): self
    {
        $failure = new self('This subagent child is already running, so it may not be started again until that turn ends.');
        $failure->errorCode = 'child_busy';
        $failure->reason = 'child_busy';

        return $failure;
    }

    /**
     * The subagent runner may not start a child that is waiting on an
     * approval a person can still answer (G-387): the start would dismiss
     * it. Re-checked under the child's lock, after the start gate read it.
     */
    public static function childWaiting(): self
    {
        $failure = new self('This subagent child is waiting on a person\'s approval, so it may not be started again until that is answered.');
        $failure->errorCode = 'child_waiting';
        $failure->reason = 'child_waiting';

        return $failure;
    }

    public static function unboundChild(): self
    {
        $failure = new self('This subagent child session has no saved provider and model binding, so it may not run outside the subagent runner.');
        $failure->errorCode = 'child_binding_missing';
        $failure->reason = 'child_binding_missing';

        return $failure;
    }

    public static function refusedByHost(Throwable $previous): self
    {
        $failure = new self('The host refused this model call.', 0, $previous);
        $failure->errorCode = $previous instanceof CallRefused ? $previous->code() : 'call_refused_by_host';

        return $failure;
    }
}
