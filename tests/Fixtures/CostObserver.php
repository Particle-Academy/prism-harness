<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Closure;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunContext;

final class CostObserver implements CallCostObserver
{
    /** @var list<CallReservation> */
    public array $reservations = [];

    /** @var list<CallSettlement> */
    public array $settlements = [];

    /** @var list<string> */
    public array $order = [];

    public ?Closure $before = null;

    public ?Closure $after = null;

    public function reserving(CallReservation $call, RunContext $run): void
    {
        $this->reservations[] = $call;
        $this->order[] = 'reserving';
        ($this->before ?? static fn () => null)($call, $run);
    }

    public function settled(CallSettlement $call, RunContext $run): void
    {
        $this->settlements[] = $call;
        $this->order[] = 'settled';
        ($this->after ?? static fn () => null)($call, $run);
    }
}
