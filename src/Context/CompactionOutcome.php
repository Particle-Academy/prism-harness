<?php

declare(strict_types=1);

namespace Prism\Harness\Context;

use Prism\Harness\Contracts\EvictionSink;
use Prism\Prism\Contracts\Message;

/**
 * What a compaction decided: what goes to the model, and what leaves the window.
 *
 * BOTH HALVES ARE REQUIRED, and that is the whole reason this is a value object
 * rather than a strategy simply returning a shorter array. A strategy that only
 * returned what it kept would make the removed messages unrecoverable by
 * construction — the harness would have nothing to hand a sink, and compaction
 * would be lossy however good the storage layer underneath it was.
 *
 * That is not hypothetical. Anthropic's `clear_tool_uses` drops tool results
 * and hands back nothing, and a consumer measuring a real audit sweep found
 * their agent noticing its own cleared results and REFUSING TO FINISH rather
 * than report counts it could no longer stand behind — having already asserted
 * one from cleared evidence and caught itself. The evicted half is what turns
 * that into a lookup.
 */
final readonly class CompactionOutcome
{
    /**
     * @param  list<Message>  $kept  Replayed to the model, oldest first.
     * @param  list<Message>  $evicted  Removed from the window. Handed to an
     *                                  {@see EvictionSink},
     *                                  never deleted from storage.
     */
    public function __construct(
        public array $kept,
        public array $evicted = [],
    ) {}

    /**
     * Nothing was compacted.
     *
     * Named rather than left to `new CompactionOutcome($messages)`, because
     * "kept everything" is a decision a strategy makes deliberately — under a
     * threshold, on a short thread — and reads better at the call site than an
     * omitted second argument.
     *
     * @param  list<Message>  $messages
     */
    public static function untouched(array $messages): self
    {
        return new self($messages);
    }

    public function compacted(): bool
    {
        return $this->evicted !== [];
    }
}
