<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use InvalidArgumentException;
use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Tools\TaskCompletionTool;

/**
 * Thrown when something outside this package offered an outcome that is not
 * exactly `done` or `failed`.
 *
 * THIS EXISTS BECAUSE THE OBVIOUS SHORTCUT IS A PRIVILEGE ESCALATION.
 * `$value === 'failed' ? Failed : Done` reads as harmless defaulting and is
 * not: it resolves every malformed input — a missing argument, `'complete'`,
 * `'DONE'`, `null` — to `done`, the outcome that ENDS the task and lets a run
 * report success. A sibling port shipped exactly that line.
 *
 * The direction is what makes it serious. Defaulting to `failed` would be
 * annoying and visible; defaulting to `done` lets an agent close its own work
 * by typing the wrong word, without ever passing the authorization that closing
 * work is supposed to require.
 *
 * The value is REPORTED BACK in the message because this is a developer-facing
 * exception. Anything that hands the refusal to a model instead must not echo
 * it — see {@see TaskCompletionTool}.
 */
final class InvalidTaskOutcome extends InvalidArgumentException implements HasErrorCode
{
    public static function notAnOutcome(string $value): self
    {
        return new self(
            "[{$value}] is not a task outcome. There are exactly two — done and failed — and neither is a "
            .'default for the other: an unrecognised value is refused rather than resolved, because the '
            .'resolution would be to done, and done is the one that lets a run report success.'
        );
    }

    #[\Override]
    public function code(): string
    {
        return 'task_outcome_invalid';
    }
}
