<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Closure;
use LogicException;
use Prism\Harness\Contracts\DeclaresToolScope;
use Prism\Harness\Exceptions\RegistrationCollision;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Support\RegistrationOwner;
use Prism\Prism\Tool;
use WeakMap;

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

    /** @var array<string, string> */
    private array $staticOwners = [];

    /** @var array<string, string> */
    private array $factoryOwners = [];

    /** @var array<string, self> */
    private array $agents = [];

    /**
     * Which provider each provider-sourced tool came from (G-326). Kept on the
     * ROOT, so a tool resolved through an agent view is still known.
     *
     * @var WeakMap<Tool, string>
     */
    private WeakMap $scopes;

    public function __construct(private readonly ?string $agent = null, private readonly ?self $root = null, private readonly bool $refuseDuplicates = false)
    {
        SessionAddress::validateAgent($agent);
        $this->scopes = new WeakMap;
    }

    public function forAgent(?string $agent): self
    {
        SessionAddress::validateAgent($agent);
        $root = $this->root ?? $this;
        if ($agent === null) {
            return $root;
        }

        return $root->agents[$agent] ??= new self($agent, $root, $root->refuseDuplicates);
    }

    public function register(Tool $tool, ?string $owner = null): self
    {
        $this->claim($tool->name(), $owner, 'static');
        $this->tools[$tool->name()] = $tool;
        $this->staticOwners[$tool->name()] = RegistrationOwner::label($owner, $this->agent);

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
        $this->factoryOwners[$name] = RegistrationOwner::label($owner, $this->agent);

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
        if ($this->strict() && isset($this->providers[$id])) {
            throw new RegistrationCollision($this->agent, $id, $this->providers[$id]['owner'], $label);
        }
        $this->providers[$id] = ['provider' => $provider, 'owner' => $label];

        return $this;
    }

    public function replace(Tool $tool, string $predecessorOwner, ?string $owner = null): self
    {
        $this->claim($tool->name(), $owner, 'static', $predecessorOwner);
        $this->tools[$tool->name()] = $tool;
        $this->staticOwners[$tool->name()] = RegistrationOwner::label($owner, $this->agent);

        return $this;
    }

    /** @param Closure(Session): Tool $factory */
    public function replaceFactory(string $name, Closure $factory, string $predecessorOwner, ?string $owner = null): self
    {
        $this->claim($name, $owner, 'factory', $predecessorOwner);
        $this->factories[$name] = $factory;
        $this->factoryOwners[$name] = RegistrationOwner::label($owner, $this->agent);

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
        } elseif ($this->strict() && $previous !== null) {
            throw new RegistrationCollision($this->agent, $name, $previous['owner'], $label);
        }
        if ($this->strict() || $predecessor !== null) {
            unset($this->tools[$name], $this->factories[$name], $this->staticOwners[$name], $this->factoryOwners[$name]);
        }
        $this->metadata[$name] = ['owner' => $label, 'source' => $source];
    }

    private function strict(): bool
    {
        return $this->agent !== null || $this->refuseDuplicates;
    }

    /**
     * The statically registered tools, for what they declare about themselves
     * (G-326): see {@see DeclaresToolScope}.
     *
     * @return list<Tool>
     */
    public function declarations(): array
    {
        return array_values($this->tools);
    }

    /**
     * What a resolved tool declares for the host tool policy (G-326), with its
     * server as the REGISTRY knows it.
     *
     * A tool that came out of a provider is scoped to the provider's registered
     * name, whatever {@see DeclaresToolScope::toolServer()} says: a bridge that
     * forgets to declare a server, or a tool that claims another server's name,
     * cannot make a provider's tool look native or look like it came from
     * somewhere else. The claim is OVERRIDDEN rather than refused: refusing
     * would let one tool with a bad claim take every tool of every provider out
     * of the run, and once overridden the claim has no effect anyway. Only a
     * natively registered tool can have no server.
     *
     * @return array{name: string, server: ?string, tier: ?string}
     */
    public function declarationOf(Tool $tool): array
    {
        $declared = ToolPolicy::declarationOf($tool);
        $root = $this->root ?? $this;
        if (isset($root->scopes[$tool])) {
            $declared['server'] = $root->scopes[$tool];
        }

        return $declared;
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
            if ($this->strict() && isset($metadata[$name])) {
                throw new RegistrationCollision($this->agent, $name, $metadata[$name]['owner'], $owner);
            }
            if ($entry['tool']->name() !== $name) {
                throw new LogicException('An additional tool must match its registered name.');
            }
            $provided[$name] = $entry['tool'];
            $metadata[$name] = ['owner' => $owner, 'source' => $entry['source']];
        }
        if ($session instanceof Session) {
            $scopes = ($this->root ?? $this)->scopes;
            foreach ($this->providers as $server => $registration) {
                foreach (($registration['provider'])($session) as $tool) {
                    if ($this->strict() && isset($metadata[$tool->name()])) {
                        throw new RegistrationCollision($this->agent, $tool->name(), $metadata[$tool->name()]['owner'], $registration['owner']);
                    }
                    $scopes[$tool] = (string) $server;
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
            $metadata[$name] = ['owner' => $this->factoryOwners[$name], 'source' => 'factory'];
        }

        $detailed = [];
        if ($this->agent !== null && array_diff($selected, array_keys($resolved)) !== []) {
            throw new LogicException('A requested tool is not registered in this agent namespace.');
        }
        foreach ($resolved as $name => $tool) {
            if (! isset($provided[$name]) && ! isset($this->factories[$name])) {
                $metadata[$name] = ['owner' => $this->staticOwners[$name], 'source' => 'static'];
            }
            $detailed[$name] = ['tool' => $tool, ...$metadata[$name]];
        }

        return $detailed;
    }
}
