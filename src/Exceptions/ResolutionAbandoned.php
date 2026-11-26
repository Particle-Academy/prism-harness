<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use RuntimeException;
use Throwable;

/**
 * What a telemetry consumer is told when a turn ended before the generation
 * that follows a resumed run's approval resolution started (a stream the
 * consumer abandoned, or a generation that failed before it began), so the
 * resolution's events had to be sent in a generation of their own.
 *
 * It carries no content: at most the class of the failure that ended the
 * turn, and a trace without frame arguments (see ContentFree).
 *
 * @internal
 */
final class ResolutionAbandoned extends RuntimeException
{
    public static function before(?Throwable $failure = null): self
    {
        $suffix = $failure === null ? '.' : sprintf(' (%s).', get_debug_type($failure));

        return ContentFree::make(static fn (): self => new self('resolution_abandoned: the turn ended before the generation after the approval resolution started'.$suffix));
    }
}
