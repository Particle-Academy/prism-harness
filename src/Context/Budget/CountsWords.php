<?php

declare(strict_types=1);

namespace Prism\Harness\Context\Budget;

use Prism\Harness\Contracts\SummaryBudget;

/**
 * Words, counted the way a reader would.
 *
 * Shared by the shipped budgets so they agree on what "sixty words" means. An
 * implementation of {@see SummaryBudget} is free not to
 * use it — counting tokens instead is a perfectly good reason to write your own.
 */
trait CountsWords
{
    /**
     * `str_word_count()` is deliberately not used. It is ASCII-minded: it drops
     * or splits on accented letters and non-Latin scripts, so a summary in
     * French or Japanese measures shorter than it is and sails through a budget
     * it broke. Splitting on whitespace over-counts nothing and under-counts
     * nothing that matters here.
     */
    protected function words(string $summary): int
    {
        $parts = preg_split('/\s+/u', trim($summary), -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? 0 : count($parts);
    }
}
