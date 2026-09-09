<?php

declare(strict_types=1);

namespace Prism\Harness\Context\Budget;

use Prism\Harness\Contracts\SummaryBudget;

/**
 * Take whatever the model returned. The budget is only ever a request.
 *
 * This is what the package did before v0.6.0, kept as a named choice rather
 * than deleted — "ask and accept" is a legitimate policy when a compacting turn
 * must cost exactly one model call, and pretending otherwise would be the
 * harness deciding for you.
 *
 * **Know what you are choosing.** Measured against a live model with nothing
 * checking: a stated 15-word budget came back at 92 words and at 346, and a
 * default of 60 came back at 205. The 346-word summary had re-stated every
 * exchange in the conversation one by one. Under this budget `summary_words` is
 * a hint the model is free to ignore, and nothing will tell you it did.
 */
final class AskOnly implements SummaryBudget
{
    #[\Override]
    public function apply(string $summary, int $limit, callable $rewrite): string
    {
        return $summary;
    }
}
