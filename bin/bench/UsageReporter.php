<?php

declare(strict_types=1);

namespace Prism\Harness\Bench;

use Closure;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunContext;

/**
 * Reports every settled model call as a `usage` line.
 *
 * Read from the harness ledger's own settlement, so the figure the adapter
 * enforces its arm cap with is the figure the inner cap was enforced with.
 */
final class UsageReporter implements CallCostObserver
{
    /** @param Closure(string, array<string, mixed>): void $emit */
    public function __construct(private readonly Closure $emit) {}

    public function reserving(CallReservation $call, RunContext $run): void {}

    public function settled(CallSettlement $call, RunContext $run): void
    {
        $usage = $call->usage;
        ($this->emit)('usage', [
            'call_id' => $call->callId,
            'outcome' => $call->outcome,
            'usage_known' => $call->usageKnown,
            'input_tokens' => $usage === null ? 0 : $usage->promptTokens,
            'cache_write_tokens' => $usage?->cacheWriteInputTokens ?? 0,
            'cache_read_tokens' => $usage?->cacheReadInputTokens ?? 0,
            'output_tokens' => $usage === null ? 0 : $usage->completionTokens,
            'reasoning_tokens' => $usage?->thoughtTokens,
            'charged_usd' => round($call->chargedUsd, 8),
        ]);
    }
}
