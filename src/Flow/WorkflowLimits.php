<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use InvalidArgumentException;

/**
 * What one workflow run may spend (G-348).
 *
 * The operator's config is the ceiling. A host may lower it for one run and a
 * graph may lower it in its `harness_budget` metadata; neither can raise it,
 * because a graph is authored content, including by agents through the flow
 * bridge. Null means no limit of that kind.
 */
final readonly class WorkflowLimits
{
    public const KEYS = ['max_steps', 'max_tool_calls', 'max_cost_usd', 'max_seconds'];

    public function __construct(
        public ?int $maxSteps,
        public ?int $maxToolCalls,
        public ?float $maxCostUsd,
        public ?int $maxSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws InvalidArgumentException for a negative, non-finite or non-integer limit
     */
    public static function fromArray(array $values, string $source): self
    {
        foreach (array_keys($values) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown workflow budget key [%s] in %s.', $key, $source));
            }
        }

        return new self(
            self::count($values['max_steps'] ?? null, 'max_steps', $source),
            self::count($values['max_tool_calls'] ?? null, 'max_tool_calls', $source),
            self::money($values['max_cost_usd'] ?? null, $source),
            self::count($values['max_seconds'] ?? null, 'max_seconds', $source),
        );
    }

    /**
     * These limits, lowered by another set. Never raised.
     *
     * @param  list<string>  $ignored  receives each key the other set tried to raise
     */
    public function narrowedBy(self $other, array &$ignored = []): self
    {
        $pick = function (int|float|null $mine, int|float|null $theirs, string $key) use (&$ignored): int|float|null {
            if ($theirs === null) {
                return $mine;
            }
            if ($mine !== null && $theirs > $mine) {
                $ignored[] = $key;

                return $mine;
            }

            return $theirs;
        };

        $steps = $pick($this->maxSteps, $other->maxSteps, 'max_steps');
        $tools = $pick($this->maxToolCalls, $other->maxToolCalls, 'max_tool_calls');
        $cost = $pick($this->maxCostUsd, $other->maxCostUsd, 'max_cost_usd');
        $seconds = $pick($this->maxSeconds, $other->maxSeconds, 'max_seconds');

        return new self(
            $steps === null ? null : (int) $steps,
            $tools === null ? null : (int) $tools,
            $cost === null ? null : (float) $cost,
            $seconds === null ? null : (int) $seconds,
        );
    }

    /** @return array{max_steps: ?int, max_tool_calls: ?int, max_cost_usd: ?float, max_seconds: ?int} */
    public function toArray(): array
    {
        return [
            'max_steps' => $this->maxSteps,
            'max_tool_calls' => $this->maxToolCalls,
            'max_cost_usd' => $this->maxCostUsd,
            'max_seconds' => $this->maxSeconds,
        ];
    }

    private static function count(mixed $value, string $key, string $source): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException(sprintf('Workflow budget [%s] in %s must be a whole number, zero or more, or null.', $key, $source));
        }

        return $value;
    }

    private static function money(mixed $value, string $source): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0) {
            throw new InvalidArgumentException(sprintf('Workflow budget [max_cost_usd] in %s must be a finite number of USD, zero or more, or null.', $source));
        }

        return (float) $value;
    }
}
