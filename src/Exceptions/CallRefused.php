<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use InvalidArgumentException;
use Prism\Harness\Contracts\HasErrorCode;
use RuntimeException;
use Throwable;

/** A trusted cost observer's machine-readable refusal, without host message content. */
final class CallRefused extends RuntimeException implements HasErrorCode
{
    public function __construct(private readonly string $errorCode, ?Throwable $previous = null)
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $errorCode) !== 1) {
            throw new InvalidArgumentException('A call refusal needs a lowercase code of at most 64 characters.');
        }
        parent::__construct('The host refused this model call.', 0, $previous);
    }

    public function code(): string
    {
        return $this->errorCode;
    }
}
