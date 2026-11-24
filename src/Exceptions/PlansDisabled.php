<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use LogicException;
use Prism\Harness\Contracts\HasErrorCode;

/**
 * Plans are off for this session (G-360). They are off unless the host turns
 * them on in config AND the session's mode turns them on.
 */
final class PlansDisabled extends LogicException implements HasErrorCode
{
    public static function forSession(): self
    {
        return new self('Plans are not enabled for this session. Turn on prism-harness.plans.enabled and the mode\'s plans flag.');
    }

    public function code(): string
    {
        return 'plan.disabled';
    }
}
