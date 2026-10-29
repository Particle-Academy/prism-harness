<?php

declare(strict_types=1);

namespace Prism\Harness\Math;

use Closure;
use InvalidArgumentException;
use LogicException;
use Prism\Harness\Contracts\FormulaEngine;
use RuntimeException;
use Throwable;

/** Optional SDK discovery uses fixed class names and no compile-time symbols. */
final readonly class FancyFormulaEngine implements FormulaEngine
{
    private Closure $check;

    private Closure $evaluate;

    private function __construct(Closure $check, Closure $evaluate, private string $engineVersion)
    {
        $this->check = $check;
        $this->evaluate = $evaluate;
    }

    public static function detect(?FormulaPolicy $policy = null): ?self
    {
        $policy ??= new FormulaPolicy;
        $real = 'FancyFormula\\Engine';
        if (class_exists($real)) {
            // Only the concrete, artifact-verified Options API is autodetected.
            // An unknown Engine never silently falls back to a stub.
            if (! defined($real.'::ENGINE') || constant($real.'::ENGINE') !== 'fancy-formula 0.1.0'
                || ! defined($real.'::SPEC') || constant($real.'::SPEC') !== '1') {
                return null;
            }
            // Validate host ceilings eagerly, without sampling a dynamic clock.
            self::realLimits($policy);
            if (is_array($policy->clock)) {
                self::clock($policy->clock);
            }

            return new self(
                fn (array $request) => (new $real(self::realOptions($policy)))->check($request),
                fn (array $request) => (new $real(self::realOptions($policy)))->evaluate($request),
                constant($real.'::ENGINE'),
            );
        }
        if (! class_exists('FancyFormula\\StubEngine')) {
            return null;
        }
        $class = 'FancyFormula\\StubEngine';
        $version = constant($class.'::ENGINE');
        if (! is_string($version) || ! str_ends_with($version, '-stub')) {
            throw new LogicException('A StubEngine must identify itself as a stub.');
        }

        return self::fromEngine(new $class, $version);
    }

    private static function realLimits(FormulaPolicy $policy): object
    {
        $class = 'FancyFormula\\Limits';

        return $class::defaults()->with(...$policy->limits);
    }

    private static function realOptions(FormulaPolicy $policy): object
    {
        $options = 'FancyFormula\\Options';
        if (! class_exists($options)) {
            throw new LogicException('The verified formula Options class is unavailable.');
        }
        $seed = 'FancyFormula\\Seed';
        $functions = 'FancyFormula\\FunctionPolicy';
        $clock = $policy->clock;
        if ($clock instanceof Closure) {
            try {
                $clock = $clock();
            } catch (Throwable $failure) {
                // A host callback throwing FormulaRequestRefused must not enter
                // the service's normal model-request refusal channel.
                throw new RuntimeException('The host formula clock failed.', previous: $failure);
            }
            if (! is_array($clock)) {
                throw new InvalidArgumentException('The host formula clock must return localDateTime and zone strings.');
            }
        }

        return new $options(
            limits: self::realLimits($policy),
            clock: $clock === null ? null : self::clock($clock),
            random: $policy->seed === null ? null : $seed::fromString($policy->seed),
            providers: [],
            functions: $policy->functionPolicy === null ? $functions::all() : $functions::only($policy->functionPolicy),
        );
    }

    private static function clock(mixed $clock): object
    {
        if (! is_array($clock) || count($clock) !== 2 || array_diff(array_keys($clock), ['localDateTime', 'zone']) !== []
            || ! is_string($clock['localDateTime'] ?? null) || ! is_string($clock['zone'] ?? null)) {
            throw new InvalidArgumentException('The host formula clock requires exactly localDateTime and zone strings.');
        }
        $class = 'FancyFormula\\FixedClock';
        if (! class_exists($class)) {
            throw new LogicException('The verified formula FixedClock class is unavailable.');
        }

        return new $class($clock['localDateTime'], $clock['zone']);
    }

    public static function unavailableDetail(): ?string
    {
        return class_exists('FancyFormula\\Engine') ? 'engine_factory_required' : null;
    }

    /** Wrap an SDK constructed by the HOST with its own Options and providers. */
    public static function fromEngine(object $engine, string $version): self
    {
        if (! is_callable([$engine, 'check']) || ! is_callable([$engine, 'evaluate'])) {
            throw new LogicException('The formula engine does not expose the frozen check/evaluate contract.');
        }

        return new self(Closure::fromCallable([$engine, 'check']), Closure::fromCallable([$engine, 'evaluate']), $version);
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
