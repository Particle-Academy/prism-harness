<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Closure;

/**
 * Where a workflow run's budget lives between workers (G-348).
 *
 * A retried node, a resumed job and a parallel sibling each open the run's
 * account in a different process, so it cannot live in memory. Every
 * admission is one call to {@see self::transact()}, which holds the run's
 * record exclusively for the whole read, decision and write: two workers can
 * never both take the last step.
 */
interface WorkflowLedgerStore
{
    /**
     * Hold the run's record, creating it with `$initial` when absent, and
     * pass its state to `$operation`. Whatever state `$operation` returns is
     * stored before the hold is released.
     *
     * @template T
     *
     * @param  array<string, mixed>  $initial
     * @param  Closure(array<string, mixed>): array{0: array<string, mixed>, 1: T}  $operation
     * @return T
     */
    public function transact(string $runKey, array $initial, Closure $operation): mixed;
}
