<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Subagents\SubagentResult;
use RuntimeException;

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

    public static function exhausted(string $reason): self
    {
        return new self("This run may not proceed: {$reason}.");
    }

    public static function callBudget(string $code): self
    {
        $failure = new self(match ($code) {
            'budget_unpriced' => 'The capped provider/model needs finite, non-negative input and output prices.',
            'budget_unbounded_output' => 'The capped call needs a positive maxOutputTokensPerCall.',
            'budget_input_unbounded' => 'The capped call contains input without a configured token bound.',
            'budget_compaction_unmetered' => 'Model-based compaction is not metered; disable it before using a hard cost cap.',
            default => 'The next model call would exceed the remaining cost budget.',
        });
        $failure->errorCode = $code;

        return $failure;
    }
}
