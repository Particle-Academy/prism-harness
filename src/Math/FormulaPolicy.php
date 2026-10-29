<?php

declare(strict_types=1);

namespace Prism\Harness\Math;

use Closure;
use InvalidArgumentException;

/** Host state. Model input may reduce a cap and can never select authority. */
final readonly class FormulaPolicy
{
    /** Engine request keys from the frozen v1 request schema, not formula rules. */
    public const ENGINE_LIMITS = [
        'maxFormulaBytes' => 8192,
        'maxDepth' => 64,
        'maxFormulas' => 500,
        'maxRangeCells' => 100000,
        'maxInputCells' => 1000000,
        'maxArrayCells' => 100000,
        'maxLambdaDepth' => 64,
        'maxSteps' => 1000000,
        'maxTextLength' => 50000,
        'wallClockMs' => 1000,
        'maxTraceSteps' => 10000,
    ];

    /** @var list<string> */
    public array $documents;

    /** @var array<string, int> */
    public array $limits;

    /** @var list<string>|null */
    public ?array $functionPolicy;

    /**
     * @param  array<array-key, mixed>  $documents  Names the agent may request.
     * @param  array<array-key, mixed>|null  $functionPolicy  Null retains the engine's safe defaults.
     * @param  array<array-key, mixed>  $limits
     * @param  array<string, mixed>|Closure|null  $clock  Host-owned engine clock.
     */
    public function __construct(
        array $documents = [],
        public bool $allowAdHoc = false,
        ?array $functionPolicy = null,
        array $limits = [],
        public array|Closure|null $clock = null,
        public ?string $seed = null,
        public int $maxOutputBytes = 65536,
        public int $maxTraceBytes = 32768,
        public string $trace = 'lookups',
    ) {
        if (! array_is_list($documents)) {
            throw new InvalidArgumentException('Formula document names must be a unique list.');
        }
        $names = [];
        foreach ($documents as $name) {
            if (! is_string($name) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_.-]{0,127}\z/D', $name) !== 1) {
                throw new InvalidArgumentException('A formula document needs a stable host-selected name.');
            }
            $names[] = $name;
        }
        if (count($documents) !== count(array_unique($documents, SORT_STRING))) {
            throw new InvalidArgumentException('Formula document names must be a unique list.');
        }
        $functions = $functionPolicy === null ? null : [];
        if ($functionPolicy !== null) {
            if (! array_is_list($functionPolicy)) {
                throw new InvalidArgumentException('Formula function policy must be a list.');
            }
            foreach ($functionPolicy as $function) {
                if (! is_string($function) || preg_match('/\A[A-Z][A-Z0-9_.]{0,127}\z/D', $function) !== 1) {
                    throw new InvalidArgumentException('Formula function policy uses explicit engine function names.');
                }
                $functions[] = $function;
            }
        }
        $engineLimits = self::ENGINE_LIMITS;
        foreach ($limits as $name => $value) {
            if (! is_string($name) || ! array_key_exists($name, self::ENGINE_LIMITS) || ! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('Formula limits must use engine request keys and positive integers.');
            }
            $engineLimits[$name] = $value;
        }
        if ($maxOutputBytes < 512 || $maxTraceBytes < 1 || $maxTraceBytes > $maxOutputBytes) {
            throw new InvalidArgumentException('Formula output bounds must allow an error envelope and bounded traces.');
        }
        if (! in_array($trace, ['none', 'lookups', 'full'], true)) {
            throw new InvalidArgumentException('Formula trace policy must be none, lookups or full.');
        }
        $this->documents = $names;
        $this->functionPolicy = $functions;
        $this->limits = $engineLimits;
    }

    /** @param array<string, mixed> $limits Model-provided caps only. */
    public function narrowed(array $limits): self
    {
        $effective = $this->limits;
        $output = $this->maxOutputBytes;
        $trace = $this->maxTraceBytes;
        foreach ($limits as $name => $value) {
            $ceiling = match ($name) {
                'maxOutputBytes' => $output,
                'maxTraceBytes' => $trace,
                default => $effective[$name] ?? null,
            };
            if ($ceiling === null || ! is_int($value) || $value < 1 || $value > $ceiling) {
                throw new FormulaRequestRefused('DO_MATH_POLICY_WIDENING', 'A formula request may only tighten the host limits.');
            }
            match ($name) {
                'maxOutputBytes' => $output = $value,
                'maxTraceBytes' => $trace = $value,
                default => $effective[$name] = $value,
            };
        }
        if ($output < 512) {
            throw new FormulaRequestRefused('DO_MATH_REQUEST_INVALID', 'The output cap must fit a bounded error envelope.');
        }

        return new self($this->documents, $this->allowAdHoc, $this->functionPolicy, $effective,
            $this->clock, $this->seed, $output, min($trace, $output), $this->trace);
    }

    /** @return array<string, mixed> For a host-created engine, never model input. */
    public function engineOptions(): array
    {
        return [
            'function_policy' => $this->functionPolicy,
            'limits' => $this->limits,
            'clock' => $this->clock,
            'seed' => $this->seed,
        ];
    }

    public function withTrace(string $trace): self
    {
        return new self($this->documents, $this->allowAdHoc, $this->functionPolicy, $this->limits,
            $this->clock, $this->seed, $this->maxOutputBytes, $this->maxTraceBytes, $trace);
    }
}
