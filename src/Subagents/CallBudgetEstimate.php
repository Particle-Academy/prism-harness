<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

/** A conservative pre-admission bound, not a tokenizer estimate or reservation. */
final readonly class CallBudgetEstimate
{
    /** @param list<CallBudgetDiagnostic> $diagnostics */
    public function __construct(
        public int $inputTokenBound,
        public float $worstCaseUsd,
        public array $diagnostics = [],
    ) {}
}
