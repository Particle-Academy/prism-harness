<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Closure;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Tool;
use Prism\Prism\Tools\LaravelMcpTool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * A tool that stands in for another and answers every question as it does.
 *
 * Every public method of Tool is delegated to the wrapped tool, so a subclass
 * that overrides one (its name, its schema, the handler it reflects on) answers
 * through the wrapper exactly as without it, and a setter changes the wrapped
 * tool, as it would unwrapped. handle() is delegated too, with core's own
 * arguments, and its return value, ToolError or exception comes back
 * unchanged; a subclass adds only what it does around that call. The
 * wrapped tool's Tool state is copied as well, for code that reads it from
 * Tool's own scope.
 *
 * Re-pointing a clone with using() is not transparent: core would then
 * decode the arguments and check the return value a second time, around a
 * variadic closure, and turn a ToolError into an "invalid return type" error.
 *
 * Only a tool that can be stood in for exactly is wrapped: see canWrap().
 *
 * @internal
 */
abstract class DelegatingTool extends Tool
{
    protected Tool $inner;

    /**
     * Whether $tool can be wrapped without anything seeing a difference.
     *
     * Not when Tool has a public method this class does not delegate (a newer
     * core), nor when the tool's class adds public methods of its own, which
     * code may reach by a type check the wrapper cannot pass. The one such
     * class core checks for, LaravelMcpTool, is wrapped when core would offer
     * it: core's only check of it is the eligibility filter at withTools().
     */
    public static function canWrap(Tool $tool): bool
    {
        foreach ((new ReflectionClass(Tool::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->getName() === '__construct') {
                continue;
            }
            if (! is_a((new ReflectionMethod(static::class, $method->getName()))->getDeclaringClass()->getName(), self::class, true)) {
                return false;
            }
        }
        if ($tool instanceof LaravelMcpTool) {
            return $tool::class === LaravelMcpTool::class && $tool->eligibleForRegistration();
        }
        foreach ((new ReflectionClass($tool))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! in_array($method->getName(), ['__construct', '__invoke'], true) && ! method_exists(Tool::class, $method->getName())) {
                return false;
            }
        }

        return true;
    }

    protected function adopt(Tool $tool): void
    {
        foreach ((new ReflectionClass(Tool::class))->getProperties() as $property) {
            if (! $property->isStatic() && $property->isInitialized($tool)) {
                (new ReflectionProperty(Tool::class, $property->getName()))->setValue($this, $property->getValue($tool));
            }
        }
        $this->inner = $tool;
    }

    /**
     * A public method the wrapped tool's class adds (see canWrap()).
     *
     * @param  array<int|string, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }

    public function handle(...$args): string|ToolOutput|ToolError
    {
        return $this->inner->handle(...$args);
    }

    public function as(string $name): self
    {
        $this->inner->as($name);

        return $this;
    }

    public function for(string $description): self
    {
        $this->inner->for($description);

        return $this;
    }

    public function using(Closure|callable $fn): self
    {
        $this->inner->using($fn);

        return $this;
    }

    public function clientExecuted(): self
    {
        $this->inner->clientExecuted();

        return $this;
    }

    public function isClientExecuted(): bool
    {
        return $this->inner->isClientExecuted();
    }

    public function withholdOtherCalls(bool $withhold = true): self
    {
        $this->inner->withholdOtherCalls($withhold);

        return $this;
    }

    public function withholdsOtherCalls(): bool
    {
        return $this->inner->withholdsOtherCalls();
    }

    public function requiresApproval(bool|Closure $condition = true): self
    {
        $this->inner->requiresApproval($condition);

        return $this;
    }

    public function hasApprovalConfigured(): bool
    {
        return $this->inner->hasApprovalConfigured();
    }

    /** @param array<string, mixed> $arguments */
    public function needsApproval(array $arguments = []): bool
    {
        return $this->inner->needsApproval($arguments);
    }

    public function namesParameter(string $name): bool
    {
        return $this->inner->namesParameter($name);
    }

    public function make(string|object $tool): Tool
    {
        return $this->inner->make($tool);
    }

    public function failed(Closure $handler): self
    {
        $this->inner->failed($handler);

        return $this;
    }

    public function withoutErrorHandling(): self
    {
        $this->inner->withoutErrorHandling();

        return $this;
    }

    public function withErrorHandling(?Closure $handler = null): self
    {
        $this->inner->withErrorHandling($handler);

        return $this;
    }

    public function concurrent(bool $concurrent = true): self
    {
        $this->inner->concurrent($concurrent);

        return $this;
    }

    public function isConcurrent(): bool
    {
        return $this->inner->isConcurrent();
    }

    public function withParameter(Schema $parameter, bool $required = true): self
    {
        $this->inner->withParameter($parameter, $required);

        return $this;
    }

    public function withStringParameter(string $name, string $description, bool $required = true): self
    {
        $this->inner->withStringParameter($name, $description, $required);

        return $this;
    }

    public function withNumberParameter(string $name, string $description, bool $required = true): self
    {
        $this->inner->withNumberParameter($name, $description, $required);

        return $this;
    }

    public function withBooleanParameter(string $name, string $description, bool $required = true): self
    {
        $this->inner->withBooleanParameter($name, $description, $required);

        return $this;
    }

    public function withArrayParameter(string $name, string $description, Schema $items, bool $required = true): self
    {
        $this->inner->withArrayParameter($name, $description, $items, $required);

        return $this;
    }

    /**
     * @param  array<int, Schema>  $properties
     * @param  array<int, string>  $requiredFields
     */
    public function withObjectParameter(string $name, string $description, array $properties, array $requiredFields = [], bool $allowAdditionalProperties = false, bool $required = true): self
    {
        $this->inner->withObjectParameter($name, $description, $properties, $requiredFields, $allowAdditionalProperties, $required);

        return $this;
    }

    /** @param array<int, string|int|float> $options */
    public function withEnumParameter(string $name, string $description, array $options, bool $required = true): self
    {
        $this->inner->withEnumParameter($name, $description, $options, $required);

        return $this;
    }

    public function requiredParameters(): array
    {
        return $this->inner->requiredParameters();
    }

    public function parameters(): array
    {
        return $this->inner->parameters();
    }

    public function parametersAsArray(): array
    {
        return $this->inner->parametersAsArray();
    }

    public function parametersAsObject(): stdClass
    {
        return $this->inner->parametersAsObject();
    }

    public function parametersAsSchema(bool $requireAllProperties = false): array
    {
        return $this->inner->parametersAsSchema($requireAllProperties);
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function description(): string
    {
        return $this->inner->description();
    }

    public function hasParameters(): bool
    {
        return $this->inner->hasParameters();
    }

    public function failedHandler(): null|false|Closure
    {
        return $this->inner->failedHandler();
    }

    /** @param array<string, mixed> $options */
    public function withProviderOptions(array $options = []): self
    {
        $this->inner->withProviderOptions($options);

        return $this;
    }

    public function providerOptions(?string $valuePath = null, mixed $default = null): mixed
    {
        return $this->inner->providerOptions($valuePath, $default);
    }

    /** @param array<string, mixed> $options */
    public function copyWithProviderOptions(array $options): static
    {
        $copy = clone $this;
        $copy->inner = $this->inner->copyWithProviderOptions($options);
        $copy->adopt($copy->inner);

        return $copy;
    }
}
