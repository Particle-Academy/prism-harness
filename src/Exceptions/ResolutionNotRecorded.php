<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use RuntimeException;
use Throwable;

/**
 * What a telemetry consumer is told when a resumed run's approved calls ran
 * but their results could not be recorded.
 *
 * It names only the class of the failure. The failure itself never goes to
 * telemetry: a database exception's message carries the statement with its
 * bindings (the tool's arguments and result), and a telemetry consumer may
 * export a failure's message regardless of the content-capture setting. Its
 * own trace carries no frame arguments either (see ContentFree): the frames
 * above it hold the failure and the turn's messages. The caller still
 * receives the original failure.
 *
 * @internal
 */
final class ResolutionNotRecorded extends RuntimeException
{
    public static function because(Throwable $failure): self
    {
        $class = get_debug_type($failure);

        return ContentFree::make(static fn (): self => new self(sprintf('resolution_not_recorded: the approved calls ran but their results were not recorded (%s).', $class)));
    }
}
