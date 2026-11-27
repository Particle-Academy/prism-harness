<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Illuminate\Support\Str;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;

/**
 * The ToolInvoked reports of one approval resolution, held until the results
 * are recorded and then sent: under the telemetry context of the run's next
 * generation when one starts, under a bare context of its own otherwise.
 *
 * Each report carries exactly what core would have passed to
 * Telemetry::toolInvoked() for that call, and is sent through it, so the
 * content gate (`prism.telemetry.capture_content`) applies as core applies it.
 *
 * @internal
 */
final class ResolutionAnnouncement
{
    /** @var list<array{call: ToolCall, result: ?ToolResult, durationMs: float, failed: bool, failure: ?string}> */
    public array $invocations = [];

    /** Tool executions in progress inside this resolution (a failure inside one is its own, not a refusal). */
    public int $executing = 0;

    /**
     * Asked, with the call, right before the resolution runs an approved
     * call (G-387 round 15): true to run it, false (or the text that says why, round 17) when it must not run.
     *
     * @var (\Closure(ToolCall): (bool|string))|null
     */
    public ?\Closure $starter = null;

    private bool $sent = false;

    /** @param list<TelemetryOperation> $operations */
    public function __construct(
        public readonly TextRequest|StructuredRequest $request,
        public readonly array $operations,
        public readonly string $provider,
        public readonly string $model,
    ) {}

    public function pending(): bool
    {
        return ! $this->sent && $this->invocations !== [];
    }

    /** Whether a generation started under this context is the one the resolved results were sent in. */
    public function belongsTo(TelemetryContext $context): bool
    {
        return in_array($context->operation, $this->operations, true)
            && $context->provider === $this->provider
            && $context->model === $this->model;
    }

    /**
     * Send every held report under the given context, or a bare one. Each is
     * dispatched on its own: a listener that throws is reported, never thrown,
     * and costs no other call its event.
     */
    public function send(?TelemetryContext $context = null): void
    {
        if ($this->sent) {
            return;
        }
        $this->sent = true;
        if ($this->invocations === [] || ! Telemetry::enabled()) {
            return;
        }
        $context ??= new TelemetryContext(
            traceId: (string) Str::uuid(),
            operation: $this->operations[0],
            provider: $this->provider,
            model: $this->model,
            startedAt: microtime(true),
        );
        foreach ($this->invocations as $invocation) {
            try {
                Telemetry::toolInvoked($context, $invocation['call'], $invocation['result'], $invocation['durationMs'],
                    failed: $invocation['failed'], failure: $invocation['failure']);
            } catch (Throwable $failure) {
                report($failure);
            }
        }
    }
}
