<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use ReflectionClass;
use ReflectionMethod;

/**
 * A tool that tells a {@see ResolutionRecorder} what each of its calls returned.
 *
 * It carries the wrapped tool's state (name, description, schema, approval and
 * error-handling settings), so the provider and core see the same tool, and
 * its handle() hands core's own arguments to the wrapped tool's handle(),
 * unchanged, and returns what that returned, unchanged: its exception, its
 * ToolError, its output. A tool re-pointed with using() would not be
 * transparent: core would decode and check the arguments and the return value
 * a second time, around a variadic closure.
 *
 * Only a tool whose state can be carried exactly is wrapped: of() returns the
 * tool itself when Tool has state a subclass cannot copy, or when the tool's
 * class overrides any public method besides its handler.
 *
 * @internal
 */
final class ObservedTool extends Tool
{
    private Tool $inner;

    private ResolutionRecorder $recorder;

    public static function of(Tool $tool, ResolutionRecorder $recorder): Tool
    {
        if (! self::copiable($tool)) {
            return $tool;
        }
        $observed = new self;
        foreach (get_object_vars($tool) as $property => $value) {
            $observed->{$property} = $value;
        }
        $observed->inner = $tool;
        $observed->recorder = $recorder;

        return $observed;
    }

    public function handle(...$args): string|ToolOutput|ToolError
    {
        $output = $this->inner->handle(...$args);
        $this->recorder->completed($this->name(), $args, $output);

        return $output;
    }

    private static function copiable(Tool $tool): bool
    {
        foreach ((new ReflectionClass(Tool::class))->getProperties() as $property) {
            if ($property->isPrivate() || $property->isStatic() || $property->isReadOnly()) {
                return false;
            }
        }
        if ($tool::class === Tool::class) {
            return true;
        }
        foreach ((new ReflectionClass($tool))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! in_array($method->getName(), ['__construct', '__invoke'], true) && $method->getDeclaringClass()->getName() !== Tool::class) {
                return false;
            }
        }

        return true;
    }
}
