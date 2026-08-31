<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * The base of the harness event stream.
 *
 * DELIBERATELY NOT TELEMETRY, and the distinction was decided before any of
 * this was written: telemetry is OBSERVABILITY — sampled, droppable, read by
 * whoever is debugging — while these are INTERFACE. An application builds its
 * UI on them, so they carry a stability guarantee telemetry never will, and
 * they are shaped for a reader rather than for a trace.
 *
 * Every event carries the run's lineage, so a consuming application can join
 * this stream to its own without adopting it. That is the point for Moic:
 * their live view of thinking, tool calls and results stays theirs, and these
 * identifiers let the durable record line up beside it.
 */
abstract readonly class HarnessEvent
{
    public function __construct(
        public string $sessionKey,
        public string $runId,
        public ?string $parentRunId = null,
        public ?string $rootRunId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function correlation(): array
    {
        return [
            'session_key' => $this->sessionKey,
            'run_id' => $this->runId,
            'parent_run_id' => $this->parentRunId,
            'root_run_id' => $this->rootRunId ?? $this->runId,
        ];
    }
}
