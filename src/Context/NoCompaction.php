<?php

declare(strict_types=1);

namespace Prism\Harness\Context;

use Prism\Harness\Contracts\CompactionStrategy;

/**
 * Replay the whole conversation. The default, and deliberately so.
 *
 * This is what the harness did before compaction existed, so installing a
 * version that has it changes nothing until somebody asks for it. A package
 * that started silently dropping turns on upgrade would be changing what the
 * model sees — and therefore what it answers — with no line in anyone's diff to
 * explain a behaviour change they did not make.
 *
 * It is also the honest default for correctness rather than only for
 * compatibility: replaying everything is the only strategy that cannot lose
 * something the agent needed. It is the one that stops working first, and the
 * point at which it does is a decision the application should make with its own
 * knowledge of its workload.
 */
final class NoCompaction implements CompactionStrategy
{
    #[\Override]
    public function compact(array $messages): CompactionOutcome
    {
        return CompactionOutcome::untouched($messages);
    }
}
