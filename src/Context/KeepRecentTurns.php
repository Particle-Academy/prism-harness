<?php

declare(strict_types=1);

namespace Prism\Harness\Context;

use Prism\Harness\Contracts\CompactionStrategy;

/**
 * Keep the last N messages, evict the rest. Deterministic, and no model call.
 *
 * The simplest thing that bounds a window, and worth shipping precisely because
 * it is unglamorous: it costs nothing, it cannot hallucinate, and its behaviour
 * is entirely predictable from the config value. An application reaching for
 * compaction for the first time should not have to buy a summarisation pipeline
 * to find out whether bounding the window helps at all.
 *
 * It belongs to the "prevention" family — bound growth structurally — rather
 * than "cure", which lets the window grow and compresses it with a model. The
 * research on compaction and safety is unkind to the cure family: summarisation
 * produces the HIGHEST rate of silently erased safety constraints of any
 * strategy measured, above 40%, precisely because a model rewrites the content
 * and nothing checks what it dropped. This strategy cannot rewrite anything.
 *
 * ## What it is not
 *
 * It is not a summariser and does not pretend the evicted turns are represented
 * in what remains. They are handed to the eviction sink and are reachable there;
 * they are not compressed into a précis. An application that wants the
 * conversation's gist to survive in-window needs a strategy that writes one, and
 * should read the paragraph above before choosing to.
 *
 * The `keep` count is MESSAGES, not turns, because a turn is not a countable
 * unit once tools are involved: one exchange can be an assistant message plus
 * six tool results. Counting messages is honest about what it bounds; counting
 * "turns" would bound something whose size varies by an order of magnitude.
 */
final class KeepRecentTurns implements CompactionStrategy
{
    public function __construct(private readonly int $keep = 40)
    {
        if ($keep < 1) {
            // A keep of zero evicts the entire conversation including the turn
            // being answered, which reads as a config typo rather than as an
            // intention. Refused here, where the value is, rather than as an
            // empty transcript at the provider.
            throw new \InvalidArgumentException('KeepRecentTurns needs to keep at least one message.');
        }
    }

    #[\Override]
    public function compact(array $messages): CompactionOutcome
    {
        if (count($messages) <= $this->keep) {
            return CompactionOutcome::untouched($messages);
        }

        $cut = count($messages) - $this->keep;

        return new CompactionOutcome(
            kept: array_slice($messages, $cut),
            evicted: array_slice($messages, 0, $cut),
        );
    }
}
