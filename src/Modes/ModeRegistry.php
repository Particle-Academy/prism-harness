<?php

declare(strict_types=1);

namespace Prism\Harness\Modes;

use InvalidArgumentException;
use Prism\Harness\Console\HarnessDoctorCommand;
use Prism\Harness\Exceptions\RegistrationCollision;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Support\RegistrationOwner;

final class ModeRegistry
{
    /** @var array<string, self> */
    private array $agents = [];

    /** @var array<string, string> */
    private array $owners = [];

    /** @param array<string, mixed> $config */
    public function __construct(private array $config, private readonly ?string $agent = null, private readonly ?self $root = null, private readonly bool $refuseDuplicates = false)
    {
        SessionAddress::validateAgent($agent);
        $owner = $config['owner'] ?? null;
        foreach ($this->names() as $name) {
            $this->owners[$name] = RegistrationOwner::label(is_string($owner) ? $owner : null, $agent);
        }
    }

    public function forAgent(?string $agent): self
    {
        SessionAddress::validateAgent($agent);
        $root = $this->root ?? $this;
        if ($agent === null) {
            return $root;
        }
        if (! isset($root->agents[$agent])) {
            $agents = $root->config['agents'] ?? [];
            $config = is_array($agents) ? ($agents[$agent] ?? []) : null;
            if (! is_array($config)) {
                throw new InvalidArgumentException('Agent modes must be configured as a mapping.');
            }
            $config['default'] ??= $root->default();
            $root->agents[$agent] = new self($config, $agent, $root, $root->refuseDuplicates);
        }

        return $root->agents[$agent];
    }

    /** @param array<string, mixed> $mode */
    public function register(string $name, array $mode, ?string $owner = null): self
    {
        $label = RegistrationOwner::label($owner, $this->agent);
        if (($this->agent !== null || $this->refuseDuplicates) && isset($this->owners[$name])) {
            throw new RegistrationCollision($this->agent, $name, $this->owners[$name], $label);
        }
        $this->set($name, $mode, $label);

        return $this;
    }

    /** @param array<string, mixed> $mode */
    public function replace(string $name, array $mode, string $predecessorOwner, ?string $owner = null): self
    {
        $label = RegistrationOwner::label($owner, $this->agent);
        if (($this->owners[$name] ?? null) !== $predecessorOwner) {
            throw new \LogicException('Replacement must name the registered predecessor owner.');
        }
        $this->set($name, $mode, $label);

        return $this;
    }

    /** @param array<string, mixed> $mode */
    private function set(string $name, array $mode, string $owner): void
    {
        $modes = $this->config['modes'] ?? [];
        if (! is_array($modes)) {
            throw new InvalidArgumentException('Modes must be configured as a mapping.');
        }
        $modes[$name] = $mode;
        $this->config['modes'] = $modes;
        $this->owners[$name] = $owner;
    }

    public function setDefault(string $name): self
    {
        $this->resolve($name);
        $this->config['default'] = $name;

        return $this;
    }

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

        $agents = $this->agentsFor($name, $mode);

