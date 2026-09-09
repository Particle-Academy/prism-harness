<?php

declare(strict_types=1);

namespace Prism\Harness\Context\Budget;

use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\Contracts\SummaryBudget;

/**
 * Cut the summary to the budget. The only shipped budget that guarantees it.
 *
 * ## Read this before choosing it
 *
 * **Truncation can hand the model a fragment that reads like a whole thought.**
 * A summary cut mid-clause — "the customer agreed to the refund provided" —
 * is not merely shorter, it can be WRONGER than the long version, because
 * nothing marks where the meaning stopped. That is the failure the eviction
 * layer exists to prevent, arriving through the thing meant to control cost.
 *
 * Two mitigations, and neither makes it safe:
 *
 *  - the cut prefers the last sentence boundary inside the budget, so a summary
 *    that has one loses whole sentences rather than half of one;
 *  - what is cut is marked with an ellipsis, so a later reader can see the
 *    summary is incomplete rather than inferring it was complete.
 *
 * **Bind an {@see EvictionSink} if you use this.** The
 * cut text is unrecoverable from the summary by construction; the sink is the
 * only reason it is recoverable at all.
 *
 * {@see RetryOnce} is the better default. Reach for this when a hard ceiling
 * matters more than the summary being whole.
 */
final class TruncateTo implements SummaryBudget
{
    use CountsWords;

    #[\Override]
    public function apply(string $summary, int $limit, callable $rewrite): string
    {
        if ($this->words($summary) <= $limit) {
            return $summary;
        }

        $words = preg_split('/\s+/u', trim($summary), -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return $summary;
        }

        $cut = trim(implode(' ', array_slice($words, 0, $limit)));

        // Prefer a sentence boundary INSIDE the budget. Losing a whole sentence
        // is a smaller lie than keeping half of one, and this is the difference
        // between "we agreed to refund" and "we agreed to refund provided".
        if (preg_match('/^(.*[.!?])(?:\s|$)/su', $cut, $whole) === 1 && trim($whole[1]) !== '') {
            return trim($whole[1]);
        }

        // No boundary to fall back on, so the cut is marked. A reader who can
        // see the summary was truncated can go to the sink; one who cannot will
        // read a fragment as a finished sentence.
        return $cut.' …';
    }
}
