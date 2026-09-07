<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Prism\Harness\Contracts\ContextRecall;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Facades\Tool as ToolFactory;
use Prism\Prism\Tool;
use Throwable;

/**
 * Lets the agent look up detail that has left its context window.
 *
 * REGISTERED ONLY WHEN A {@see ContextRecall} IS BOUND, and that condition is
 * the important part of this class. A recall tool with nothing behind it
 * answers every question with "nothing found", and an agent reasonably reads
 * that as *the detail does not exist* rather than as *I cannot check* — a
 * stronger and more wrong conclusion than not having the tool at all. An
 * absent capability is honest; one that silently always fails is not.
 *
 * ## Why the agent needs this and not just a bigger window
 *
 * Measured on a real audit workload: with tool results cleared from context and
 * nothing able to hand them back, the agent noticed and REFUSED TO FINISH
 * rather than report totals it could no longer support — having already
 * asserted one from cleared evidence and caught itself by luck. Its own
 * proposed fix, offered unprompted, was to write "a ledger... so the counts
 * survive context clearing instead of depending on my memory."
 *
 * That is this tool. An agent that can look something up does not have to
 * choose between guessing and stopping.
 *
 * ## Budget
 *
 * Recall is bounded because compaction is the reason it exists. Handing back
 * everything relevant would re-expand precisely the window that was just
 * compacted, so the ceiling is applied by the implementation and the tool
 * describes it to the model — an agent that knows it gets a summary asks a
 * narrower question.
 */
final class ContextRecallTool
{
    public function __construct(
        private readonly ContextRecall $recall,
        private readonly int $budget = 1000,
    ) {}

    public function forSession(Session $session): Tool
    {
        // THE THREAD KEY, not the session key, and they are not the same
        // string: `Session::key()` is `session:<hash>:<id>:<scope>` while the
        // sink is handed the thread's primary key.
        //
        // This started out as `$session->key()` and the two halves therefore
        // searched different namespaces -- eviction wrote 448 rows and recall
        // found none of them, silently, because a lookup that matches nothing
        // is indistinguishable from a conversation with nothing to find. Unit
        // tests on either side passed; only a probe that evicted a fact and
        // asked for it back could see it.
        //
        // The thread is the conversation, so the thread is what both sides key
        // on.
        $scope = (string) $session->thread()->getKey();

        return ToolFactory::as('recall_context')
            ->for(
                'Look up detail from EARLIER in this conversation that is no longer '
                .'in your context. Use it when you need to check something you were '
                .'told or saw before, rather than relying on memory or repeating the '
                .'work. Returns a bounded extract, so ask a specific question.'
            )
            ->withStringParameter('query', 'What you are trying to find, in your own words')
            ->using(function (string $query) use ($scope): string {
                try {
                    $found = $this->recall->recall($query, $scope, $this->budget);
                } catch (Throwable $failure) {
                    report($failure);

                    // Reported to the MODEL as a failure to look, not as an
                    // absence. The distinction decides what it does next: an
                    // agent told "nothing found" proceeds as though the detail
                    // does not exist, and an agent told the lookup broke knows
                    // its own uncertainty is unresolved.
                    return 'The recall lookup failed. Treat this as unknown rather than as nothing found.';
                }

                return trim($found) === ''
                    ? 'Nothing relevant was found in the earlier part of this conversation.'
                    : $found;
            });
    }
}
