<?php

declare(strict_types=1);

namespace Prism\Harness\Math;

use InvalidArgumentException;

/** No error translation, violation sorting or fallback arithmetic. */
final readonly class EvaluationResult
{
    /** @param array<string, mixed> $report */
    public function __construct(public array $report)
    {
        if (! is_bool($report['ok'] ?? null)) {
            throw new InvalidArgumentException('A formula evaluation must report a boolean ok result.');
        }
    }

    public function ok(): bool
    {
        return $this->report['ok'] === true;
    }
}
