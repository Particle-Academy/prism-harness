<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

/**
 * The bounds a plan runs inside (G-360). Read from `prism-harness.plans`.
 *
 * A value outside its range is REFUSED at construction, never clamped: an
 * operator who asked for 500 steps should learn that 40 is the most an update
 * can carry, not find out from a plan that was silently cut.
 */
final readonly class PlanLimits
{
    /** Fancy contract g282.3: a plan carries at most 40 steps. */
    public const STEPS_CEILING = 40;

    /** Fancy contract g282.3: the first update is under 8 KB. */
    public const EVENT_BYTES_CEILING = 8192;

    public function __construct(
        public int $maxSteps = 20,
        public int $maxAttempts = 3,
        public int $maxPausesPerStep = 1,
        public int $maxChanges = 5,
        public int $maxAdvances = 60,
        public int $contextMaxChars = 2000,
        public int $contextWindow = 8,
        public int $eventMaxBytes = self::EVENT_BYTES_CEILING,
        public bool $requireApproval = true,
        public bool $runSteps = false,
        public int $staleAfter = 300,
    ) {
        $ranges = [
            'max_steps' => [$maxSteps, 1, self::STEPS_CEILING],
            'max_attempts' => [$maxAttempts, 1, 20],
            'max_pauses_per_step' => [$maxPausesPerStep, 0, 10],
            'max_changes' => [$maxChanges, 0, 50],
            'max_advances' => [$maxAdvances, 1, 10000],
            'context.max_chars' => [$contextMaxChars, 500, 20000],
            'context.window' => [$contextWindow, 0, self::STEPS_CEILING],
            'events.max_bytes' => [$eventMaxBytes, 1024, self::EVENT_BYTES_CEILING],
            'reaper.stale_after' => [$staleAfter, 1, 86400],
        ];
        foreach ($ranges as $name => [$value, $min, $max]) {
            if ($value < $min || $value > $max) {
                throw new \InvalidArgumentException("prism-harness.plans.{$name} must be between {$min} and {$max}.");
            }
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        $int = static function (mixed $value, int $default, string $name): int {
            if ($value === null) {
                return $default;
            }
            if (! is_int($value)) {
                throw new \InvalidArgumentException("prism-harness.plans.{$name} must be a whole number.");
            }

            return $value;
        };
        $bool = static function (mixed $value, bool $default, string $name): bool {
            if ($value === null) {
                return $default;
            }
            if (! is_bool($value)) {
                throw new \InvalidArgumentException("prism-harness.plans.{$name} must be true or false.");
            }

            return $value;
        };
        $context = is_array($config['context'] ?? null) ? $config['context'] : [];
        $events = is_array($config['events'] ?? null) ? $config['events'] : [];
        $reaper = is_array($config['reaper'] ?? null) ? $config['reaper'] : [];

        return new self(
            maxSteps: $int($config['max_steps'] ?? null, 20, 'max_steps'),
            maxAttempts: $int($config['max_attempts'] ?? null, 3, 'max_attempts'),
            maxPausesPerStep: $int($config['max_pauses_per_step'] ?? null, 1, 'max_pauses_per_step'),
            maxChanges: $int($config['max_changes'] ?? null, 5, 'max_changes'),
            maxAdvances: $int($config['max_advances'] ?? null, 60, 'max_advances'),
            contextMaxChars: $int($context['max_chars'] ?? null, 2000, 'context.max_chars'),
            contextWindow: $int($context['window'] ?? null, 8, 'context.window'),
            eventMaxBytes: $int($events['max_bytes'] ?? null, self::EVENT_BYTES_CEILING, 'events.max_bytes'),
            requireApproval: $bool($config['require_approval'] ?? null, true, 'require_approval'),
            runSteps: $bool($events['run_steps'] ?? null, false, 'events.run_steps'),
            staleAfter: $int($reaper['stale_after'] ?? null, 300, 'reaper.stale_after'),
        );
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'max_steps' => $this->maxSteps,
            'max_attempts' => $this->maxAttempts,
            'max_pauses_per_step' => $this->maxPausesPerStep,
            'max_changes' => $this->maxChanges,
            'max_advances' => $this->maxAdvances,
            'context_max_chars' => $this->contextMaxChars,
            'context_window' => $this->contextWindow,
            'event_max_bytes' => $this->eventMaxBytes,
            'require_approval' => $this->requireApproval,
            'run_steps' => $this->runSteps,
            'stale_after' => $this->staleAfter,
        ];
    }
}
