<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use InvalidArgumentException;
use Prism\Harness\Contracts\HasErrorCode;

/**
 * Thrown when a lease is not a length of time this package can hold a task for.
 *
 * ONE RULE, TWO SCALES, AND BOTH ARE THE SAME RULE: a configuration that
 * silently becomes a DIFFERENT configuration is one nobody gets to notice.
 *
 *  - Zero or negative is refused rather than clamped to one second. An
 *    operator who wrote `lease_seconds => 0` has said something they did not
 *    mean, and substituting a working value takes away the only chance anyone
 *    had to find out.
 *  - A fractional lease is refused rather than truncated. `90.4` becoming `90`
 *    is the same shape one scale down — and it is not only tidiness: the stored
 *    `claimed_until` is an INTEGER Unix timestamp by decision, so a fractional
 *    lease could never have been honoured as written. Refusing says so;
 *    truncating stores a different lease than the one that was asked for and
 *    tells nobody.
 *
 * Truncation being in the SAFE direction — a shorter lease, so more reclaims
 * rather than lost work — is the argument for clamping, restated. It was not
 * enough for zero and it is not enough here: quiet is the problem, not the
 * direction.
 *
 * Raised when the source is CONSTRUCTED, before any task is claimed, so nothing
 * is half-done when it fires.
 */
final class InvalidTaskLease extends InvalidArgumentException implements HasErrorCode
{
    public static function notPositive(int $seconds): self
    {
        return new self(
            "A task lease of {$seconds} second(s) cannot hold anything: it expires at or before the moment "
            .'it is granted, so every task would be claimable by every worker at once and a claim would '
            ."guarantee nothing.\n\n"
            .'Set `prism-harness.tasks.lease_seconds` to a positive number of seconds — long enough for a '
            .'model call plus its tool work. It is not clamped to a usable value on your behalf, because a '
            .'configuration that silently becomes a different configuration is one nobody gets to notice.'
        );
    }

    public static function notWholeSeconds(string $setting, string $value): self
    {
        return new self(
            "The task lease [{$setting}] is set to [{$value}], which is not a whole number of seconds. It is "
            .'refused rather than rounded, for the reason a lease of zero is refused rather than raised to '
            ."one: the lease you would get is not the lease you wrote, and nothing would say so.\n\n"
            .'A lease expiry is stored as an integer Unix timestamp — that is pinned across all three '
            .'languages — so a fractional lease could not have been honoured as written in any case.'
        );
    }

    #[\Override]
    public function code(): string
    {
        return 'task_lease_invalid';
    }
}
