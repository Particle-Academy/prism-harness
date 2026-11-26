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

        // EVERYTHING below runs inside the framing: any failure, in a read
        // before the child starts as much as in the child, comes back as a
        // framed `failed` result carrying only the exception's class. A read
        // that fails therefore fails CLOSED: nothing has been written yet.
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
     * Whether a task holds only blank codepoints (G-387); see
     * {@see RunStarts::blank()}, the one set every language spells the same.
     */
    public static function blank(string $task): bool
    {
        return RunStarts::blank($task);
    }

    /**
     * The text the parent model is given for each refusal of the start gate:
     * harness-written, never a host's or a store's words.
     */
    private const REASONS = [
        'task_invalid' => 'the task is empty',
        'task_blank' => 'the task is empty',
        'too_deep' => 'subagent nesting reached the maximum depth of %d',
        'cancelled' => 'the run was cancelled',
        'time' => 'time budget exhausted',
        'steps' => 'step budget exhausted: no steps remain in the run tree for this subagent',
        'cost' => 'cost budget exhausted',
        'cost_unenforceable' => 'cost budget cannot be enforced for this subagent',
        'tool_calls' => 'tool call budget exhausted',
        'child_busy' => 'the subagent is already running',
        'child_waiting' => 'the subagent is waiting on a person\'s approval',
        'tree' => 'the run tree has nothing left for this subagent',
        'plan' => 'the plan budget has nothing left for this subagent',
    ];

    /**
     * One runner start (G-387 round 7): every fact the start depends on is
     * READ first, with nothing written (no session mode, no thread link, no
     * thread row, no count, binding, hold or run state); the start gate
     * ({@see RunStarts::gate()}) decides once; a refusal returns framed
     * having written nothing. Only a start the gate lets through commits,
     * in one order ({@see AgentRuntime::startChild()}), and once committed
     * a later stop only stops model steps.
     */
    private function delegate(
        Subagent $subagent,
        Session $parent,
        RunContext $context,
        string $parentRunId,
        mixed $task,
        ?AgentMode $parentMode,
        string $childRunId,
    ): SubagentResult {
        // The child runs in the agent the declaration names, or the parent's.
        // ModeRegistry already refused an agent the parent mode does not list.
        $childAgent = $subagent->agent ?? $parent->agent();
        // Marked as the runner's own, with the run it was started from: only
        // this context saves the child's binding, and the child is in that
        // run's tree only while it is live on this parent session and the
        // context is its own (G-381). The parent's thread is READ, never
        // created, by a start that may yet be refused.
        $childContext = app(RunTrees::class)->startingChild($context->forChild($subagent, $parentRunId, Thread::existingAt($parent->address())?->getKey())
            ->withBinding(ChildBinding::for(
                $subagent,
                $parent->provider(),
                $parent->model(),
                $parentMode ?? $this->modeOf($parent),
                sameAgent: $childAgent === $parent->agent(),
            )), $parentRunId, $parent->key());
        $child = $this->harness->forAgent($childAgent)->session(
            $parent->participant(), $subagent->scopeUnder($parent->scope()), $parent->actor(),
        );

        $facts = [
            ...$this->runtime->runnerStartFacts($child, $childContext, $context, $task, $subagent->mode),
            // A turn on the child is running and holds its count: starting it
            // again would reset that count under the running turn.
            'child_busy' => app(RunTrees::class)->delegationBusy($child),
            // A child waiting on a person's approval is the person's to
            // answer: a delegation into it would dismiss that approval.
            'child_pending' => $this->pendingOn($child),
        ];
        $verdict = RunStarts::gate($facts);
        $refused = $verdict['gate']['refused'] ?? (isset($verdict['gate']['start']) ? null : 'task_invalid');
        if ($refused !== null || ! is_string($task)) {
            $refused ??= 'task_invalid';

            return SubagentResult::refused($subagent->name, $childRunId, $parentRunId,
                SubagentOutcome::from(RunStarts::OUTCOMES[$refused] ?? 'denied'),
                sprintf(self::REASONS[$refused] ?? 'the subagent may not start', RunContext::MAX_DEPTH));
        }

        try {
            $response = $this->runtime->startChild($child, $task, $childContext, $subagent->mode, $parent, $context->rootRunId(), $facts['tree_left']);

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
                    self::REASONS['child_busy']);
            }

            // Not a failure of the child's work: the tree refused a hold that
            // raced the gate, or a stop landed after the start committed.
            // Reported as its own outcome so the parent does not retry. Every
            // RunNotPermitted message is harness-written.
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
     * Whether the child has an approval a person could still answer
     * (`answerable`: one whose run was not cancelled, on a child whose
     * decision would be accepted, its binding saved and its run tree not
     * cancelled), one nobody can answer (`unanswerable`, which does not hold
     * the child for ever), or none. Reads only.
     *
     * @return 'none'|'answerable'|'unanswerable'
     */
    private function pendingOn(Session $child): string
    {
        $thread = Thread::existingAt($child->address());
        if ($thread === null) {
            return 'none';
        }
        $pending = array_filter($thread->pendingApprovalIds(),
            static fn (string $id): bool => $child->approvalStatus($id) === 'pending');
        if ($pending === []) {
            return 'none';
        }
        try {
            // The checks a decision on it would be refused by.
            $this->runtime->assertDecisionsActionable($child, null);
            app(RunTrees::class)->assertDecisionsFunded($child, null);
        } catch (RunNotPermitted) {
            return 'unanswerable';
        }

        return 'answerable';
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
