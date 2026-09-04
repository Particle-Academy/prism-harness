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
    /**
     * Shared with {@see LeaseNotExtendable::budgetExhausted()}, which refuses a
     * lease extension for exactly this reason and carries exactly this code.
     * Two exception TYPES because the callers differ; one code, because the
     * fact does not. See decision 0004.
     */
    #[\Override]
    public function code(): string
    {
        return 'run_not_permitted';
    }

    public static function exhausted(string $reason): self
    {
        return new self("This run may not proceed: {$reason}.");
    }
}
