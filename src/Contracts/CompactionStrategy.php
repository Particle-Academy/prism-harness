<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Context\CompactionOutcome;
use Prism\Harness\Context\NoCompaction;
use Prism\Harness\Context\ToolPairGuard;
use Prism\Prism\Contracts\Message;

/**
 * How a conversation is shortened before it is replayed to the model.
 *
 * THE HARNESS DOES NOT DECIDE THIS FOR YOU, and that is the point of the
 * contract existing at all. There is no single correct answer: a support chat
 * can drop old turns freely, a long audit cannot drop the tool results its
 * final count depends on, and a coding agent wants the opposite of both. An
 * opinionated default baked into the harness would be a rule every project had
 * to work around rather than a foundation they could build on.
 *
 * So the harness provides the STRUCTURE — when compaction runs, what happens to
 * what it removes, and the invariants no strategy may break — and the strategy
 * provides the rule.
 *
 * ## What the harness guarantees regardless of your strategy
 *
 * **A returned transcript is always valid.** A tool call and its result are
 * kept or dropped together, whatever a strategy does to them. This is not
 * politeness: splitting them makes the provider reject the entire request
 * ("tool_use ids were found without tool_result blocks immediately after"),
 * and because it depends on where the cut happens to fall it fails
 * intermittently, several turns after the compaction that caused it. A strategy
 * author should not have to know that, and cannot violate it if they do not.
 *
 * See {@see ToolPairGuard}.
 *
 * ## What it does NOT guarantee, and what that costs
 *
 * Compaction can erase safety constraints. This is measured rather than
 * theoretical — "Governance Decay" (arXiv 2606.22528) finds summarisation-based
 * compaction producing safety violations above 40%, token truncation 25-30%,
 * and semantic compression 15-20%, because constraints stated early are
 * progressively lost with no failure signal. Their recommendation is to treat
 * constraints as immutable content held apart from compressible history, and to
 * validate that they survive.
 *
 * In this harness the system prompt is NOT part of the thread — it is applied
 * per run — so it cannot be compacted away, which removes the largest instance
 * of that failure. **Anything else your agent must not forget belongs in the
 * system prompt or in a tool it can call, not in a turn you are hoping survives.**
 *
 * ## Evicted messages are not deleted
 *
 * A strategy returns what it removed as well as what it kept, and the harness
 * hands the removed messages to an {@see EvictionSink}. That is what makes
 * compaction recoverable instead of lossy: the detail leaves the window and
 * stays reachable. Clearing without recovery is the configuration with the
 * worst properties available — the context is cheap and the agent can no longer
 * stand behind what it said.
 *
 * Nothing is deleted from storage either way. `Thread::messages()` compacts the
 * view; the rows remain.
 */
interface CompactionStrategy
{
    /**
     * Decide what this conversation looks like on the way to the model.
     *
     * Receives the whole conversation, oldest first, already materialised —
     * a strategy that needs to count tokens or look at the end cannot work
     * from a generator.
     *
     * Return everything to compact nothing. That is a legitimate answer and is
     * what {@see NoCompaction} does.
     *
     * @param  list<Message>  $messages
     */
    public function compact(array $messages): CompactionOutcome;
}
