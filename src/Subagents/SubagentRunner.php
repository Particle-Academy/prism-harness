<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Models\Thread;
use Prism\Harness\Modes\AgentMode;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRefusals;
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

    public function tool(Subagent $subagent, Session $parent, ?RunContext $context, string $parentRunId, ?AgentMode $parentMode = null): Tool
    {
        return (new Tool)
            ->as($subagent->name)
            ->for($subagent->description)
            ->withStringParameter('task', 'What the subagent should do, stated as a complete instruction. It does not see this conversation.')
            // `mixed`: a model may send any JSON value. A task that is not a
            // string is the runner's to refuse, framed, never a raw error.
            ->using(function (mixed $task = null) use ($subagent, $parent, $context, $parentRunId, $parentMode): string {
                if ($context === null) {
                    throw new \LogicException('Tool inspection cannot invoke a subagent.');
                }

                $result = $this->run($subagent, $parent, $context, $parentRunId, $task, $parentMode);

                // The tree refusing to let a child run is a gate, not the
                // child's failure: recorded so a run step reports it as one.
                return in_array($result->outcome, [SubagentOutcome::Exhausted, SubagentOutcome::Cancelled, SubagentOutcome::Denied], true)
                    ? ToolRefusals::record($parentRunId, $subagent->name, $result->toToolResult())
                    : $result->toToolResult();
            });
    }

    public function run(
        Subagent $subagent,
        Session $parent,
        RunContext $context,
        string $parentRunId,
        mixed $task,
        ?AgentMode $parentMode = null,
    ): SubagentResult {
        $childRunId = 'run_'.bin2hex(random_bytes(12));

        // A task with nothing in it is no instruction: refused before the
        // child is built or touched. Run, an empty task would read as a
        // resume of whatever the child is parked on. Blank is one explicit
        // codepoint set, spelled the same in every language (never the
        // language's own trim, which differs between them).
        if (! is_string($task) || self::blank($task)) {
            return SubagentResult::refused($subagent->name, $childRunId, $parentRunId, SubagentOutcome::Denied,
                'the task is empty');
        }

        // EVERYTHING below runs inside the framing: any failure, in a check
        // before the child starts as much as in the child, comes back as a
        // framed `failed` result carrying only the exception's class.
        try {
            return $this->delegate($subagent, $parent, $context, $parentRunId, $task, $parentMode, $childRunId);
        } catch (Throwable $e) {
            // ONLY THE CLASS NAME TRAVELS. A provider, store or database
            // exception's message can carry a URL, a host, SQL, headers or a
            // key, and whatever goes in `reason` is read by a model and may be
            // shown to a user. The full message goes to the log.
            report($e);

            return SubagentResult::failed(
                $subagent->name,
                $childRunId,
                $parentRunId,
                sprintf('The subagent failed with %s. The details are in the application log.', $e::class),
            );
        }
    }

    /**
     * Whether a task holds only blank codepoints (G-387): U+0009-U+000D,
     * U+0020, U+0085, U+00A0, U+1680, U+2000-U+200D, U+2028, U+2029,
     * U+202F, U+205F, U+2060, U+3000 and U+FEFF. The same set in all three
     * languages. Text that is not valid UTF-8 is not blank.
     */
    public static function blank(string $task): bool
    {
        return preg_match('/\A[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200D}\x{2028}\x{2029}\x{202F}\x{205F}\x{2060}\x{3000}\x{FEFF}]*\z/u', $task) === 1;
    }

    private function delegate(
        Subagent $subagent,
        Session $parent,
        RunContext $context,
        string $parentRunId,
        string $task,
        ?AgentMode $parentMode,
        string $childRunId,
    ): SubagentResult {
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

        // The child runs in the agent the declaration names, or the parent's.
        // ModeRegistry already refused an agent the parent mode does not list.
        $childAgent = $subagent->agent ?? $parent->agent();
        // Marked as the runner's own, with the run it was started from: only
        // this context saves the child's binding, and the child is in that
        // run's tree only while it is live on this parent session and the
        // context is its own (G-381).
        $childContext = app(RunTrees::class)->startingChild($context->forChild($subagent, $parentRunId, $parent->thread()->getKey())
            ->withBinding(ChildBinding::for(
                $subagent,
                $parent->provider(),
                $parent->model(),
                $parentMode ?? $this->modeOf($parent),
                sameAgent: $childAgent === $parent->agent(),
            )), $parentRunId, $parent->key());

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
        // answer reads to the parent like the work was done. In the ledger's
        // unit: the budget is a ceiling on the tree's running count, so what
        // the child may take is what that ceiling leaves above it (G-387).
        if ($childContext->budget->maxSteps - $context->ledger->steps() < 1) {
            return SubagentResult::refused(
                $subagent->name,
                $childRunId,
                $parentRunId,
                SubagentOutcome::Exhausted,
                'no steps remain in the run tree for this subagent',
            );
        }

        // A child waiting on a person's approval is the person's to answer:
        // a delegation into it would dismiss that approval. Refused before
        // anything is written to the child.
        $child = $this->harness->forAgent($childAgent)->session(
            $parent->participant(), $subagent->scopeUnder($parent->scope()), $parent->actor(),
        );
        if ($this->awaitsAPerson($child)) {
            return SubagentResult::refused($subagent->name, $childRunId, $parentRunId, SubagentOutcome::Denied,
                'the subagent is waiting on a person\'s approval');
        }
        // A turn on the child is running and holds its count: starting it
        // again would reset that count under the running turn.
        if (app(RunTrees::class)->delegationBusy($child)) {
            return SubagentResult::refused($subagent->name, $childRunId, $parentRunId, SubagentOutcome::Denied,
                'the subagent is already running');
        }

        try {
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
            if ($e->code() === 'child_busy') {
                return SubagentResult::refused($subagent->name, $childRunId, $parentRunId, SubagentOutcome::Denied,
                    'the subagent is already running');
            }

            // Not a failure of the child's work — the tree refused to let it
            // spend. Reported as its own outcome so the parent does not retry.
            return SubagentResult::refused(
                $subagent->name,
                $childRunId,
                $parentRunId,
                $context->ledger->cancelled() ? SubagentOutcome::Cancelled : SubagentOutcome::Exhausted,
                $e->getMessage(),
            );
        }
        // Any other exception is caught by run(), deliberately: here it would
        // abort the PARENT's run, losing everything it had done before
        // calling out, and the parent can decide what to do about a child
        // that broke.
    }

    /**
     * Whether the child has an approval a person could still answer: one
     * whose run was not cancelled, on a child whose decision would be
     * accepted (its binding saved, its run tree not cancelled). An approval
     * nobody can answer does not hold the child for ever.
     */
    private function awaitsAPerson(Session $child): bool
    {
        $pending = array_filter(Thread::existingAt($child->address())?->pendingApprovalIds() ?? [],
            static fn (string $id): bool => $child->approvalStatus($id) === 'pending');
        if ($pending === []) {
            return false;
        }
        try {
            // The checks a decision on it would be refused by.
            $this->runtime->assertDecisionsActionable($child, null);
            app(RunTrees::class)->assertDecisionsFunded($child, null);
        } catch (RunNotPermitted) {
            return false;
        }

        return true;
    }

    /**
     * The parent's mode, for a caller that did not hand it over.
     *
     * Only an UNCONFIGURED mode is tolerated, as "no parent-mode tier": a
     * direct caller may run a subagent beneath a session whose mode was never
     * configured. A configured but malformed mode still throws.
     */
    private function modeOf(Session $parent): ?AgentMode
    {
        $modes = app(ModeRegistry::class)->forAgent($parent->agent());
        $name = $parent->mode() ?? $modes->default();

        return in_array($name, $modes->names(), true) ? $modes->resolve($name) : null;
    }
}
