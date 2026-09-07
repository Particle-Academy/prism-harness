<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

/**
 * Reaching back for detail that has left the context window.
 *
 * The other half of {@see EvictionSink}. A sink takes custody of what leaves;
 * this hands it back on demand, within a budget, so the agent can look
 * something up instead of either carrying it or losing it.
 *
 * ## Why on demand and not resident
 *
 * The whole point of compaction is that the window is finite. Recall that
 * returned everything relevant would re-expand exactly what was just compacted,
 * which is why `$budget` is not optional and not advisory: an implementation
 * that ignores it turns a bounded window back into an unbounded one and the
 * caller has no way to tell until the provider complains.
 *
 * ## Why an interface rather than a dependency
 *
 * The harness does not require `prism-memory`. An application that has it binds
 * an implementation that searches a chat-scoped collection by meaning; one that
 * keeps evicted turns in a table binds a `LIKE`; one that wants nothing binds
 * nothing.
 *
 * **Binding nothing is a supported answer and it means the tool is not offered
 * at all.** That is deliberate. A recall tool that is always empty is worse than
 * no recall tool: the agent asks, gets nothing, and concludes the detail does
 * not exist — which is a stronger and more wrong claim than not being able to
 * check. Silence about a capability beats a capability that silently lies.
 */
interface ContextRecall
{
    /**
     * Detail relevant to `$query` from this conversation's evicted history.
     *
     * Returns text for the model to read, or an empty string when there is
     * genuinely nothing — which the tool reports as "nothing found" rather than
     * as an error, because "I looked and there was nothing" is a useful answer
     * and an exception is not.
     *
     * MUST respect `$budget`. It is an estimated token ceiling on what is
     * returned, not a suggestion.
     *
     * @param  string  $scope  The conversation to search — the same value the
     *                         {@see EvictionSink} was given.
     * @param  int  $budget  Estimated token ceiling on the returned text.
     */
    public function recall(string $query, string $scope, int $budget): string;
}
