<?php

declare(strict_types=1);

namespace Prism\Harness\Math;

use RuntimeException;

/** Prism's host-policy refusal, kept distinct from the engine's formula errors. */
final class FormulaRequestRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
