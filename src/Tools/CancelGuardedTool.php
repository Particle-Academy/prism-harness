<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Closure;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use ReflectionClass;
use ReflectionMethod;

/**
 * A tool that checks its run's cancel flag immediately before it runs (G-285).
 *
 * Several tool calls in one model step run one after another inside core, so
 * a check between steps alone would let every call after a cancel still run.
 * This is the check before each one. A call already running is never
 * interrupted: a half-executed tool is the state the durable record exists
 * to protect, so it finishes and the NEXT call is refused.
 *
 * The same tool in every other respect: its state is copied, and the call is
 * delegated to the original, so its own handler, error handling and approval
 * settings are untouched.
 *
 * @internal Built by the runtime around each tool it offers a run.
 */
final class CancelGuardedTool extends Tool
{
    public const REFUSAL = 'Not run: the run was cancelled.';

    private Tool $inner;

    /** @var Closure(): bool */
    private Closure $cancelled;

    private ?string $runId = null;

    /**
     * The guarded tool, or the tool itself when it cannot be guarded without
     * changing what it does: a tool class that overrides anything but
     * handle(), or one that runs concurrently with the other calls of its
     * step (those start together, so there is no "next" call to refuse).
     *
     * @param  Closure(): bool  $cancelled
     */
    public static function guard(Tool $tool, Closure $cancelled, ?string $runId): Tool
    {
        if ($tool instanceof self || ! self::guardable($tool)) {
            return $tool;
        }
        /** @var self $guarded */
        $guarded = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $state = Closure::bind(static fn (Tool $source): array => get_object_vars($source), null, Tool::class)($tool);
        Closure::bind(static function (Tool $target, array $state): void {
            foreach ($state as $name => $value) {
                $target->{$name} = $value;
            }
        }, null, Tool::class)($guarded, $state);
        $guarded->inner = $tool;
        $guarded->cancelled = $cancelled;
        $guarded->runId = $runId;

        return $guarded;
    }

    public function handle(...$args): string|ToolOutput|ToolError
    {
        if (($this->cancelled)()) {
            return ToolRefusals::record($this->runId, $this->name(), self::REFUSAL);
        }

        return $this->inner->handle(...$args);
    }

    private static function guardable(Tool $tool): bool
    {
        if (Closure::bind(static fn (Tool $source): bool => $source->concurrent, null, Tool::class)($tool)) {
            return false;
        }
        if ($tool::class === Tool::class) {
            return true;
        }
        foreach ((new ReflectionClass(Tool::class))->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
            if (in_array($method->getName(), ['handle', '__construct'], true) || $method->isStatic()) {
                continue;
            }
            if ((new ReflectionMethod($tool, $method->getName()))->getDeclaringClass()->getName() !== Tool::class) {
                return false;
            }
        }

        return true;
    }
}
