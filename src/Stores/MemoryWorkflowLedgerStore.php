<?php

declare(strict_types=1);

namespace Prism\Harness\Stores;

use Closure;
use Prism\Harness\Contracts\WorkflowLedgerStore;

/**
 * A workflow budget held for one process only (G-348).
 *
 * For a synchronous `FlowRunner::run()` in one process, and for tests. A
 * retry or resume in another process starts from nothing, so a durable
 * driver must use {@see DatabaseWorkflowLedgerStore}.
 */
final class MemoryWorkflowLedgerStore implements WorkflowLedgerStore
{
    /** @var array<string, array<string, mixed>> */
    private array $runs = [];

    public function transact(string $runKey, array $initial, Closure $operation): mixed
    {
        [$state, $result] = $operation($this->runs[$runKey] ?? $initial);
        $this->runs[$runKey] = $state;

        return $result;
    }
}
