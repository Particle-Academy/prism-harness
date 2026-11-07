<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use RuntimeException;

final class SessionLockLost extends RuntimeException
{
    private bool $writeRejected = false;

    public static function forKey(string $key): self
    {
        return new self("session_lock_lost: the harness run no longer owns the session lock [{$key}].");
    }

    /** @internal A rejected atomic write must not enter ordinary failure cleanup. */
    public static function afterRejectedWrite(string $key): self
    {
        $failure = self::forKey($key);
        $failure->writeRejected = true;

        return $failure;
    }

    public function isWriteRejected(): bool
    {
        return $this->writeRejected;
    }
}
