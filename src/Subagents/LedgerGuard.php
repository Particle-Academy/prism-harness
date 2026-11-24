<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Flow\WorkflowBudget;

/**
 * Keeps a {@see RunLedger} that several processes share honest (G-348).
 *
 * A workflow run's ledger outlives the worker that opened it: a retried node,
 * a resumed job and a parallel sibling each open it again elsewhere. Every
 * read and write of a guarded ledger runs through {@see self::atomically()},
 * which loads the shared totals into the ledger first and stores them after,
 * under one exclusive hold, so the in-memory object is only ever a cache.
 *
 * @internal Implemented by {@see WorkflowBudget}.
 */
interface LedgerGuard
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function atomically(RunLedger $ledger, Closure $operation): mixed;

    /** The refusal for a ledger that may not spend again, recorded as the run's exhaustion. */
    public function refusal(RunLedger $ledger, string $reason): RunNotPermitted;
}
