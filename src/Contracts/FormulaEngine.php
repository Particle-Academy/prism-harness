<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Math\CheckResult;
use Prism\Harness\Math\EvaluationResult;
use Prism\Harness\Math\FormulaPolicy;

/** The optional engine owns formula semantics, constraints and stable errors. */
interface FormulaEngine
{
    public function version(): string;

    /** @param array<string, mixed> $document */
    public function check(array $document, FormulaPolicy $policy): CheckResult;

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $inputs  Raw values, never formula source.
     */
    public function evaluate(array $document, array $inputs, FormulaPolicy $policy): EvaluationResult;
}
