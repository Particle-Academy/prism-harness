<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Prism\ValueObjects\Usage;

final readonly class CallSettlement
{
    /** @param 'completed'|'failed'|'abandoned' $outcome */
    public function __construct(
        public string $callId,
        public float $chargedUsd,
        public bool $usageKnown,
        public ?Usage $usage,
        public string $outcome,
    ) {}
}
