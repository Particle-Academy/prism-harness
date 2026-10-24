<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

final readonly class CallReservation
{
    /** @param 'text'|'structured'|'stream' $kind */
    public function __construct(
        public string $callId,
        public string $provider,
        public string $model,
        public float $worstCaseUsd,
        public string $kind,
    ) {}
}
