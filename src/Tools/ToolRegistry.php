<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Closure;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Tool;

final class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools = [];

    /** @var array<string, Closure(Session): Tool> */
    private array $factories = [];

    /** @var list<Closure(Session): iterable<Tool>> */
    private array $providers = [];

    public function register(Tool $tool): self
    {
        $this->tools[$tool->name()] = $tool;

        return $this;
    }

    /** @param iterable<Tool> $tools */
    public function registerMany(iterable $tools): self
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }

        return $this;
    }

    /** @param Closure(Session): Tool $factory */
    public function registerFactory(string $name, Closure $factory): self
    {
        $this->factories[$name] = $factory;

        return $this;
    }

    /** @param Closure(Session): iterable<Tool> $provider */
    public function registerProvider(Closure $provider): self
    {
        $this->providers[] = $provider;

        return $this;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, Tool>
     */
    public function resolve(array $names, ?Session $session = null): array
    {
        $provided = [];
        if ($session instanceof Session) {
            foreach ($this->providers as $provider) {
                foreach ($provider($session) as $tool) {
                    $provided[$tool->name()] = $tool;
                }
            }
        }
        $selected = in_array('*', $names, true)
            ? array_values(array_unique([...array_keys($this->tools), ...array_keys($this->factories), ...array_keys($provided)]))
            : $names;
        $resolved = array_intersect_key([...$this->tools, ...$provided], array_flip($selected));

        foreach (array_intersect_key($this->factories, array_flip($selected)) as $name => $factory) {
            if (! $session instanceof Session) {
                throw new \LogicException(sprintf('Session-bound tool [%s] requires a Harness session.', $name));
            }
            $tool = $factory($session);
            if ($tool->name() !== $name) {
                throw new \LogicException(sprintf('Session-bound tool factory [%s] returned [%s].', $name, $tool->name()));
            }
            $resolved[$name] = $tool;
        }

        return $resolved;
    }
}
