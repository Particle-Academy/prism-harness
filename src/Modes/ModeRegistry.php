<?php

declare(strict_types=1);

namespace Prism\Harness\Modes;

use InvalidArgumentException;
use Prism\Harness\Console\HarnessDoctorCommand;
use Prism\Harness\Subagents\Subagent;

final readonly class ModeRegistry
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config) {}

    public function default(): string
    {
        $default = $this->config['default'] ?? 'chat';

        return is_string($default) && $default !== '' ? $default : 'chat';
    }

    /**
     * Every mode name this application has configured.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $modes = $this->config['modes'] ?? [];

        return is_array($modes) ? array_values(array_filter(array_keys($modes), is_string(...))) : [];
    }

    /**
     * Every mode, resolved.
     *
     * Resolving them ALL is the point: `resolve()` validates as it goes, so a
     * mode nobody has entered yet keeps its misconfiguration until the day
     * somebody switches to it. This is what {@see HarnessDoctorCommand}
     * uses to find that on a Tuesday rather than in front of a user.
     *
     * @return array<string, AgentMode>
     */
    public function all(): array
    {
        $modes = [];

        foreach ($this->names() as $name) {
            $modes[$name] = $this->resolve($name);
        }

        return $modes;
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
            $this->subagentsFor($name, $mode),
            $this->requiresApprovalFor($name, $mode),
        );
    }

    /**
     * @param  array<string, mixed>  $mode
     * @return list<string>
     */
    private function requiresApprovalFor(string $name, array $mode): array
    {
        $declared = $mode['requires_approval'] ?? [];

        if (! is_array($declared)) {
            throw new InvalidArgumentException(sprintf('Harness mode [%s] declares a malformed requires_approval list.', $name));
        }

        return array_values(array_filter($declared, is_string(...)));
    }

    /**
     * The nested agents a mode may call.
     *
     * DECLARED PER MODE, which is the point: a subagent is authority, and
     * authority a run inherits by being nested is authority nobody granted. A
     * mode that names no subagents cannot spawn one.
     *
     * A subagent's own `mode` must exist, and it is resolved here rather than
     * at call time so a typo surfaces when the parent mode is loaded instead of
     * halfway through a run that has already spent budget.
     *
     * @param  array<string, mixed>  $mode
     * @return array<string, Subagent>
     */
    private function subagentsFor(string $name, array $mode): array
    {
        $declared = $mode['subagents'] ?? [];
        if (! is_array($declared)) {
            throw new InvalidArgumentException(sprintf('Harness mode [%s] declares a malformed subagent list.', $name));
        }

        $subagents = [];

        foreach ($declared as $key => $config) {
            if (! is_string($key) || ! is_array($config)) {
                throw new InvalidArgumentException(sprintf('Harness mode [%s] declares a malformed subagent.', $name));
            }

            $subagent = Subagent::fromArray($key, $config);

            $modes = $this->config['modes'] ?? [];
            if (! is_array($modes) || ! is_array($modes[$subagent->mode] ?? null)) {
                throw new InvalidArgumentException(sprintf(
                    'Harness mode [%s] declares subagent [%s], whose mode [%s] is not configured.',
                    $name,
                    $key,
                    $subagent->mode,
                ));
            }

            $subagents[$key] = $subagent;
        }

        return $subagents;
    }
}
