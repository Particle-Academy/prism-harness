<?php

declare(strict_types=1);

namespace Prism\Harness\Math;

use Closure;
use LogicException;
use Prism\Harness\Contracts\FormulaEngine;

/** Optional SDK discovery uses fixed class names and no compile-time symbols. */
final readonly class FancyFormulaEngine implements FormulaEngine
{
    private Closure $check;

    private Closure $evaluate;

    private function __construct(object $engine, private string $engineVersion)
    {
        if (! is_callable([$engine, 'check']) || ! is_callable([$engine, 'evaluate'])) {
            throw new LogicException('The formula engine does not expose the frozen check/evaluate contract.');
        }
        $this->check = Closure::fromCallable([$engine, 'check']);
        $this->evaluate = Closure::fromCallable([$engine, 'evaluate']);
    }

    public static function detect(): ?self
    {
        // A real Engine takes precedence, but its constructor contract is not
        // guessed. Hosts bind a FormulaEngine factory until that contract ships.
        if (class_exists('FancyFormula\\Engine') || ! class_exists('FancyFormula\\StubEngine')) {
            return null;
        }
        $class = 'FancyFormula\\StubEngine';
        $version = constant($class.'::ENGINE');
        if (! is_string($version) || ! str_ends_with($version, '-stub')) {
            throw new LogicException('A StubEngine must identify itself as a stub.');
        }

        return new self(new $class, $version);
    }

    public static function unavailableDetail(): ?string
    {
        return class_exists('FancyFormula\\Engine') ? 'engine_factory_required' : null;
    }

    /** Wrap an SDK constructed by the HOST with its own Options and providers. */
    public static function fromEngine(object $engine, string $version): self
    {
        return new self($engine, $version);
    }

    public function version(): string
    {
        return $this->engineVersion;
    }

    public function check(array $document, FormulaPolicy $policy): CheckResult
    {
        return new CheckResult($this->invoke($this->check, $this->request($document, [], $policy)));
    }

    public function evaluate(array $document, array $inputs, FormulaPolicy $policy): EvaluationResult
    {
        return new EvaluationResult($this->invoke($this->evaluate, $this->request($document, $inputs, $policy)));
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function invoke(Closure $operation, array $request): array
    {
        $report = $operation($request);
        if (is_object($report) && is_callable([$report, 'toArray'])) {
            $report = $report->toArray();
        }
        if (! is_array($report)) {
            throw new LogicException('The formula engine returned a non-object report.');
        }
        if (isset($report['engine']) && $report['engine'] !== $this->engineVersion) {
            throw new LogicException('The formula engine changed its version label.');
        }

        return $report;
    }

    /**
     * Host declarations survive unchanged; model inputs supply values only.
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    private function request(array $document, array $inputs, FormulaPolicy $policy): array
    {
        $request = $document;
        $declarations = $document['inputs'] ?? [];
        foreach ($inputs as $name => $value) {
            if (! is_array($declarations) || ! isset($declarations[$name]) || ! is_array($declarations[$name])) {
                throw new FormulaRequestRefused('DO_MATH_INPUT_NOT_ALLOWED', 'Only declared formula inputs may be supplied.');
            }
            $declaration = $declarations[$name];
            $field = match ($declaration['type'] ?? null) {
                'range' => 'rows',
                'table' => 'table',
                default => 'value',
            };
            $declaration[$field] = $value;
            $request['inputs'][$name] = $declaration;
        }
        $request['limits'] = $policy->limits;
        $request['trace'] = $policy->trace;

        return $request;
    }
}
