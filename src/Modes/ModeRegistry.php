<?php

declare(strict_types=1);

namespace Prism\Harness\Modes;

use InvalidArgumentException;

final readonly class ModeRegistry
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config) {}

    public function default(): string
    {
        $default = $this->config['default'] ?? 'chat';

        return is_string($default) && $default !== '' ? $default : 'chat';
    }

    public function resolve(?string $name): AgentMode
    {
        $name ??= $this->default();
        $modes = $this->config['modes'] ?? [];
        $mode = is_array($modes) ? ($modes[$name] ?? null) : null;
        if (! is_array($mode)) {
            throw new InvalidArgumentException(sprintf('Harness mode [%s] is not configured.', $name));
        }

        $prompt = $mode['system_prompt'] ?? '';
        $tools = $mode['tools'] ?? [];
        $skills = $mode['skills'] ?? [];
        $maxSteps = $mode['max_steps'] ?? 8;
        if (! is_string($prompt) || ! is_array($tools) || ! is_array($skills) || ! is_int($maxSteps) || $maxSteps < 1) {
            throw new InvalidArgumentException(sprintf('Harness mode [%s] is malformed.', $name));
        }

        return new AgentMode(
            $name,
            $prompt,
            array_values(array_filter($tools, is_string(...))),
            array_values(array_filter($skills, is_string(...))),
            $maxSteps,
        );
    }
}
