<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunContext;

/** Trusted host accounting at each capped provider call, independent of content capture. */
interface CallCostObserver
{
    /** The in-memory reservation exists. Throw to refuse before any provider request. */
    public function reserving(CallReservation $call, RunContext $run): void;

    /** Exactly once after successful reserving(), after the ledger has settled. May throw. */
    public function settled(CallSettlement $call, RunContext $run): void;
}
