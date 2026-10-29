<?php

declare(strict_types=1);

namespace Prism\Harness\Math;

use InvalidArgumentException;

/** Preserve the engine's complete report, including native diagnostic order. */
final readonly class CheckResult
{
    /** @param array<string, mixed> $report */
    public function __construct(public array $report)
    {
        if (! is_bool($report['ok'] ?? null)) {
            throw new InvalidArgumentException('A formula check must report a boolean ok result.');
        }
    }

    public function ok(): bool
    {
        return $this->report['ok'] === true;
    }
}
