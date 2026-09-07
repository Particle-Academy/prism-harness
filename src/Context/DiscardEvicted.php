<?php

declare(strict_types=1);

namespace Prism\Harness\Context;

use Prism\Harness\Contracts\EvictionSink;

/**
 * Throw away what leaves the window.
 *
 * The default sink, and it is the default only because the alternative would be
 * to require infrastructure. It is NOT a recommendation.
 *
 * Paired with {@see NoCompaction} — also the default — nothing is ever evicted,
 * so nothing is ever discarded and this class does nothing at all. That
 * combination is the shipped behaviour: the harness replays the whole
 * conversation exactly as it always has.
 *
 * The pairing worth naming is the OTHER one. A real compaction strategy plus
 * this sink is context clearing without recovery, which is the configuration
 * with the worst properties available: the window is cheap, the agent cannot
 * see what it did, and there is no error anywhere when it answers from a gap.
 * If you are choosing a strategy, choose a sink in the same breath.
 */
final class DiscardEvicted implements EvictionSink
{
    #[\Override]
    public function store(array $messages, string $scope): void
    {
        // Nothing. Named so that a reader of a stack trace or a config file
        // sees a decision rather than an absence.
    }
}
