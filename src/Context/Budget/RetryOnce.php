<?php

declare(strict_types=1);

namespace Prism\Harness\Context\Budget;

use Prism\Harness\Contracts\SummaryBudget;

/**
 * Count the summary and, when it is over, ask once more for a shorter one.
 *
 * The default, because it is the only shipped budget that improves the result
 * without being able to damage it. Measured against a live model at a 60-word
 * budget it took the summary from 205 words to 61.
 *
 * **Once, not until it fits.** A loop would spend unbounded calls chasing a
 * number the model may simply never hit, on a path that already bills a call per
 * compacting turn. So this is allowed to miss, and does: in the same measured
 * runs, arms finished at 118 and 84 words against a budget of 60, meaning both
 * the first and the second answer came back over.
 *
 * If that matters more than the second call costs, {@see TruncateTo} guarantees
 * the bound and {@see AskOnly} stops paying for the attempt.
 */
final class RetryOnce implements SummaryBudget
{
    use CountsWords;

    #[\Override]
    public function apply(string $summary, int $limit, callable $rewrite): string
    {
        $length = $this->words($summary);

        if ($length <= $limit) {
            return $summary;
        }

        // The overshoot is quoted because "you used N of a budget of M" is a
        // correction, where repeating the original instruction is just asking
        // the same question again and hoping.
        $retried = $rewrite(sprintf(
            'That summary was %d words. The limit is %d. Rewrite it to fit, keeping '
            ."names, numbers, identifiers and decisions and cutting elaboration:\n\n%s",
            $length,
            $limit,
            $summary,
        ));

        // A retry may fail and may miss. What it must not do is make things
        // worse: a null second call, or a longer second answer, leaves the first
        // one standing. Returning nothing would be the worst outcome available —
        // SummarisingCompaction would keep the whole conversation rather than
        // evict turns with no summary standing in for them.
        if ($retried === null) {
            return $summary;
        }

        return $this->words($retried) < $length ? $retried : $summary;
    }
}
