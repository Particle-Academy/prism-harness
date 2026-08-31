<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Tool;
use Throwable;

/**
 * Turns a declared {@see Subagent} into a tool the parent run can call.
 *
 * The parent is mid-run and holding its own session lock when this executes.
 * Everything here is arranged so that fact stays harmless:
 *
 *  - the child resolves its OWN session address, so it takes a different lock;
 *  - the child's authority comes from its declared mode, never the parent's;
 *  - the child's budget is drawn from the tree's remaining allowance;
 *  - every way the child can end returns a framed result rather than throwing,
 *    because the parent is a legitimate audience for "that did not work" and
 *    tearing down the parent run would discard work it had already done.
 */
final readonly class SubagentRunner
{
    public function __construct(
        private PrismHarness $harness,
        private AgentRuntime $runtime,
    ) {}

    public function tool(Subagent $subagent, Session $parent, RunContext $context, string $parentRunId): Tool
    {
        return (new Tool)
            ->as($subagent->name)
            ->for($subagent->description)
            ->withStringParameter('task', 'What the subagent should do, stated as a complete instruction. It does not see this conversation.')
            ->using(fn (string $task): string => $this
                ->run($subagent, $parent, $context, $parentRunId, $task)
                ->toToolResult());
    }

    public function run(
        Subagent $subagent,
        Session $parent,
        RunContext $context,
        string $parentRunId,
        string $task,
    ): SubagentResult {
        $childRunId = 'run_'.bin2hex(random_bytes(12));

        // Checked BEFORE spawning, so an exhausted tree does not pay for a
        // session, a thread row and a provider call to discover it is exhausted.
        $stop = $context->ledger->exhaustion($context->budget);
        if ($stop !== null) {
            return SubagentResult::refused(
                $subagent->name,
                $childRunId,
                $parentRunId,
                $context->ledger->cancelled() ? SubagentOutcome::Cancelled : SubagentOutcome::Exhausted,
                $stop,
            );
        }

        $childContext = $context->forChild($subagent, $parentRunId, $parent->thread()->getKey());

        // Refused BEFORE the child's address is built. Two modes that name each
        // other as subagents form a cycle that budgets would eventually stop,
        // but only after each level had appended `::sub::<name>` to a scope
        // column that truncates rather than errors — and two children truncated
        // to the same string are one conversation.
        if ($childContext->tooDeep()) {
            return SubagentResult::refused(
                $subagent->name,
                $childRunId,
                $parentRunId,
                SubagentOutcome::Denied,
                sprintf('subagent nesting reached the maximum depth of %d', RunContext::MAX_DEPTH),
            );
        }

        // A child that has been narrowed to nothing is refused rather than run:
        // a zero-step budget can only produce an empty answer, and an empty
        // answer reads to the parent like the work was done.
        if ($childContext->budget->maxSteps < 1) {
            return SubagentResult::refused(
                $subagent->name,
                $childRunId,
                $parentRunId,
                SubagentOutcome::Exhausted,
                'no steps remain in the run tree for this subagent',
            );
        }

        try {
            $child = $this->harness->session($parent->participant(), $subagent->scopeUnder($parent->scope()));
            $child->usingMode($subagent->mode);
            $child->thread()->linkBeneath($parent->thread(), $context->rootRunId());

            $response = $this->runtime->send($child, $task, null, $childContext);

            return new SubagentResult(
                subagent: $subagent->name,
                runId: $response->runId,
                parentRunId: $parentRunId,
                outcome: SubagentOutcome::Completed,
                content: $response->text(),
                usage: [
                    'steps' => $context->ledger->steps(),
                    'cost_usd' => $context->ledger->costUsd(),
                ],
            );
        } catch (RunNotPermitted $e) {
            // Not a failure of the child's work — the tree refused to let it
            // spend. Reported as its own outcome so the parent does not retry.
            return SubagentResult::refused(
                $subagent->name,
                $childRunId,
                $parentRunId,
                $context->ledger->cancelled() ? SubagentOutcome::Cancelled : SubagentOutcome::Exhausted,
                $e->getMessage(),
            );
        } catch (Throwable $e) {
            // Caught deliberately. An exception here would abort the PARENT's
            // run, losing everything it had done before calling out — and the
            // parent can reasonably decide what to do about a child that broke.
            //
            // ONLY THE CLASS NAME TRAVELS. A provider exception's message can
            // carry the request URL, headers, or a key embedded in either, and
            // whatever goes in `reason` is read by a model and may be shown to
            // a user. The full message goes to the log, where it is useful to
            // an operator and reaches nobody else.
            report($e);

            return SubagentResult::failed(
                $subagent->name,
                $childRunId,
                $parentRunId,
                sprintf('The subagent failed with %s. The details are in the application log.', $e::class),
            );
        }
    }
}
