<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Closure;
use LogicException;
use Prism\Harness\Exceptions\RegistrationCollision;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Support\RegistrationOwner;
use Prism\Prism\Tool;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** @var array<string, Closure(Session): Tool> */
    private array $factories = [];

    /** @var array<string, array{provider: Closure(Session): iterable<Tool>, owner: string}> */
    private array $providers = [];

    /** @var array<string, array{owner: string, source: string}> */
    private array $metadata = [];

    /** @var array<string, self> */
    private array $agents = [];

    public function __construct(private readonly ?string $agent = null, private readonly ?self $root = null)
    {
        SessionAddress::validateAgent($agent);
    }

    public function forAgent(?string $agent): self
    {
        SessionAddress::validateAgent($agent);
        $root = $this->root ?? $this;
        if ($agent === null) {
            return $root;
        }

        return $root->agents[$agent] ??= new self($agent, $root);
    }

    public function register(Tool $tool, ?string $owner = null): self
    {
        $this->claim($tool->name(), $owner, 'static');
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    /** @param iterable<Tool> $tools */
    public function registerMany(iterable $tools, ?string $owner = null): self
    {
        foreach ($tools as $tool) {
            $this->register($tool, $owner);
        }

        return $this;
    }

    /** @param Closure(Session): Tool $factory */
    public function registerFactory(string $name, Closure $factory, ?string $owner = null): self
    {
        $this->claim($name, $owner, 'factory');
        $this->factories[$name] = $factory;

        return $this;
    }

    /** @param Closure(Session): iterable<Tool> $provider */
    public function registerProvider(Closure $provider, ?string $owner = null, ?string $name = null): self
    {
        $label = RegistrationOwner::label($owner, $this->agent);
        $id = $name ?? ($this->agent === null ? 'provider:'.count($this->providers) : null);
        if ($id === null || $id === '') {
            throw new LogicException('A named provider requires a registration name.');
        }
        if (isset($this->providers[$id])) {
            throw new RegistrationCollision($this->agent, $id, $this->providers[$id]['owner'], $label);
        }
        $this->providers[$id] = ['provider' => $provider, 'owner' => $label];

        return $this;
    }

    public function replace(Tool $tool, string $predecessorOwner, ?string $owner = null): self
    {
        $this->claim($tool->name(), $owner, 'static', $predecessorOwner);
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    /** @param Closure(Session): Tool $factory */
    public function replaceFactory(string $name, Closure $factory, string $predecessorOwner, ?string $owner = null): self
    {
        $this->claim($name, $owner, 'factory', $predecessorOwner);
        $this->factories[$name] = $factory;

        return $this;
    }

    /** @param Closure(Session): iterable<Tool> $provider */
    public function replaceProvider(string $name, Closure $provider, string $predecessorOwner, ?string $owner = null): self
    {
        $label = RegistrationOwner::label($owner, $this->agent);
        if (! isset($this->providers[$name]) || $this->providers[$name]['owner'] !== $predecessorOwner) {
            throw new LogicException('Replacement must name the registered predecessor owner.');
        }
        $this->providers[$name] = ['provider' => $provider, 'owner' => $label];

        return $this;
    }

    private function claim(string $name, ?string $owner, string $source, ?string $predecessor = null): void
    {
        $label = RegistrationOwner::label($owner, $this->agent);
        $previous = $this->metadata[$name] ?? null;
        if ($predecessor !== null) {
            if ($previous === null || $previous['owner'] !== $predecessor) {
                throw new LogicException('Replacement must name the registered predecessor owner.');
            }
        } elseif ($previous !== null) {
            throw new RegistrationCollision($this->agent, $name, $previous['owner'], $label);
        }
        unset($this->tools[$name], $this->factories[$name]);
        $this->metadata[$name] = ['owner' => $label, 'source' => $source];
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_keys($this->metadata);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, Tool>
     */
    public function resolve(array $names, ?Session $session = null): array
    {
        return array_map(fn (array $entry): Tool => $entry['tool'], $this->resolveDetailed($names, $session));
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, array{tool: Tool, owner: string, source: string}>  $additional
     * @return array<string, array{tool: Tool, owner: string, source: string}>
     */
    public function resolveDetailed(array $names, ?Session $session = null, array $additional = []): array
    {
        if ($session instanceof Session && $session->agent() !== $this->agent) {
            if ($this->agent !== null) {
                throw new LogicException('Harness registry/session agent mismatch.');
            }

            return $this->forAgent($session->agent())->resolveDetailed($names, $session, $additional);
        }
        $provided = [];
        $metadata = $this->metadata;
        foreach ($additional as $name => $entry) {
            $owner = RegistrationOwner::label($entry['owner'], $this->agent);
            if (isset($metadata[$name])) {
                throw new RegistrationCollision($this->agent, $name, $metadata[$name]['owner'], $owner);
            }
            if ($entry['tool']->name() !== $name) {
                throw new LogicException('An additional tool must match its registered name.');
            }
            $provided[$name] = $entry['tool'];
            $metadata[$name] = ['owner' => $owner, 'source' => $entry['source']];
        }
        if ($session instanceof Session) {
            foreach ($this->providers as $registration) {
                foreach (($registration['provider'])($session) as $tool) {
                    if (isset($metadata[$tool->name()])) {
                        throw new RegistrationCollision($this->agent, $tool->name(), $metadata[$tool->name()]['owner'], $registration['owner']);
                    }
                    $provided[$tool->name()] = $tool;
                    $metadata[$tool->name()] = ['owner' => $registration['owner'], 'source' => 'provider'];
                }
            }
        }
        $selected = in_array('*', $names, true)
            ? array_values(array_unique([...array_keys($this->tools), ...array_keys($this->factories), ...array_keys($provided)]))
            : $names;
        $resolved = array_intersect_key([...$this->tools, ...$provided], array_flip($selected));

        foreach (array_intersect_key($this->factories, array_flip($selected)) as $name => $factory) {
            if (! $session instanceof Session) {
                throw new LogicException(sprintf('Session-bound tool [%s] requires a Harness session.', $name));
            }
            $tool = $factory($session);
            if ($tool->name() !== $name) {
                throw new LogicException(sprintf('Session-bound tool factory [%s] returned [%s].', $name, $tool->name()));
            }
            $resolved[$name] = $tool;
        }

        $detailed = [];
        if ($this->agent !== null && array_diff($selected, array_keys($resolved)) !== []) {
            throw new LogicException('A requested tool is not registered in this agent namespace.');
        }
        foreach ($resolved as $name => $tool) {
            $detailed[$name] = ['tool' => $tool, ...$metadata[$name]];
        }

        return $detailed;
    }
}
