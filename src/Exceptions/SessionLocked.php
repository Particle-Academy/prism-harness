<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use RuntimeException;

/**
 * Thrown when another worker holds the session and did not release it in time.
 *
 * Deliberately not a silent pass-through. The lock exists so that two requests
 * resolving the same session cannot both advance a run; running the callback
 * anyway on timeout would defeat the only thing it is for.
 */
final class SessionLocked extends RuntimeException
{
    public static function forKey(string $key, int $waitSeconds): self
    {
        return new self(
            "Timed out after {$waitSeconds}s waiting for the harness session lock [{$key}]. "
            .'Another worker is advancing this session.'
        );
    }
}