        return new AgentMode(
            $name,
            $prompt,
            array_values(array_filter($tools, is_string(...))),
            array_values(array_filter($skills, is_string(...))),
            $maxSteps,
            $this->subagentsFor($name, $mode, $agents),
            $this->requiresApprovalFor($name, $mode),
            $this->providerOptionsFor($name, $mode),
            self::bindingValue($mode, 'provider', sprintf('Harness mode [%s] declares a malformed provider/model: expected a nonempty string or null.', $name)),
            self::bindingValue($mode, 'model', sprintf('Harness mode [%s] declares a malformed provider/model: expected a nonempty string or null.', $name)),
            $agents,
        );
    }

    /**
     * A declared provider or model: a nonempty string, or absent.
     *
     * Refused rather than ignored when it is anything else. An empty string or
     * a list would fall through to the agent default, and the mode would run
     * on a model its author did not choose while reading as configured.
     *
     * @param  array<array-key, mixed>  $declaration
     */
    private static function bindingValue(array $declaration, string $key, string $message): ?string
    {
        $value = $declaration[$key] ?? null;
        if ($value !== null && (! is_string($value) || $value === '')) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    /**
     * The named agents a subagent of this mode may run in.
     *
     * Held to the same identifier rule as an agent id itself, and NOT
     * normalised. Trimming or case-folding here would let a near-miss name
     * select another agent's authority — the shape of G-36.
     *
     * @param  array<string, mixed>  $mode
     * @return list<string>
     */
    private function agentsFor(string $name, array $mode): array
    {
        $declared = $mode['agents'] ?? [];
        $message = sprintf('Harness mode [%s] declares a malformed agents list: each entry must be a lowercase agent id of 1-64 ASCII bytes.', $name);
        if (! is_array($declared) || ! array_is_list($declared)) {
            throw new InvalidArgumentException($message);
        }
        foreach ($declared as $agent) {
            if (! is_string($agent) || ! SessionAddress::isAgent($agent)) {
                throw new InvalidArgumentException($message);
            }
        }

        /** @var list<string> $declared */
        return $declared;
    }

    /**
     * Options handed to the provider on every run in this mode.
     *
     * Passed through to Prism's `withProviderOptions()` unchanged, so a key
     * means whatever the provider says it means: `thinking` for Anthropic,
     * `reasoning` for OpenAI. The harness does not translate between them,
     * because a translation would be a second definition of each option to
     * keep in step with the providers.
     *
     * Refused rather than ignored when it is not a keyed map. A list, or a
     * scalar, would reach the provider as nothing, and the mode would run
     * without the option its author believes is on.
     *
     * @param  array<string, mixed>  $mode
     * @return array<string, mixed>
     */
    private function providerOptionsFor(string $name, array $mode): array
    {
        $declared = $mode['provider_options'] ?? [];

        if (! is_array($declared) || ($declared !== [] && array_is_list($declared))) {
            throw new InvalidArgumentException(sprintf('Harness mode [%s] declares malformed provider_options: expected a map of option names to values.', $name));
        }

        /** @var array<string, mixed> $declared */
        return $declared;
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
     * A subagent may also run in ANOTHER named agent, on that agent's provider
     * and model, but only one the mode lists in `agents`. Its mode is then
     * looked up in that agent, where it will run.
     *
     * @param  array<string, mixed>  $mode
     * @param  list<string>  $agents
     * @return array<string, Subagent>
     */
    private function subagentsFor(string $name, array $mode, array $agents = []): array
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

            $malformed = sprintf('Harness mode [%s] declares subagent [%s] with a malformed provider/model: expected a nonempty string or null.', $name, $key);
            self::bindingValue($config, 'provider', $malformed);
            self::bindingValue($config, 'model', $malformed);
            if (! is_bool($config['inherit_session_model'] ?? false)) {
                throw new InvalidArgumentException(sprintf('Harness mode [%s] declares subagent [%s] with a malformed inherit_session_model: expected a boolean.', $name, $key));
            }
            $agent = $config['agent'] ?? null;
            if ($agent !== null && (! is_string($agent) || ! SessionAddress::isAgent($agent))) {
                throw new InvalidArgumentException(sprintf('Harness mode [%s] declares subagent [%s] with a malformed agent id: agent ids are lowercase identifiers of 1-64 ASCII bytes.', $name, $key));
            }
            if (is_string($agent) && $agent !== $this->agent && ! in_array($agent, $agents, true)) {
                throw new InvalidArgumentException(sprintf('Harness mode [%s] declares subagent [%s] for agent [%s], which the mode does not list in its agents.', $name, $key, $agent));
            }

            $subagent = Subagent::fromArray($key, $config);

            // Names only: resolving the target mode here would recurse through
            // every mode it delegates to, and a cycle is bounded at run time.
            $target = $subagent->agent === null || $subagent->agent === $this->agent ? $this : ($this->root ?? $this)->forAgent($subagent->agent);
            if (! in_array($subagent->mode, $target->names(), true)) {
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
