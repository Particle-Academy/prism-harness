<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Closure;
use Throwable;

/**
 * Builds an exception meant for telemetry without frame arguments in its
 * trace.
 *
 * PHP records each frame's arguments in an exception's trace unless
 * zend.exception_ignore_args is on (php.ini-development leaves it off). The
 * frames above a resolution hold the turn's messages and the failure being
 * described, so an exception built there would carry tool arguments and
 * results to any consumer that reads getTrace(). The setting is switched on
 * for the construction only and restored at once.
 *
 * @internal
 */
final class ContentFree
{
    /**
     * @template TException of Throwable
     *
     * @param  Closure(): TException  $make
     * @return TException
     */
    public static function make(Closure $make): Throwable
    {
        $previous = ini_set('zend.exception_ignore_args', '1');
        try {
            return $make();
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }
}
