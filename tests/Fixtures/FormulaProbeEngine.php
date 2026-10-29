<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Prism\Harness\Contracts\FormulaEngine;
use Prism\Harness\Math\CheckResult;
use Prism\Harness\Math\EvaluationResult;
use Prism\Harness\Math\FormulaPolicy;

/** Spy for Prism's OWN port, never a declaration or imitation of the SDK. */
final class FormulaProbeEngine implements FormulaEngine
{
    /** @var list<array{document: array<string, mixed>, policy: FormulaPolicy}> */
    public array $checks = [];

    /** @var list<array{document: array<string, mixed>, inputs: array<string, mixed>, policy: FormulaPolicy}> */
    public array $evaluations = [];

    /** @var array<string, mixed> */
    public array $checkReport = ['ok' => true, 'diagnostics' => []];

    /** @var array<string, mixed> */
    public array $report = [
        'ok' => true, 'fingerprint' => 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'results' => [], 'traces' => [], 'warnings' => [],
    ];

    public function version(): string
    {
        return 'prism-port-probe';
    }

    public function check(array $document, FormulaPolicy $policy): CheckResult
    {
        $this->checks[] = compact('document', 'policy');

        return new CheckResult($this->checkReport);
    }

    public function evaluate(array $document, array $inputs, FormulaPolicy $policy): EvaluationResult
    {
        $this->evaluations[] = compact('document', 'inputs', 'policy');

        return new EvaluationResult($this->report);
    }
}
