<?php

declare(strict_types=1);

namespace Prism\Harness\Math;

use Closure;
use InvalidArgumentException;
use Prism\Harness\Contracts\FormulaEngine;
use Prism\Harness\Sessions\SessionAddress;

/** Host-owned document registries, isolated per agent and fixed for a worker. */
final class DoMathManager
{
    public const ENGINE_FACTORY = 'prism-harness.formula-engine-factory';

    /** @var array<string, DoMathService> */
    private array $services = [];

    /**
     * @param  array<string, mixed>  $config
     * @param  Closure(FormulaPolicy): FormulaEngine|null  $engineFactory
     */
    public function __construct(private readonly array $config, private readonly ?Closure $engineFactory = null) {}

    /** @return list<string|null> */
    public function configuredAgents(): array
    {
        $configured = $this->options(null) === null ? [] : [null];
        foreach (array_keys($this->config['agents'] ?? []) as $agent) {
            SessionAddress::validateAgent($agent);
            if ($this->options($agent) !== null) {
                $configured[] = $agent;
            }
        }

        return $configured;
    }

    public function forAgent(?string $agent = null): DoMathService
    {
        SessionAddress::validateAgent($agent);
        $key = $agent ?? '';
        if (isset($this->services[$key])) {
            return $this->services[$key];
        }
        $options = $this->options($agent);
        if ($options === null) {
            throw new InvalidArgumentException('Configure tools.do_math explicitly in this agent namespace first.');
        }
        $known = ['documents', 'allow_ad_hoc', 'function_policy', 'limits', 'clock', 'seed', 'max_output_bytes', 'max_trace_bytes', 'trace', 'engine_factory'];
        if (array_diff(array_keys($options), $known) !== []) {
            throw new InvalidArgumentException('Unknown doMath host policy option.');
        }
        $policy = new FormulaPolicy(
            documents: $options['documents'] ?? [],
            allowAdHoc: $options['allow_ad_hoc'] ?? false,
            functionPolicy: $options['function_policy'] ?? null,
            limits: $options['limits'] ?? [],
            clock: $options['clock'] ?? null,
            seed: $options['seed'] ?? null,
            maxOutputBytes: $options['max_output_bytes'] ?? 65536,
            maxTraceBytes: $options['max_trace_bytes'] ?? min(32768, $options['max_output_bytes'] ?? 65536),
            trace: $options['trace'] ?? 'lookups',
        );
        $factory = $options['engine_factory'] ?? $this->engineFactory;
        if ($factory !== null && ! $factory instanceof Closure) {
            throw new InvalidArgumentException('The host engine factory must be a Closure(FormulaPolicy): FormulaEngine.');
        }
        $engine = $factory === null ? FancyFormulaEngine::detect() : $factory($policy);
        if ($engine !== null && ! $engine instanceof FormulaEngine) {
            throw new InvalidArgumentException('The host engine factory must return a FormulaEngine.');
        }
        if ($factory !== null && $engine === null) {
            throw new InvalidArgumentException('A configured engine factory cannot silently return no engine.');
        }

        return $this->services[$key] = new DoMathService($policy, $engine,
            $engine === null ? FancyFormulaEngine::unavailableDetail() : null);
    }

    /** @return array<string, mixed>|null */
    private function options(?string $agent): ?array
    {
        $scope = $agent === null ? $this->config : ($this->config['agents'][$agent] ?? []);
        $options = $scope['tools']['do_math'] ?? null;
        if ($options === null || $options === false) {
            return null;
        }
        if (! is_array($options)) {
            throw new InvalidArgumentException('Configure doMath with a host policy array or leave it disabled.');
        }

        return $options;
    }
}
