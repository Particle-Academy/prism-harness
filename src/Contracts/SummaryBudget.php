<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Context\Budget\AskOnly;
use Prism\Harness\Context\Budget\RetryOnce;
use Prism\Harness\Context\Budget\TruncateTo;
use Prism\Harness\Context\SummarisingCompaction;

/**
 * What to do about a summary that came back longer than it was asked to be.
 *
 * ## Why this is yours to decide
 *
 * A word budget reaches the model as *"in at most N words"* inside a prompt.
 * That is a request, and a model does not have to grant it. Measured from a live
 * Lab probe before anything checked: a stated 15 words came back at 92 and at
 * 346, and a default of 60 came back at 205 — the 346 having re-stated every
 * exchange in the conversation, one by one.
 *
 * So something has to decide what happens next, and **the harness will not
 * decide it for you**, for the same reason it does not choose your
 * {@see CompactionStrategy}: the right answer depends on what the conversation
 * is worth and what a turn is allowed to cost, and only the application knows
 * both. A support console that must not spend twice on a turn wants a different
 * answer from an audit agent whose transcript is evidence.
 *
 * Two dials, deliberately separate:
 *
 *  - **how big** — `summary_words`, a number in config;
 *  - **how it is enforced** — this contract.
 *
 * Bind one and every summarising compaction uses it:
 *
 * ```php
 * $this->app->bind(SummaryBudget::class, fn () => new TruncateTo);
 * ```
 *
 * ## What ships
 *
 * {@see RetryOnce} is the default: count, and send
 * an over-budget summary back once with the overshoot quoted. Measured, that
 * took a 60-word budget from 205 words to 61 — and its worst case is still over,
 * because a retry is allowed to miss.
 *
 * {@see AskOnly} enforces nothing, which is what
 * this package did before v0.6.0. Honest, free, and the summary may be four
 * times the size you asked for.
 *
 * {@see TruncateTo} guarantees the bound by
 * cutting. Read its warning first — it is the only one that can hand the model a
 * fragment that reads like a whole thought.
 *
 * ## The one rule an implementation must not break
 *
 * **Never return an empty string.** A summary that is merely too long costs
 * tokens; no summary at all makes {@see SummarisingCompaction} keep the entire
 * conversation rather than evict turns with nothing standing in for them. If you
 * cannot improve it, hand back what you were given.
 */
interface SummaryBudget
{
    /**
     * Bring a summary within `$limit`, or decide it is close enough.
     *
     * `$rewrite` asks the summarising model again — pass it the prompt you want
     * sent, and it returns the reply, or null when the call failed or came back
     * empty. Calling it costs money on a path that already bills per compacting
     * turn, so an implementation that calls it in a loop should be one somebody
     * chose deliberately.
     *
     * @param  string  $summary  What the model produced. Never empty.
     * @param  int  $limit  The configured `summary_words`.
     * @param  callable(string): ?string  $rewrite  Ask again; null on failure.
     * @return string The summary to use. Never empty — return `$summary` if in doubt.
     */
    public function apply(string $summary, int $limit, callable $rewrite): string;
}
