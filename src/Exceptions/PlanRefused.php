<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Plans\PlanRefusal;
use RuntimeException;

/**
 * A plan operation was refused (G-360).
 *
 * The message is a constant chosen by the code, never text from the model or
 * from the plan, so it is safe to log and to show. Branch on code().
 */
final class PlanRefused extends RuntimeException implements HasErrorCode
{
    private function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $code): self
    {
        return new self($code, PlanRefusal::message($code));
    }

    public function code(): string
    {
        return $this->errorCode;
    }
}
