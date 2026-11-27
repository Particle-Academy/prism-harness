<?php

declare(strict_types=1);

namespace Prism\Harness;

use Closure;
use Generator;
use Illuminate\Container\Container;
use Prism\Harness\Context\MemoryHooks;
use Prism\Harness\Context\SummarisingCompaction;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Events\FlowToolRefused;
use Prism\Harness\Events\HarnessEvent;
use Prism\Harness\Events\RunCancelled;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunResumed;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Events\RunStepped;
use Prism\Harness\Events\ToolApprovalAsked;
use Prism\Harness\Events\ToolApproved;
use Prism\Harness\Events\ToolAutoAllowed;
use Prism\Harness\Events\ToolDecision;
use Prism\Harness\Events\ToolDenied;
use Prism\Harness\Exceptions\PlanRefused;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\SessionLockLost;
use Prism\Harness\Exceptions\StructuredSchemaViolation;
use Prism\Harness\Exceptions\ToolNotAvailable;
use Prism\Harness\Flow\FlowToolset;
use Prism\Harness\Models\Thread;
use Prism\Harness\Modes\AgentMode;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Plans\PlanMachine;
use Prism\Harness\Plans\Plans;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Sessions\StartView;
use Prism\Harness\Skills\SkillRegistry;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Steps\RunStepTagger;
use Prism\Harness\Structured\SchemaCheck;
use Prism\Harness\Structured\StructuredTranscript;
use Prism\Harness\Subagents\BudgetedCalls;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\ChildBinding;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunBudgets;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunStarts;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\StepCalls;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Support\CallPairing;
use Prism\Harness\Support\ClassLabel;
use Prism\Harness\Support\RunSettles;
use Prism\Harness\Support\TurnAttachments;
use Prism\Harness\Tools\CancelGuardedTool;
use Prism\Harness\Tools\CanonicalJson;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolPolicy;
use Prism\Harness\Tools\ToolPolicySlot;
use Prism\Harness\Tools\ToolRefusals;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use RuntimeException;
use Throwable;
use WeakMap;

final readonly class AgentRuntime
{
    /** The run id on decisions made for a workflow node, which has no harness run. */
    private const FLOW_RUN = 'flow';

    /**
     * Where the host tool policy is kept when no container slot is bound (a
     * runtime built by hand). In an application it lives in the container's
     * SCOPED slot instead: see {@see self::policySlot()}.
     */
    private ToolPolicySlot $policySlot;

    /**
     * The result messages a runner start ended calls with (G-387), until
     * they are recorded: marked as ended in the thread, and announced as
     * denied only once durable. Each names the approval ids of the calls
     * it ended, which the thread's mark keeps.
     *
     * @var WeakMap<ToolResultMessage, list<string>>
     */
    private WeakMap $endedByStart;

    /**
     * What ends a runner start's own view and, when it failed before its
     * task row, puts its records back and returns its holds (G-387), by
     * run id. Called by whichever path ends the run, BEFORE that path
     * writes the run's end or emits its terminal event, so a listener never
     * observes (or races) a hold that is about to be released. Once only.
     */
    private RunSettles $startSettles;

    /**
     * The start views THIS runtime entered (G-387): the only ones that
     * confirm a start's records. A view a host sets with
     * Session::enterStart() itself is not in here, so it confirms nothing,
     * whatever the run slot says.
     *
     * @var WeakMap<StartView, true>
     */
    private WeakMap $enteredStarts;

    /** @param array<string, mixed> $config */
    public function __construct(
        private ModeRegistry $modes,
        private ToolRegistry $tools,
        private ToolAuthorizer $authorizer,
        private SkillRegistry $skills,
        private array $config,
        /**
         * Resolves the subagent runner, lazily.
         *
         * A Closure rather than the object because the dependency is circular:
         * the runner needs PrismHarness to resolve a child session, and
         * PrismHarness needs this runtime. Deferring to call time breaks the
         * cycle without either side holding a half-built collaborator.
         *
         * @var (Closure(): SubagentRunner)|null
         */
        private ?Closure $subagentRunner = null,
        /** @var array<string, mixed> */
        private array $pricing = [],
        /** @var (Closure(): ?CallCostObserver)|null */
        private ?Closure $costObserver = null,
        /** @var array<string, mixed> */
        private array $agents = [],
    ) {
        $this->policySlot = new ToolPolicySlot;
        $this->endedByStart = new WeakMap;
        $this->startSettles = new RunSettles;
        $this->enteredStarts = new WeakMap;
        foreach ($agents as $agent => $options) {
            SessionAddress::validateAgent($agent);
            if (! is_array($options)) {
                throw new \InvalidArgumentException('Agent settings must be a map.');
            }
            foreach (['provider', 'model'] as $key) {
                if (array_key_exists($key, $options) && $options[$key] !== null && (! is_string($options[$key]) || $options[$key] === '')) {
                    throw new \InvalidArgumentException('Agent provider/model defaults must be nonempty strings or null.');
                }
            }
            if (array_key_exists('spend_ceiling', $options) && $options['spend_ceiling'] !== null
                && (! (is_int($options['spend_ceiling']) || is_float($options['spend_ceiling']))
                    || ! is_finite((float) $options['spend_ceiling']) || $options['spend_ceiling'] <= 0)) {
                throw new \InvalidArgumentException('Agent spend_ceiling must be a positive finite number of USD or null.');
            }
            foreach (['max_steps_ceiling', 'max_output_tokens_per_call'] as $key) {
                if (array_key_exists($key, $options) && $options[$key] !== null && (! is_int($options[$key]) || $options[$key] < 1)) {
                    throw new \InvalidArgumentException('Agent step/output ceilings must be positive integers or null.');
                }
            }
            foreach (['lock_ttl' => 1, 'lock_wait' => 0] as $key => $minimum) {
                if (array_key_exists($key, $options) && (! is_int($options[$key]) || $options[$key] < $minimum)) {
                    throw new \InvalidArgumentException('Agent lock settings must be whole seconds with positive TTL and nonnegative wait.');
                }
            }
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent  media sent with the prompt; see TurnAttachments for what is refused
     */
    public function send(Session $session, string $prompt, ?array $toolNames = null, ?RunContext $context = null, array $additionalContent = []): AgentResponse
    {
        // Refused BEFORE the lock and before a run exists: a bad attachment is a
        // mistake in the call, and it should not cost a run row, events or budget.
        $attachments = TurnAttachments::admit($prompt, $additionalContent);
        $context = $this->executionContext($context);
        $runId = null;

        try {
            return $this->sendLocked($session, $prompt, $toolNames, $context, $attachments, $runId);
        } finally {
            if ($runId !== null) {
                ToolRefusals::forget($runId);
            }
        }
    }

    /**
     * Record approval decisions and continue the run, all under ONE hold of
     * the session's fenced lock.
     *
     * `$record` runs first inside the lock, before a run id exists or any
     * provider is called: it checks that every decision answers a pending
     * approval and writes them. A session another request holds refuses with
     * SessionLocked having written nothing, so the decision can be retried;
     * two racing decisions for one approval cannot both pass the check.
     *
     * @internal Called by Session::decide().
     *
     * @param  Closure(): void  $record
     */
    public function resumeDecided(Session $session, Closure $record, ?RunContext $context = null): AgentResponse
    {
        $context = $this->executionContext($context);
        $runId = null;

        try {
            // Resumed with an EMPTY prompt: the conversation already holds the
            // request, the decisions and everything before them.
            return $this->sendLocked($session, '', null, $context, [], $runId, $record);
        } finally {
            if ($runId !== null) {
                ToolRefusals::forget($runId);
            }
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     * @param  array{mode: string, parent: Session, root: string, tree_left: ?int, base: int, context_left: int}|null  $start  a runner start the start gate let through (G-387)
     */
    private function sendLocked(Session $session, string $prompt, ?array $toolNames, ?RunContext $context, array $attachments, ?string &$runId, ?Closure $beforeRun = null, ?array $start = null): AgentResponse
    {
        return $session->fencedLock(function (Session $session) use ($prompt, $toolNames, $context, $attachments, &$runId, $beforeRun, $start): AgentResponse {
            // Inside the lock and before a run exists: decisions are checked
            // and written here, so a refusal leaves no run behind it.
            if ($beforeRun !== null) {
                $beforeRun();
            }

            // The id comes FIRST, before anything that can throw.
            //
            // Mode resolution and provider config used to run ahead of it, so a
            // misconfigured mode threw before there was a run to fail: no
            // `failRun`, no RunFailed event, and an application watching the
            // stream saw a run it had started simply never end. A configuration
            // error is the most likely failure here and was the one least
            // visible.
            $runId = 'run_'.bin2hex(random_bytes(12));
            $undo = null;
            $delegation = null;

            try {
                // A runner start runs in the mode its declaration names; the
                // session's mode slot is written only once the start commits.
                $mode = $this->modes->forAgent($session->agent())->resolve($start['mode'] ?? $session->mode());
                // Which run tree this run belongs to: decided once, here (G-381).
                $membership = app(RunTrees::class)->begin($session, $runId, $context, $prompt);
                // And how many steps it may take, from facts this runtime holds (G-387).
                [$facts, $paused] = $this->ownFacts($session, $mode, $context, $prompt);
                if ($start !== null) {
                    // A runner start is held to what the gate READ (G-387):
                    // steps the context spent since count against it.
                    $facts['context_left'] = $start['context_left'];
                }
                [$provider, $model, $child, $save] = $this->resolveBinding($session, $mode, $context);
                if ($save) {
                    // A runner start the start gate let through (G-387): its
                    // tree hold, then the child's records in one locked write.
                    if ($start === null || $child === null) {
                        throw new \LogicException('A runner start begins only through the start gate.');
                    }
                    [$undo, $delegation] = $this->commitRunnerStart($session, $membership, $facts, $child, $start, $runId);
                    // From here until its run ends, the start's own run reads
                    // what (b) committed on this Session object (G-387); every
                    // other reader keeps the provisional rule. Only a view
                    // this runtime entered confirms anything.
                    $this->enteredStarts[$session->enterStart($runId)] = true;
                    // Whatever ends this run settles the start first (G-387).
                    // Registered after the view is entered, and settled by
                    // the catch below too, so nothing between commit (b) and
                    // the run can leave it unsettled.
                    $this->startSettles->put($runId, function (bool $failed) use (&$undo, $session, $runId): void {
                        // The start's own view ends first: what follows
                        // reads the child as every other reader does.
                        $session->leaveStart();
                        $pending = $undo;
                        $undo = null;
                        // The success path NEVER undoes a start (round 13):
                        // it completed, so its task row is recorded and the
                        // delegation stands. Nor does a failure once this
                        // runtime wrote the task row, which it knows in
                        // memory, before any store read.
                        if ($pending === null || ! $failed || $this->startSettles->recorded($runId)) {
                            return;
                        }
                        // A runner start that failed before its task row was
                        // recorded never happened. Its records are
                        // provisional, and with no task row every reader
                        // already sees them as absent; putting the child's
                        // records, its count and the thread link back, and
                        // returning the tree hold, is housekeeping, and its
                        // failure changes nothing. A store read that cannot
                        // say whether a row was recorded undoes nothing: the
                        // records stay unconfirmed, and read as absent.
                        try {
                            $recorded = Thread::existingAt($session->address())?->storedMessages()->where('run_id', $runId)->exists() ?? false;
                        } catch (Throwable $unknown) {
                            report(new RuntimeException('A subagent start could not be checked: '.ClassLabel::of($unknown).'.'));

                            return;
                        }
                        if ($recorded) {
                            return;
                        }
                        try {
                            $pending();
                        } catch (Throwable $undoFailure) {
                            report(new RuntimeException('A subagent start could not be undone: '.ClassLabel::of($undoFailure).'.'));
                        }
                    });
                }
            } catch (Throwable $failure) {
                // A start committed before this threw never ran: settled as
                // failed (its view ended, and undone) before RunFailed.
                $this->settleStart($runId);
                // NO `failRun` HERE. `beginRun` has not run yet, and finishing a
                // run the session never started trips its own state guard —
                // which then throws OVER the configuration error that actually
                // caused this, replacing a message naming the broken mode with
                // "run state changed while the run was active". The event is
                // the whole point of this block; there is no run state to
                // transition, because there is no run.
                event(new RunFailed(
                    sessionKey: $session->key(),
                    runId: $runId,
                    exception: ClassLabel::of($failure),
                    parentRunId: $context?->parentRunId,
                    rootRunId: $context?->rootRunId(),
                    harnessAgent: $session->agent(),
                ));

                throw $failure;
            }

            $resumedFrom = $this->resumedFrom($session, $prompt);
            $begun = false;
            try {
                // A cancelled run never resumes (G-285): refused before it begins.
                if ($session->resumesCancelledRun($prompt)) {
                    throw RunNotPermitted::cancelled();
                }
                if ($start !== null && $undo !== null) {
                    // Commit step (c) of a runner start: the thread link,
                    // then the run itself. The mode went with (b).
                    $undo = $this->linkStart($session, $start, $undo);
                }
                $session->beginRun($runId, $mode->name, $provider, $model, $context?->parentRunId, $context?->rootRunId() ?? $runId,
                    tree: $membership, provisional: $undo !== null);
                $begun = true;

                // A root run opens the tree's account; a child inherits it. Either
                // way there is exactly one ledger per tree from here down.
                $run = $this->atMemberDepth($membership, $context ?? RunContext::root($runId, new RunBudget(
                    maxSteps: $this->ceilingFor($session, $mode),
                    maxCostUsd: $this->floatConfig('max_cost_usd'),
                    maxSeconds: $this->nullableIntConfig('max_seconds'),
                    maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
                )));
                $run = $this->boundedAgentRun($session, $this->workflowNodeRun($session, $mode, $run));
                $this->continuePausedSteps($session, $prompt, $context, $run, $runId, $facts, $paused);
                $run = $this->planRun($session, $runId, $run, $context, $prompt, $membership, begun: true, facts: $facts,
                    readBase: $start['base'] ?? null, started: $delegation);
                $budget = $run->budget;
                $unwatch = $this->watchCancel($session, $runId, $run, $resumedFrom);
                try {
                    return $this->sendRun($session, $prompt, $toolNames, $attachments, $runId, $mode, $provider, $model, $run, $budget, $start !== null);
                } finally {
                    $unwatch();
                }
            } catch (Throwable $failure) {
                // Clean up FIRST, each step on its own, reading nothing of
                // the failure but its class (G-387): a runner start is
                // settled (its view ended; undone, holds returned, if it
                // never recorded its task row) BEFORE its run is failed and
                // RunFailed emitted. A run that began and was not ended by
                // the path that threw is failed here, with its RunFailed.
                $this->settleStart($runId);
                if ($begun) {
                    $this->closeRun($session, $runId, $context, $failure);
                }

                throw $failure;
            } finally {
                // Every failure exit settled the start above; what is left
                // here is the success path, which keeps it (round 13).
                $this->settleStart($runId, failed: false);
                $session->leaveStart();
            }
        }, ttlSeconds: $this->integerConfig('lock_ttl', 300, $session), waitSeconds: $this->integerConfig('lock_wait', 0, $session));
    }

    /** What a call the child was parked on reads when a runner start ends it (G-387). */
    private const ENDED_BY_START = 'Not run: the delegation this call was proposed under ended.';

    /**
     * The calls of the last tool-calling step in `$history` that have no
     * result, each ENDED with a fixed result and never run (G-387): what a
     * runner start records with its task row in place of acting on them. A
     * decision recorded on one is not acted on; core's resolver then finds
     * every call answered and runs nothing. Null when nothing is open.
     *
     * @param  list<Message>  $history
     */
    private function endedCalls(array $history): ?ToolResultMessage
    {
        $ended = self::endedCallsOf($history);
        if ($ended === null) {
            return null;
        }
        // Recorded marked as ended, naming the approvals of the calls it
        // ended, and announced only once it is durable.
        $this->endedByStart[$ended['message']] = $ended['approvals'];

        return $ended['message'];
    }

    /**
     * What {@see self::endedCalls()} ends, and the approvals the ended calls
     * were asked under, in call order. Pure: the cross-language thread-rows
     * corpus drives it, so every port writes the same row (G-387).
     *
     * @internal
     *
     * @param  list<Message>  $history
     * @return array{message: ToolResultMessage, approvals: list<string>}|null
     */
    public static function endedCallsOf(array $history): ?array
    {
        $index = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i] instanceof AssistantMessage && $history[$i]->toolCalls !== []) {
                $index = $i;

                break;
            }
        }
        if ($index === null) {
            return null;
        }
        /** @var AssistantMessage $assistant */
        $assistant = $history[$index];
        // By POSITION, never by call id (round 13): a call is answered only
        // by a result of its own, and a request names only its own call, so
        // a gated call that shares its id with an ungated one that ran is
        // still ended here, under its own approval.
        $calls = CallPairing::ofCalls($assistant->toolCalls);
        $rows = [];
        $ran = null;
        for ($i = $index + 1, $n = count($history); $i < $n; $i++) {
            if ($history[$i] instanceof ToolResultMessage) {
                if ($ran === null && $history[$i]->toolApprovalResponses !== []) {
                    $ran = CallPairing::answered($calls, $rows);
                }
                $rows[] = CallPairing::ofResults($history[$i]->toolResults);
            }
        }
        $answered = CallPairing::answered($calls, $rows);
        $requests = array_values($assistant->toolApprovalRequests);
        $paired = CallPairing::requests($calls, array_map(static fn ($request): string => $request->toolCallId, $requests), $ran ?? $answered);
        $ended = [];
        $approvals = [];
        foreach (array_values($assistant->toolCalls) as $at => $call) {
            if ($answered[$at]) {
                continue;
            }
            foreach ($requests as $k => $request) {
                if ($paired[$k] === $at) {
                    $approvals[] = $request->approvalId;
                }
            }
            $ended[] = new ToolResult(
                toolCallId: $call->id,
                toolName: $call->name,
                args: $call->argumentsForResult(),
                result: self::ENDED_BY_START,
                toolCallResultId: $call->resultId,
            );
        }
        if ($ended === []) {
            return null;
        }

        return ['message' => new ToolResultMessage($ended), 'approvals' => array_values(array_unique($approvals))];
    }

    /**
     * Settle the runner start `$runId` is, if it is one and was not settled
     * yet (G-387). `$failed` is false only on the success path, which never
     * undoes a start (round 13).
     */
    private function settleStart(string $runId, bool $failed = true): void
    {
        $settle = $this->startSettles->take($runId);
        if ($settle !== null) {
            $settle($failed);
        }
    }

    /**
     * Whether `$view` is a start view this runtime entered on a runner
     * start's own Session (G-387). A view a host made is not.
     *
     * @internal Read by Session for the start's own view.
     */
    public function enteredStart(StartView $view): bool
    {
        return isset($this->enteredStarts[$view]);
    }

    /**
     * End a run that began and that the path which threw did not end
     * (G-387): failed (or cancelled), with its RunFailed event, each step
     * on its own so one failing cannot skip the next. Reads nothing of the
     * failure but its class and, for the harness's own final refusal, its
     * code. A lost lock whose writes are rejected is left to its holder.
     */
    private function closeRun(Session $session, string $runId, ?RunContext $context, Throwable $failure): void
    {
        $this->settleStart($runId);
        if ($failure instanceof SessionLockLost && $failure->isWriteRejected()) {
            return;
        }
        try {
            if ($session->runStatus($runId) !== 'running') {
                return;
            }
        } catch (Throwable) {
            // Unknown: ended here, which an ended run refuses harmlessly.
        }
        $cancelled = $failure instanceof RunNotPermitted && $failure->code() === 'run_cancelled';
        try {
            if ($cancelled) {
                $session->cancelRun($runId);
            } else {
                $session->failRun($runId, $failure instanceof RunNotPermitted ? 'budget' : ClassLabel::of($failure));
            }
        } catch (Throwable $unwritten) {
            report(new RuntimeException('A failed run could not be closed: '.ClassLabel::of($unwritten).'.'));
            try {
                app(RunTrees::class)->finished($session, $runId);
            } catch (Throwable) {
                // Its holds are charged in full once they expire.
            }
        }
        if (! $cancelled) {
            event(new RunFailed(
                sessionKey: $session->key(),
                runId: $runId,
                exception: ClassLabel::of($failure),
                parentRunId: $context?->parentRunId,
                rootRunId: $context?->rootRunId() ?? $runId,
                harnessAgent: $session->agent(),
            ));
        }
    }

    /**
     * A run whose ONLY spent limit is its step budget, parked on calls the
     * person has decided and that have no result yet, still acts on those
     * decisions: it runs the approved calls and records every result, and the
     * step check after the resolution then stops it with no model step. The
     * decision was accepted, so it is acted on; refused here, it would be
     * consumed with nothing done, and every later resume refused the same way.
     */
    private function actsOnDecisionsFirst(Session $session, RunContext $run, RunBudget $budget, string $prompt): bool
    {
        // Only a resume acts on recorded decisions; a new message resumes none.
        return $prompt === '' && $run->ledger->onlyStepsSpent($budget) && $session->thread()->decidedCallsAwaitingResult();
    }

    /**
     * Refuse, BEFORE a decision is recorded, one the resuming turn could not
     * act on at all (G-387): run the checks that turn would run first. A
     * linked child that lost its binding, or a supplied context that is
     * cancelled or whose time or tool-call limit is spent, would refuse the
     * resume after the decision was written, consuming the approval with
     * nothing done. Steps and cost never refuse a decision: acting on it
     * makes no model call. A cancelled run tree is refused by RunTrees.
     *
     * @internal Called by the session before it records decisions.
     *
     * @throws RunNotPermitted
     */
    public function assertDecisionsActionable(Session $session, ?RunContext $context, bool $onlyDenials = false): void
    {
        $context = $this->executionContext($context);
        $route = ChildBinding::route(
            app(RunTrees::class)->bindingContextKind($context),
            $context?->binding !== null,
            $session->childBinding() !== null,
            $session->thread()->parent_thread_id !== null || $session->childTree() !== null || $session->childPlanLedger() !== null,
        );
        if ($route['binding'] === 'refused') {
            throw RunNotPermitted::unboundChild();
        }
        if ($context !== null) {
            // A denial runs nothing, so a spent tool-call or time limit does
            // not refuse it; a cancelled run still does.
            if ($onlyDenials) {
                if ($context->ledger->cancelRequested()) {
                    throw RunNotPermitted::cancelled();
                }

                return;
            }
            $stop = $context->ledger->resolutionStop($context->budget);
            if ($stop !== null) {
                throw $context->ledger->refusal($stop);
            }
        }
    }

    /**
     * Refuse, before any provider call and before anything is recorded, a
     * run with nothing it may spend and no recorded decision to act on: the
     * tree's allowance, not this node's wish. The same check, and the same
     * ending (failed on its budget, or cancelled), on send, structured and
     * the step stream (G-387), which used to end a spent run three different
     * ways. A capped run's own cost is admitted by its call reservation; its
     * step allowance is checked here like any other run's.
     */
    private function refuseSpentRun(Session $session, string $runId, RunContext $run, RunBudget $budget, string $prompt): void
    {
        $stop = $run->ledger->exhaustion($run->capped()
            ? new RunBudget($budget->maxSteps, maxSeconds: $budget->maxSeconds, maxToolCalls: $budget->maxToolCalls) : $budget);
        if ($stop !== null && ! $this->actsOnDecisionsFirst($session, $run, $budget, $prompt)) {
            $refusal = $run->ledger->refusal($stop);
            $this->endRun($session, $runId, $run, $refusal->code() === 'run_cancelled' ? 'cancelled' : 'budget');

            throw $refusal;
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     */
    private function sendRun(Session $session, string $prompt, ?array $toolNames, array $attachments, string $runId, AgentMode $mode, string $provider, string $model, RunContext $run, RunBudget $budget, bool $committedStart = false): AgentResponse
    {
        event(new RunStarted(
            sessionKey: $session->key(),
            runId: $runId,
            mode: $mode->name,
            provider: $provider,
            model: $model,
            parentRunId: $run->parentRunId,
            rootRunId: $run->rootRunId(),
            harnessAgent: $session->agent(),
        ));

        // A runner start was decided by the start gate and is committed: a
        // stop that landed since only stops its model steps (the loop's
        // gate), it never refuses the start (G-387).
        if (! $committedStart) {
            $this->refuseSpentRun($session, $runId, $run, $budget, $prompt);
        }

        try {
            $run = $this->observedRun($run);
            $this->assertMeteredCompaction($run);
            // The history is read HERE, once, and handed to Prism as
            // messages rather than as a Thread. See newMessages() for why
            // this run has to know exactly which message objects it sent.
            $history = iterator_to_array($session->thread()->messages(), false);
            $turn = $prompt === '' ? [] : [new UserMessage($prompt, $attachments)];
            if ($committedStart && $prompt !== '') {
                // A runner start acts on NO recorded decision (G-387): the
                // calls the child was parked on are ended with its task row,
                // never run, so a decision recorded before it (one a bare
                // resume would refuse) is never acted on by a delegation.
                // Read from the rows as stored, unfolded: a fold keys results
                // by call id, which cannot tell same-id calls apart.
                $ended = $this->endedCalls($session->thread()->storedHistory());
                if ($ended !== null) {
                    $turn[] = $ended;
                }
            }

            $generation = Prism::text()
                ->using($provider, $model)
                ->withMessages([...$history, ...$turn])
                ->withTelemetryMetadata(sessionId: $session->key());

            if ($mode->providerOptions !== []) {
                $generation->withProviderOptions($mode->providerOptions);
            }

            $systemPrompt = app(MemoryHooks::class)->systemPrompt($this->skills->augmentPrompt($mode->systemPrompt, $mode->skills), $session, $prompt);
            if ($systemPrompt !== '') {
                $generation->withSystemPrompt($systemPrompt);
            }

            $tools = $this->authorizedTools($mode, $session, $toolNames, $run, $runId);
            if ($tools !== []) {
                $generation->withTools($tools)->withMaxSteps(! $run->capped() ? $this->requestSteps($session, $run) : 1);
            }
            if ($budget->maxOutputTokensPerCall !== null) {
                $generation->withMaxTokens($budget->maxOutputTokensPerCall);
            }
            // ONE MODEL STEP PER PROVIDER REQUEST, always (G-285). The run's
            // cancel flag is read before every step and before every tool, so
            // a Stop from another process lands at the next step rather than
            // after the whole run. Core's own multi-step loop has no point
            // between steps where it could be read.
            $response = ! $run->capped()
                ? (new StepCalls)->text($generation, $run,
                    function (TextResponse $response) use ($session, &$history, $runId, $tools): void {
                        $this->recordTurn($session, $this->newMessages($response, $history), $runId, $tools);
                    }, function (array $messages) use ($session, &$history, $runId, $tools): void {
                        $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                        $history = $messages;
                    })
                : $this->budgetedCalls($run, $provider, $model)->text($generation, $run,
                    function (TextResponse $response) use ($session, &$history, $runId, $tools): void {
                        $this->recordTurn($session, $this->newMessages($response, $history), $runId, $tools);
                    }, function (array $messages) use ($session, &$history, $runId, $tools): void {
                        $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                        $history = $messages;
                    });
            $failed = in_array($response->finishReason, [FinishReason::Error, FinishReason::Unknown], true);
            if ($failed) {
                $this->settleStart($runId);
                $session->failRun($runId, $response->finishReason->value);
            } else {
                $session->completeRun($runId, $response->finishReason->value, $this->toolCallNames($response));
            }

            // Every step was charged before its provider call; an uncapped
            // run's reported cost is recorded once after the run.
            if (! $run->capped()) {
                $run->ledger->recordCost($this->costOf($response));
            }

            $agentResponse = new AgentResponse(
                runId: $runId,
                response: $response,
                parentRunId: $run->parentRunId,
                rootRunId: $run->rootRunId(),
            );

            $cancelled = $this->announceEnd($session, $runId, $run, $failed
                ? new RunFailed($session->key(), $runId, $response->finishReason->value,
                    $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent())
                : new RunFinished(
                    sessionKey: $session->key(),
                    runId: $runId,
                    finishReason: $response->finishReason->value,
                    steps: count($response->steps),
                    // Surfaced on the event, not left for a listener to infer
                    // from the finish reason: a paused run is what an
                    // application has to put in front of a person, and it is
                    // the one end state that needs someone to act.
                    awaitingApproval: $agentResponse->awaitingApproval(),
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                    harnessAgent: $session->agent(),
                ));
        } catch (Throwable $failure) {
            if ($failure instanceof SessionLockLost && $failure->isWriteRejected()) {
                throw $failure;
            }
            // A committed start that a stop kept from its first model step
            // still records the task it was given: the delegation happened,
            // and the calls the child was parked on end with it (G-387).
            // Guarded: the run is failed below whatever this does.
            try {
                if ($committedStart && $prompt !== '' && $failure instanceof RunNotPermitted
                    && ! $session->thread()->storedMessages()->where('run_id', $runId)->exists()) {
                    $ended = $this->endedCalls($session->thread()->storedHistory());
                    $this->recordTurn($session, [new UserMessage($prompt, $attachments), ...($ended === null ? [] : [$ended])], $runId, []);
                }
            } catch (Throwable $unrecorded) {
                report(new RuntimeException('A subagent task could not be recorded: '.ClassLabel::of($unrecorded).'.'));
            }
            $this->failRunOn($session, $runId, $run, $failure);

            throw $failure;
        }

        // A cancel that landed after the last step still ends the run
        // cancelled (G-285); its answer is kept in the thread.
        if ($cancelled) {
            throw RunNotPermitted::cancelled();
        }

        return $agentResponse;
    }

    /**
     * A turn whose answer is a document.
     *
     * The same run as {@see self::send()} — same mode, same tools, same budget,
     * same events, same lock — asking the provider for structured output and
     * checking what comes back against the schema before the caller sees it.
     *
     * WHAT THE THREAD KEEPS IS THE TEXT. The assistant message is the document
     * exactly as the model wrote it, with the parsed object attached beside it
     * as `structured` metadata. Never instead of it: a later turn replays this
     * conversation as messages, and a transcript that reads differently because
     * of the SHAPE of the request that produced it is the same defect as one
     * that reads differently when streamed — which {@see self::stream()} exists
     * to avoid. The metadata is stored and replayed; no provider map reads that
     * key, so nothing is sent back out with it.
     *
     * A FAILED DOCUMENT IS STILL RECORDED, and then raised. The exchange
     * happened: the model was asked and answered, and a thread that omits the
     * answer it did not like is a thread that cannot explain the retry sitting
     * next to it. The run is marked failed, `RunFailed` fires, and the caller
     * gets {@see StructuredSchemaViolation} carrying the document.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent  media sent with the prompt; see TurnAttachments for what is refused
     *
     * @throws StructuredSchemaViolation when the model returns no document, or one the schema refuses
     */
    public function sendStructured(Session $session, string $prompt, Schema $schema, ?array $toolNames = null, ?RunContext $context = null, array $additionalContent = []): StructuredAgentResponse
    {
        $attachments = TurnAttachments::admit($prompt, $additionalContent);
        $context = $this->executionContext($context);
        $runId = null;

        try {
            return $this->sendStructuredLocked($session, $prompt, $schema, $toolNames, $context, $attachments, $runId);
        } finally {
            if ($runId !== null) {
                ToolRefusals::forget($runId);
            }
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     */
    private function sendStructuredLocked(Session $session, string $prompt, Schema $schema, ?array $toolNames, ?RunContext $context, array $attachments, ?string &$runId): StructuredAgentResponse
    {
        return $session->fencedLock(function (Session $session) use ($prompt, $schema, $toolNames, $context, $attachments, &$runId): StructuredAgentResponse {
            $runId = 'run_'.bin2hex(random_bytes(12));

            try {
                $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());
                // Which run tree this run belongs to: decided once, here (G-381).
                $membership = app(RunTrees::class)->begin($session, $runId, $context, $prompt);
                // And how many steps it may take, from facts this runtime holds (G-387).
                [$facts, $paused] = $this->ownFacts($session, $mode, $context, $prompt);
                [$provider, $model] = $this->bindingFor($session, $mode, $context, $membership, $facts);
            } catch (Throwable $failure) {
                // As in send(): no run exists yet, so there is no run state to
                // fail — only an event to fire.
                event(new RunFailed(
                    sessionKey: $session->key(),
                    runId: $runId,
                    exception: ClassLabel::of($failure),
                    parentRunId: $context?->parentRunId,
                    rootRunId: $context?->rootRunId(),
                    harnessAgent: $session->agent(),
                ));

                throw $failure;
            }

            // A cancelled run never resumes (G-285): refused before it begins.
            if ($session->resumesCancelledRun($prompt)) {
                throw RunNotPermitted::cancelled();
            }
            $resumedFrom = $this->resumedFrom($session, $prompt);
            $session->beginRun($runId, $mode->name, $provider, $model, $context?->parentRunId, $context?->rootRunId() ?? $runId,
                tree: $membership);

            // From here the run exists: ANY throw (a RunStarted listener's
            // included, an Error as much as an Exception) fails it, by class
            // name only, with its RunFailed, unless the path that threw
            // already ended it (G-387).
            try {
                $run = $this->atMemberDepth($membership, $context ?? RunContext::root($runId, new RunBudget(
                    maxSteps: $this->ceilingFor($session, $mode),
                    maxCostUsd: $this->floatConfig('max_cost_usd'),
                    maxSeconds: $this->nullableIntConfig('max_seconds'),
                    maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
                )));
                $run = $this->boundedAgentRun($session, $this->workflowNodeRun($session, $mode, $run));
                $this->continuePausedSteps($session, $prompt, $context, $run, $runId, $facts, $paused);
                $run = $this->planRun($session, $runId, $run, $context, $prompt, $membership, begun: true, facts: $facts);
                $unwatch = $this->watchCancel($session, $runId, $run, $resumedFrom);
                try {
                    return $this->sendStructuredRun($session, $prompt, $schema, $toolNames, $attachments, $runId, $mode, $provider, $model, $run);
                } finally {
                    $unwatch();
                }
            } catch (Throwable $failure) {
                $this->closeRun($session, $runId, $context, $failure);

                throw $failure;
            }
        }, ttlSeconds: $this->integerConfig('lock_ttl', 300, $session), waitSeconds: $this->integerConfig('lock_wait', 0, $session));
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     */
    private function sendStructuredRun(Session $session, string $prompt, Schema $schema, ?array $toolNames, array $attachments, string $runId, AgentMode $mode, string $provider, string $model, RunContext $run): StructuredAgentResponse
    {
        $budget = $run->budget;

        event(new RunStarted(
            sessionKey: $session->key(),
            runId: $runId,
            mode: $mode->name,
            provider: $provider,
            model: $model,
            parentRunId: $run->parentRunId,
            rootRunId: $run->rootRunId(),
            harnessAgent: $session->agent(),
        ));

        $this->refuseSpentRun($session, $runId, $run, $budget, $prompt);

        try {
            $run = $this->observedRun($run);
            $this->assertMeteredCompaction($run);
            $history = iterator_to_array($session->thread()->messages(), false);
            $userMessage = $prompt === '' ? null : new UserMessage($prompt, $attachments);

            $generation = Prism::structured()
                ->using($provider, $model)
                ->withSchema($schema)
                ->withMessages($userMessage instanceof UserMessage ? [...$history, $userMessage] : $history)
                ->withTelemetryMetadata(sessionId: $session->key());

            if ($mode->providerOptions !== []) {
                $generation->withProviderOptions($mode->providerOptions);
            }

            $systemPrompt = app(MemoryHooks::class)->systemPrompt($this->skills->augmentPrompt($mode->systemPrompt, $mode->skills), $session, $prompt);
            if ($systemPrompt !== '') {
                $generation->withSystemPrompt($systemPrompt);
            }

            $tools = $this->authorizedTools($mode, $session, $toolNames, $run, $runId);
            if ($tools !== []) {
                $generation->withTools($tools)->withMaxSteps(! $run->capped() ? $this->requestSteps($session, $run) : 1);
            }
            if ($budget->maxOutputTokensPerCall !== null) {
                $generation->withMaxTokens($budget->maxOutputTokensPerCall);
            }
            // One model step per provider request, so the cancel flag is read
            // between steps (G-285); see send().
            $response = ! $run->capped()
                ? (new StepCalls)->structured($generation, $run,
                    function (StructuredResponse $response) use ($session, &$userMessage, $runId, $tools): void {
                        $this->recordTurn($session, StructuredTranscript::of($response, $userMessage), $runId, $tools);
                    }, function (array $messages) use ($session, &$history, &$userMessage, $runId, $tools): void {
                        $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                        $history = $messages;
                        $userMessage = null;
                    })
                : $this->budgetedCalls($run, $provider, $model)->structured($generation, $run,
                    function (StructuredResponse $response) use ($session, &$userMessage, $runId, $tools): void {
                        $this->recordTurn($session, StructuredTranscript::of($response, $userMessage), $runId, $tools);
                    }, function (array $messages) use ($session, &$history, &$userMessage, $runId, $tools): void {
                        $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                        $history = $messages;
                        $userMessage = null;
                    });

            // A turn that stopped for a person has no document yet: it is
            // returned with its pending approvals, never checked against the
            // schema (round 13). Retrying a turn read as a failure would
            // discard what the person was asked about.
            $parked = (new StructuredAgentResponse($runId, $response))->awaitingApproval();
            $violation = $parked ? null : $this->violationIn($schema, $response);

            if ($violation instanceof StructuredSchemaViolation) {
                throw $violation;
            }

            $failed = in_array($response->finishReason, [FinishReason::Error, FinishReason::Unknown], true);
            if ($failed) {
                $session->failRun($runId, $response->finishReason->value);
            } else {
                $session->completeRun($runId, $response->finishReason->value, $this->structuredToolCallNames($response));
            }

            if (! $run->capped()) {
                $run->ledger->recordCost($response->usage->cost);
            }

            $cancelled = $this->announceEnd($session, $runId, $run, $failed
                ? new RunFailed($session->key(), $runId, $response->finishReason->value,
                    $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent())
                : new RunFinished(
                    sessionKey: $session->key(),
                    runId: $runId,
                    finishReason: $response->finishReason->value,
                    steps: count($response->steps),
                    // A structured run returns a document, raises, or stops
                    // for a person with no document yet.
                    awaitingApproval: $parked,
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                    harnessAgent: $session->agent(),
                ));
        } catch (Throwable $failure) {
            if ($failure instanceof SessionLockLost && $failure->isWriteRejected()) {
                throw $failure;
            }
            $this->failRunOn($session, $runId, $run, $failure);

            throw $failure;
        }

        if ($cancelled) {
            throw RunNotPermitted::cancelled();
        }

        return new StructuredAgentResponse(
            runId: $runId,
            response: $response,
            parentRunId: $run->parentRunId,
            rootRunId: $run->rootRunId(),
        );
    }

    /**
     * What is wrong with this document, or null when nothing is.
     *
     * Prism leaves `structured` null when the text held no readable document,
     * so the two failures are told apart here rather than collapsed into one
     * message a caller has to read prose out of.
     */
    private function violationIn(Schema $schema, StructuredResponse $response): ?StructuredSchemaViolation
    {
        if ($response->structured === null) {
            return StructuredSchemaViolation::unreadable($response->text);
        }

        $problems = SchemaCheck::problems($schema, $response->structured);

        return $problems === []
            ? null
            : StructuredSchemaViolation::failsSchema($response->text, $problems);
    }

    /**
     * The tools a structured run invoked, in order.
     *
     * @return list<string>
     */
    private function structuredToolCallNames(StructuredResponse $response): array
    {
        return array_map(fn (ToolCall $call): string => $call->name, $response->toolCalls);
    }

    /**
     * The same run, delivered as it happens.
     *
     * Yields Prism's stream events UNTOUCHED, so an application already
     * rendering thinking, tool calls and results keeps exactly the payloads it
     * renders today. What this adds is the durable half: the turn is recorded
     * and the run is closed out when the stream ends.
     *
     * THE LOCK IS HELD FOR THE WHOLE ITERATION, which is the awkward part of
     * streaming through a durable session and is why the `finally` matters. A
     * consumer that abandons the generator — a disconnected browser, an
     * exception upstream — would otherwise leave the run open and the session
     * locked until the TTL expired. PHP runs a generator's `finally` when it is
     * destroyed, so the run is closed on that path too, recorded with whatever
     * the stream had produced by then rather than discarded.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent  media sent with the prompt; see TurnAttachments for what is refused
     * @return Generator<int, StreamEvent>
     */
    public function stream(Session $session, string $prompt, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null): Generator
    {
        yield from $this->taggedStream($session, $prompt, $toolNames, $additionalContent, $this->executionContext($context),
            new RunStepTagger($context?->turnId, buffered: false));
    }

    /**
     * The same run as {@see self::stream()}, delivered as tagged {@see RunStep}s.
     *
     * The run is identical — the same paths, the same recording, the same
     * events, including a {@see RunStepped} per step — and what the caller
     * receives is the step stream instead of core's events. A core event with
     * no step mapping is still observed by the run; it is just not forwarded.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent
     * @return Generator<int, RunStep>
     */
    public function streamSteps(Session $session, string $prompt, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null): Generator
    {
        $tagger = new RunStepTagger($context?->turnId, buffered: true);
        $unwatch = $this->watchPlan($session, $tagger);
        try {
            foreach ($this->taggedStream($session, $prompt, $toolNames, $additionalContent, $this->executionContext($context), $tagger) as $event) {
                foreach ($tagger->drain() as $step) {
                    yield $step;
                }
            }
            foreach ($tagger->drain() as $step) {
                yield $step;
            }
        } finally {
            $unwatch();
        }
    }

    /**
     * Mix `plan_updated` steps into this stream when the host turned them on
     * (G-360, contract g282.3). Off by default: the stream then stays g282.1.
     *
     * @return Closure(): void the unwatch
     */
    private function watchPlan(Session $session, RunStepTagger $tagger, bool $written = false): Closure
    {
        $plans = app(Plans::class);
        if (! $plans->enabledFor($session) || ! $plans->limits()->runSteps) {
            return static function (): void {};
        }
        $tagger->plans(fn (): ?array => $plans->load($session), $written);

        return $plans->watch($session->key(), fn (array $plan) => $tagger->planUpdated($plan));
    }

    /**
     * Continue a run parked for approval, as tagged steps (G-284).
     *
     * The session lock is taken first; then `$record` writes the decisions,
     * through the same code as decide(), and reports where the parked run's
     * sequence ended. Only then are they reported and the run resumed on the
     * step path, which resolves
     * approvals from the thread. A dropped stream has lost nothing, and a
     * second decision for the same approval is refused before it is recorded,
     * because that approval is no longer pending (G-354).
     *
     * The resumed run keeps the parked run's id when the session still knows
     * it, so `seq` continues from where that run's stream ended.
     *
     * @internal Called by Session::streamDecision().
     *
     * @param  list<ToolApprovalResponse>  $decisions
     * @param  Closure(): array{0: ?string, 1: int, 2?: list<ToolApprovalResponse>}  $record  runs inside the lock
     * @return Generator<int, RunStep>
     */
    public function streamDecisionSteps(Session $session, array $decisions, Closure $record, ?RunContext $context): Generator
    {
        // The lock FIRST, before the decisions are written or any provider is
        // called. A held session refuses here, having changed nothing.
        $release = $session->holdFencedLock($this->integerConfig('lock_ttl', 300, $session), $this->integerConfig('lock_wait', 0, $session));
        try {
            // Iterated rather than delegated with `yield from`, so an abandoned
            // stream destroys the run (closing it out under the fence) BEFORE
            // the lock is released.
            foreach ($this->resumeLocked($session, $decisions, $record, $context) as $step) {
                yield $step;
            }
        } finally {
            $release();
        }
    }

    /**
     * @param  list<ToolApprovalResponse>  $decisions
     * @param  Closure(): array{0: ?string, 1: int, 2?: list<ToolApprovalResponse>}  $record
     * @return Generator<int, RunStep>
     */
    private function resumeLocked(Session $session, array $decisions, Closure $record, ?RunContext $context): Generator
    {
        // A plan the decisions wrote is noted (G-360): this stream opens with
        // it even when the decision ended it, or the reader would never learn
        // that a draft was rejected.
        $decidedPlan = false;
        $capture = app(Plans::class)->watch($session->key(), function () use (&$decidedPlan): void {
            $decidedPlan = true;
        });
        try {
            $recorded = $record();
            [$parkedRunId, $lastSeq] = $recorded;
            // The decisions as RECORDED (round 13): on a step whose call ids
            // repeat every one is a denial, and the stream says so.
            $decisions = $recorded[2] ?? $decisions;
        } finally {
            $capture();
        }
        $tagger = new RunStepTagger($context?->turnId, buffered: true, seq: $lastSeq, resumeRunId: $parkedRunId);
        $unwatch = $this->watchPlan($session, $tagger, $decidedPlan);
        try {
            yield from $this->resumeTagged($session, $decisions, $context, $tagger);
        } finally {
            $unwatch();
        }
    }

    /**
     * @param  list<ToolApprovalResponse>  $decisions
     * @return Generator<int, RunStep>
     */
    private function resumeTagged(Session $session, array $decisions, ?RunContext $context, RunStepTagger $tagger): Generator
    {
        $actor = $context?->actor;
        $context = $this->executionContext($context);
        $runId = $tagger->claimRunId();
        $tagger->bind($session->key(), $runId, $context?->parentRunId, $context?->rootRunId(), $session->agent());
        foreach ($decisions as $decision) {
            // From the APP's run context, never from the model or the request.
            $tagger->decision($decision->approvalId, $decision->approved, $decision->reason, $actor);
        }
        foreach ($tagger->drain() as $step) {
            yield $step;
        }

        // The step path, always. It resolves approvals from the thread before
        // any provider call and records the merge first, and it takes an empty
        // prompt as "continue" rather than as a turn to record.
        $capped = $this->takesPricedPath($session, $context);
        $events = $capped
            ? $this->streamCapped($session, '', null, $context, [], $tagger)
            : $this->streamStepTree($session, '', null, $context, [], $tagger);
        foreach ($events as $event) {
            foreach ($tagger->drain() as $step) {
                yield $step;
            }
        }
        foreach ($tagger->drain() as $step) {
            yield $step;
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent
     * @return Generator<int, StreamEvent>
     */
    private function taggedStream(Session $session, string $prompt, ?array $toolNames, array $additionalContent, ?RunContext $context, RunStepTagger $tagger): Generator
    {
        // First, before a run begins. A generator runs nothing until it is
        // iterated, so this refuses on the first iteration rather than at the
        // call, which is still before any run state exists.
        $attachments = TurnAttachments::admit($prompt, $additionalContent);

        // THE SESSION LOCK, held for the whole iteration — every path below
        // (plain, step tree, capped) runs inside it, as send() runs inside
        // fencedLock(). Taken before any run state or provider call, so a
        // session another request is running refuses with SessionLocked and
        // nothing else happens.
        $release = $session->holdFencedLock($this->integerConfig('lock_ttl', 300, $session), $this->integerConfig('lock_wait', 0, $session));
        try {
            // Iterated rather than delegated with `yield from`: when the
            // consumer abandons the stream, the loop's iterator is freed before
            // this `finally` runs, so the run is closed out — its state written
            // under the fence — BEFORE the lock is released. Keys pass through.
            foreach ($this->lockedStream($session, $prompt, $toolNames, $context, $attachments, $tagger) as $key => $event) {
                yield $key => $event;
            }
        } finally {
            $release();
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     * @return Generator<int, StreamEvent>
     */
    private function lockedStream(Session $session, string $prompt, ?array $toolNames, ?RunContext $context, array $attachments, RunStepTagger $tagger): Generator
    {

        if ($this->takesPricedPath($session, $context, $prompt)) {
            yield from $this->streamCapped($session, $prompt, $toolNames, $context, $attachments, $tagger);

            return;
        }

        if ($this->observerFor($context) !== null) {
            throw RunNotPermitted::callBudget('budget_observer_uncapped');
        }

        // The step path, always (G-285): it reads the run's cancel flag before
        // every model step and every tool call, which core's own multi-step
        // stream has no point to do.
        yield from $this->streamStepTree($session, $prompt, $toolNames, $context, $attachments, $tagger);
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     * @return Generator<StreamEvent>
     */
    private function streamStepTree(Session $session, string $prompt, ?array $toolNames, ?RunContext $context, array $attachments, RunStepTagger $tagger): Generator
    {
        $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());
        $runId = $tagger->claimRunId();
        // Which run tree this run belongs to: decided once, here (G-381).
        $membership = app(RunTrees::class)->begin($session, $runId, $context, $prompt);
        // And how many steps it may take, from facts this runtime holds (G-387).
        [$facts, $paused] = $this->ownFacts($session, $mode, $context, $prompt);
        [$provider, $model] = $this->bindingFor($session, $mode, $context, $membership, $facts);
        $run = $this->atMemberDepth($membership, $context ?? RunContext::root($runId, new RunBudget(
            maxSteps: $this->ceilingFor($session, $mode),
            maxSeconds: $this->nullableIntConfig('max_seconds'),
            maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
        )));
        $run = $this->boundedAgentRun($session, $run);
        // A cancelled run never resumes (G-285): refused before it begins.
        if ($session->resumesCancelledRun($prompt)) {
            throw RunNotPermitted::cancelled();
        }
        $this->continuePausedSteps($session, $prompt, $context, $run, $runId, $facts, $paused);
        $run = $this->planRun($session, $runId, $run, $context, $prompt, $membership, begun: false, facts: $facts);
        $tagger->bind($session->key(), $runId, $run->parentRunId, $run->rootRunId(), $session->agent());
        $tagger->history(fn (): iterable => $session->thread()->messages());
        $resumedFrom = $this->resumedFrom($session, $prompt);
        $session->beginRun($runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId(), $tagger->turnId(),
            $membership);
        $unwatch = $this->watchCancel($session, $runId, $run, $resumedFrom);
        // A parked run continued under its own id has already STARTED once;
        // a second RunStarted for it would read as a new run.
        $this->announceStart($session, $runId, $run, $tagger->resuming()
            ? new RunResumed($session->key(), $runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent())
            : new RunStarted($session->key(), $runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent()),
            function () use ($unwatch, $session, $runId, $tagger): void {
                $unwatch();
                $this->rememberSeq($session, $runId, $tagger);
                ToolRefusals::forget($runId);
            });
        try {
            $this->refuseSpentRun($session, $runId, $run, $run->budget, $prompt);
        } catch (Throwable $refused) {
            $unwatch();
            $this->rememberSeq($session, $runId, $tagger);
            ToolRefusals::forget($runId);

            throw $refused;
        }
        $recorded = false;
        $finish = FinishReason::Unknown;
        $steps = 0;
        $awaitingApproval = false;
        $reportedCost = null;
        $completedCall = false;
        try {
            $run = $this->observedRun($run);
            $history = iterator_to_array($session->thread()->messages(), false);
            $generation = Prism::text()->using($provider, $model)
                ->withMessages($prompt === '' ? $history : [...$history, new UserMessage($prompt, $attachments)])
                ->withTelemetryMetadata(sessionId: $session->key());
            if ($mode->providerOptions !== []) {
                $generation->withProviderOptions($mode->providerOptions);
            }
            $systemPrompt = app(MemoryHooks::class)->systemPrompt($this->skills->augmentPrompt($mode->systemPrompt, $mode->skills), $session, $prompt);
            if ($systemPrompt !== '') {
                $generation->withSystemPrompt($systemPrompt);
            }
            $tools = $this->authorizedTools($mode, $session, $toolNames, $run, $runId);
            $tagger->offered($this->toolNames($tools));
            if ($tools !== []) {
                $generation->withTools($tools);
            }
            if ($run->budget->maxOutputTokensPerCall !== null) {
                $generation->withMaxTokens($run->budget->maxOutputTokensPerCall);
            }
            yield from $this->observed($tagger, (new StepCalls)->stream($generation, $run,
                function (array $messages, TextResponse $response) use ($session, $prompt, $attachments, $runId, $tools, &$recorded, &$finish, &$steps, &$awaitingApproval, &$reportedCost, &$completedCall): void {
                    $this->recordTurn($session, [
                        ...(! $recorded && $prompt !== '' ? [new UserMessage($prompt, $attachments)] : []), ...$messages,
                    ], $runId, $tools);
                    $recorded = true;
                    $completedCall = true;
                    if (($cost = $this->costOf($response)) !== null) {
                        $reportedCost = ($reportedCost ?? 0.0) + $cost;
                    }
                    $finish = $response->finishReason;
                    $steps++;
                    foreach ($messages as $message) {
                        if ($message instanceof AssistantMessage && $message->toolApprovalRequests !== []) {
                            $awaitingApproval = true;
                        }
                    }
                }, function (array $messages) use ($session, &$history, $runId, $tools, &$recorded): void {
                    $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                    $history = $messages;
                    $recorded = true;
                }));
        } catch (Throwable $failure) {
            $this->failRunOn($session, $runId, $run, $failure);

            throw $failure;
        } finally {
            $unwatch();
            if ($completedCall) {
                $run->ledger->recordCost($reportedCost);
            }
            if (($session->run()['status'] ?? null) === 'running') {
                if (! $recorded && $prompt !== '') {
                    $session->thread()->record([new UserMessage($prompt, $attachments)], $runId);
                }
                if (in_array($finish, [FinishReason::Error, FinishReason::Unknown], true)) {
                    $session->failRun($runId, $finish->value);
                    $this->announceEnd($session, $runId, $run, new RunFailed($session->key(), $runId, $finish->value, $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent()));
                } else {
                    $session->completeRun($runId, $finish->value);
                    $this->announceEnd($session, $runId, $run, new RunFinished($session->key(), $runId, $finish->value, $steps, $awaitingApproval,
                        $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent()));
                }
            }
            $this->rememberSeq($session, $runId, $tagger);
            ToolRefusals::forget($runId);
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     * @return Generator<int, StreamEvent>
     */
    private function streamCapped(Session $session, string $prompt, ?array $toolNames, ?RunContext $context, array $attachments, RunStepTagger $tagger): Generator
    {
        $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());
        $runId = $tagger->claimRunId();
        // Which run tree this run belongs to: decided once, here (G-381).
        $membership = app(RunTrees::class)->begin($session, $runId, $context, $prompt);
        // And how many steps it may take, from facts this runtime holds (G-387).
        [$facts, $paused] = $this->ownFacts($session, $mode, $context, $prompt);
        [$provider, $model] = $this->bindingFor($session, $mode, $context, $membership, $facts);
        $run = $this->atMemberDepth($membership, $context ?? RunContext::root($runId, new RunBudget(
            maxSteps: $this->ceilingFor($session, $mode),
            maxCostUsd: $this->floatConfig('max_cost_usd'),
            maxSeconds: $this->nullableIntConfig('max_seconds'),
            maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
        )));
        $run = $this->boundedAgentRun($session, $run);
        // A cancelled run never resumes (G-285): refused before it begins.
        if ($session->resumesCancelledRun($prompt)) {
            throw RunNotPermitted::cancelled();
        }
        $this->continuePausedSteps($session, $prompt, $context, $run, $runId, $facts, $paused);
        $run = $this->planRun($session, $runId, $run, $context, $prompt, $membership, begun: false, facts: $facts);
        $tagger->bind($session->key(), $runId, $run->parentRunId, $run->rootRunId(), $session->agent());
        $tagger->history(fn (): iterable => $session->thread()->messages());
        $resumedFrom = $this->resumedFrom($session, $prompt);
        $session->beginRun($runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId(), $tagger->turnId(),
            $membership);
        $unwatch = $this->watchCancel($session, $runId, $run, $resumedFrom);
        // A parked run continued under its own id has already STARTED once;
        // a second RunStarted for it would read as a new run.
        $this->announceStart($session, $runId, $run, $tagger->resuming()
            ? new RunResumed($session->key(), $runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent())
            : new RunStarted($session->key(), $runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent()),
            function () use ($unwatch, $session, $runId, $tagger): void {
                $unwatch();
                $this->rememberSeq($session, $runId, $tagger);
                ToolRefusals::forget($runId);
            });
        // The same refusal as every other path, before anything is recorded:
        // no user row, and no result closing an approval still pending.
        try {
            $this->refuseSpentRun($session, $runId, $run, $run->budget, $prompt);
        } catch (Throwable $refused) {
            $unwatch();
            $this->rememberSeq($session, $runId, $tagger);
            ToolRefusals::forget($runId);

            throw $refused;
        }
        $recorded = false;
        $finish = FinishReason::Unknown;
        $steps = 0;
        $awaitingApproval = false;
        try {
            $run = $this->observedRun($run);
            $this->assertMeteredCompaction($run);
            $history = iterator_to_array($session->thread()->messages(), false);
            $generation = Prism::text()->using($provider, $model)
                ->withMessages($prompt === '' ? $history : [...$history, new UserMessage($prompt, $attachments)])
                ->withTelemetryMetadata(sessionId: $session->key());
            if ($mode->providerOptions !== []) {
                $generation->withProviderOptions($mode->providerOptions);
            }
            $systemPrompt = app(MemoryHooks::class)->systemPrompt($this->skills->augmentPrompt($mode->systemPrompt, $mode->skills), $session, $prompt);
            if ($systemPrompt !== '') {
                $generation->withSystemPrompt($systemPrompt);
            }
            $tools = $this->authorizedTools($mode, $session, $toolNames, $run, $runId);
            $tagger->offered($this->toolNames($tools));
            if ($tools !== []) {
                $generation->withTools($tools);
            }
            yield from $this->observed($tagger, $this->budgetedCalls($run, $provider, $model)->stream($generation, $run,
                function (array $messages, TextResponse $response) use ($session, $prompt, $attachments, $runId, $tools, &$recorded, &$finish, &$steps, &$awaitingApproval): void {
                    $this->recordTurn($session, [
                        ...(! $recorded && $prompt !== '' ? [new UserMessage($prompt, $attachments)] : []), ...$messages,
                    ], $runId, $tools);
                    $recorded = true;
                    $finish = $response->finishReason;
                    $steps++;
                    foreach ($messages as $message) {
                        if ($message instanceof AssistantMessage && $message->toolApprovalRequests !== []) {
                            $awaitingApproval = true;
                        }
                    }
                }, function (array $messages) use ($session, &$history, $runId, $tools, &$recorded): void {
                    $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                    $history = $messages;
                    $recorded = true;
                }));
        } catch (Throwable $failure) {
            $this->failRunOn($session, $runId, $run, $failure);

            throw $failure;
        } finally {
            $unwatch();
            if (($session->run()['status'] ?? null) === 'running') {
                if (! $recorded && $prompt !== '') {
                    $session->thread()->record([new UserMessage($prompt, $attachments)], $runId);
                }
                if (in_array($finish, [FinishReason::Error, FinishReason::Unknown], true)) {
                    $session->failRun($runId, $finish->value);
                    $this->announceEnd($session, $runId, $run, new RunFailed($session->key(), $runId, $finish->value, $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent()));
                } else {
                    $session->completeRun($runId, $finish->value);
                    $this->announceEnd($session, $runId, $run, new RunFinished($session->key(), $runId, $finish->value, $steps, $awaitingApproval,
                        $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent()));
                }
            }
            $this->rememberSeq($session, $runId, $tagger);
            ToolRefusals::forget($runId);
        }
    }

    /**
     * The context a run EXECUTES under.
     *
     * A context carrying only a turnId and/or an actor is metadata: the run
     * takes exactly the path, and the budget, it would take with no context at
     * all. Only a context that states a run tree changes how a run executes.
     */
    private function executionContext(?RunContext $context): ?RunContext
    {
        return $context?->metadataOnly === true ? null : $context;
    }

    /**
     * The parked run an empty-prompt turn continues, read before the new run
     * begins (G-285): a cancel written for it while this turn waited on the
     * session lock must stop this turn too. Null for a new prompt.
     */
    private function resumedFrom(Session $session, string $prompt): ?string
    {
        $id = $prompt === '' ? ($session->run()['id'] ?? null) : null;

        return is_string($id) ? $id : null;
    }

    /**
     * Watch this run's durable cancel flag on its tree's ledger (G-285), for
     * as long as the run executes in this process. Returns the unwatch.
     *
     * @return Closure(): void
     */
    private function watchCancel(Session $session, string $runId, RunContext $run, ?string $resumedFrom = null): Closure
    {
        return $run->ledger->watchCancel(static fn (): bool => $session->cancelRequestedFor($runId)
            || ($resumedFrom !== null && $resumedFrom !== $runId && $session->cancelRequestedFor($resumedFrom)));
    }

    /**
     * End a run refused before it called anything: cancelled, with its event,
     * or failed on its budget, which has never announced itself.
     */
    private function endRun(Session $session, string $runId, RunContext $run, string $why): void
    {
        if ($why === 'cancelled') {
            $session->cancelRun($runId);
            $this->announceEnd($session, $runId, $run, null);

            return;
        }
        $session->failRun($runId, $why);
        if ($session->runWasCancelled($runId)) {
            $this->announceEnd($session, $runId, $run, null);
        }
    }

    /**
     * End a run that threw: cancelled when a cancel stopped it, failed
     * otherwise, and announced either way.
     */
    private function failRunOn(Session $session, string $runId, RunContext $run, Throwable $failure): void
    {
        // A runner start is settled before its end is written or announced.
        $this->settleStart($runId);
        if ($failure instanceof RunNotPermitted && $failure->code() === 'run_cancelled') {
            $session->cancelRun($runId);
        } else {
            $session->failRun($runId, $failure instanceof RunNotPermitted ? 'budget' : ClassLabel::of($failure));
        }
        $this->announceEnd($session, $runId, $run, new RunFailed($session->key(), $runId, ClassLabel::of($failure),
            $run->parentRunId, $run->rootRunId(), harnessAgent: $session->agent()));
    }

    /**
     * Fire a streamed run's start event (G-387). The run already began, so a
     * listener that throws (an Error as much as an Exception) fails it, by
     * class name only, with its RunFailed, after `$cleanup`; the throw then
     * propagates. A run is never left `running` by its own start event.
     *
     * @param  Closure(): void  $cleanup
     */
    private function announceStart(Session $session, string $runId, RunContext $run, HarnessEvent $event, Closure $cleanup): void
    {
        try {
            event($event);
        } catch (Throwable $failure) {
            try {
                $cleanup();
            } finally {
                $this->closeRun($session, $runId, $run, $failure);
            }

            throw $failure;
        }
    }

    /**
     * Fire the run's terminal event — or {@see RunCancelled} INSTEAD of it,
     * when the run ended cancelled (G-285). Returns whether it was cancelled.
     */
    private function announceEnd(Session $session, string $runId, RunContext $run, ?HarnessEvent $event): bool
    {
        if (! $session->runWasCancelled($runId)) {
            if ($event !== null) {
                event($event);
            }

            return false;
        }
        $turnId = $session->run()['turn_id'] ?? null;
        event(new RunCancelled($session->key(), $runId, $run->parentRunId, $run->rootRunId(), $session->agent(),
            is_string($turnId) ? $turnId : null));

        return true;
    }

    /**
     * Pass core's events through UNCHANGED, keys included, observing each.
     *
     * @param  iterable<array-key, StreamEvent>  $events
     * @return Generator<array-key, StreamEvent>
     */
    private function observed(RunStepTagger $tagger, iterable $events): Generator
    {
        foreach ($events as $key => $event) {
            $tagger->observe($event);
            yield $key => $event;
        }
    }

    /**
     * @param  list<Tool>  $tools
     * @return list<string>
     */
    private function toolNames(array $tools): array
    {
        return array_map(fn (Tool $tool): string => $tool->name(), $tools);
    }

    /**
     * Where this run's step sequence ended, so a resumed run continues it.
     *
     * Last, and isolated: the run is already closed out, and failing to note
     * a sequence number must not turn a finished run into a failed one.
     */
    private function rememberSeq(Session $session, string $runId, RunStepTagger $tagger): void
    {
        if ($tagger->seq() === 0) {
            return;
        }
        try {
            $session->rememberStepSeq($runId, $tagger->seq());
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    private function budgetedCalls(RunContext $run, string $provider, string $model): BudgetedCalls
    {
        return new BudgetedCalls(CallBudget::forRun($run, $provider, $model, $this->pricing));
    }

    private function observerFor(?RunContext $run): ?CallCostObserver
    {
        return $run->observer ?? $run?->ledger->inheritCostObserver(null)
            ?? ($this->costObserver === null ? null : ($this->costObserver)());
    }

    private function observedRun(RunContext $run): RunContext
    {
        $observer = $this->observerFor($run);
        if ($observer === null) {
            return $run;
        }
        if (! $run->capped()) {
            throw RunNotPermitted::callBudget('budget_observer_uncapped');
        }

        return $run->observer !== null ? $run : $run->withBudget($run->budget, $observer);
    }

    private function assertMeteredCompaction(RunContext $run): void
    {
        if ($run->capped() && app(CompactionStrategy::class) instanceof SummarisingCompaction) {
            throw RunNotPermitted::callBudget('budget_compaction_unmetered');
        }
    }

    /**
     * The tools this run may actually offer the model, resolved and authorized.
     *
     * One method because every turn shape needs the same four steps in the same
     * order, and a second copy of them is how a text turn and a structured turn
     * end up with different authority for the same mode.
     *
     * @return list<array{name: string, owner: string, source: string}>
     */
    public function effectiveTools(Session $session, ?string $modeName = null): array
    {
        $mode = $this->modes->forAgent($session->agent())->resolve($modeName ?? $session->mode());

        return $this->ownedOffer($mode, $session, null, inspection: true)['descriptors'];
    }

    /**
     * What a workflow may offer the model for this session: each tool's name,
     * description and schema, with nothing that can execute.
     *
     * @internal Read through {@see FlowToolset}.
     *
     * @param  list<string>|null  $toolNames  narrows the mode, and cannot widen it
     * @return list<Tool>
     */
    public function flowOffer(Session $session, ?array $toolNames = null): array
    {
        return array_map(static function (array $entry): Tool {
            $tool = $entry['tool'];
            $copy = (new Tool)->as($tool->name())->for($tool->description())->clientExecuted();
            foreach ($tool->parameters() as $name => $schema) {
                $copy->withParameter($schema, in_array($name, $tool->requiredParameters(), true));
            }
            $options = $tool->providerOptions();
            if (is_array($options)) {
                $copy->withProviderOptions($options);
            }

            return $copy;
        }, array_values($this->flowEntries($session, $toolNames)));
    }

    /**
     * Decide one model step as a whole, before any of its calls is handed over.
     *
     * @internal Called through {@see FlowToolset}.
     *
     * @param  list<string>|null  $toolNames
     * @param  list<string>  $offered  what this request offered the model
     * @param  list<array{name: string, id: string, arguments: array<array-key, mixed>|null, callIds?: list<string>}>  $calls
     *
     * @throws ToolNotAvailable when any call in the step may not run
     */
    public function admitFlowCalls(Session $session, ?array $toolNames, array $offered, array $calls): void
    {
        $entries = $this->flowEntries($session, $toolNames);
        $ids = [];
        $admitted = [];

        foreach ($calls as $call) {
            $name = $call['name'];
            // Only an id the provider issued can repeat. A call without one is
            // distinct, and every check below still applies to it.
            foreach ($call['callIds'] ?? [] as $callId) {
                if (isset($ids[$callId])) {
                    throw ToolNotAvailable::repeatedCall($name);
                }
            }
            foreach ($call['callIds'] ?? [] as $callId) {
                $ids[$callId] = true;
            }
            if (! in_array($name, $offered, true) || ! isset($entries[$name])) {
                throw ToolNotAvailable::named($name, $offered);
            }
            if ($call['arguments'] === null) {
                throw ToolNotAvailable::unreadableArguments($name);
            }
            $admitted[] = [$entries[$name], $call['arguments']];
        }

        $refusal = null;
        foreach ($admitted as [$entry, $arguments]) {
            $refused = $this->flowRefusal($session, $entry, $arguments);
            $refusal ??= $refused;
        }

        if ($refusal instanceof ToolNotAvailable) {
            throw $refusal;
        }
    }

    /**
     * Run one workflow tool call, deciding it again at the moment it runs.
     *
     * @internal Called through {@see FlowToolset}.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $arguments
     * @param  (callable(): void)|null  $starting  called once the call is allowed, just before its handler
     *
     * @throws ToolNotAvailable when the call may not run
     */
    public function runFlowTool(Session $session, ?array $toolNames, string $name, array $arguments, ?callable $starting = null): mixed
    {
        // The decision and the handler both run inside the session lock.
        return $this->flowLocked(
            $session,
            fn (Session $session): mixed => $this->runFlowToolLocked($session, $toolNames, $name, $arguments, $starting),
            static fn (SessionLocked $locked): ToolNotAvailable => ToolNotAvailable::sessionInUse($name, $locked),
        );
    }

    /**
     * Run a workflow's use of the session inside the lock an ordinary turn
     * holds, with the same timeout. While another holder has it the callback
     * does not run and the refusal is thrown instead; nothing waits beyond the
     * configured lock_wait. The lock is released on every exit.
     *
     * @internal Called by {@see runFlowTool()} and {@see PrismLlmClient}.
     *
     * @template TReturn
     *
     * @param  Closure(Session): TReturn  $callback
     * @param  Closure(SessionLocked): ToolNotAvailable  $refusal
     * @return TReturn
     */
    public function flowLocked(Session $session, Closure $callback, Closure $refusal): mixed
    {
        $entered = false;

        try {
            return $session->fencedLock(function (Session $session) use ($callback, &$entered): mixed {
                $entered = true;

                return $callback($session);
            }, ttlSeconds: $this->integerConfig('lock_ttl', 300, $session), waitSeconds: $this->integerConfig('lock_wait', 0, $session));
        } catch (SessionLocked $locked) {
            if ($entered) {
                // Thrown from inside the callback, not by taking this lock.
                throw $locked;
            }

            throw $refusal($locked);
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $arguments
     * @param  (callable(): void)|null  $starting
     */
    private function runFlowToolLocked(Session $session, ?array $toolNames, string $name, array $arguments, ?callable $starting): mixed
    {
        $entries = $this->flowEntries($session, $toolNames);

        if (! isset($entries[$name])) {
            throw ToolNotAvailable::named($name, array_keys($entries));
        }

        $refusal = $this->flowRefusal($session, $entries[$name], $arguments);

        if ($refusal instanceof ToolNotAvailable) {
            throw $refusal;
        }

        if ($entries[$name]['gated']) {
            $this->emitToolDecision(ToolAutoAllowed::class, $session, self::FLOW_RUN, $entries[$name]['tool'], $name, $arguments, 'policy');
        }

        if ($starting !== null) {
            $starting();
        }

        return $entries[$name]['tool']->handle(...$arguments);
    }

    /**
     * The most model steps one workflow loop may take on this session.
     *
     * @internal Called through {@see FlowToolset}.
     */
    public function flowStepLimit(Session $session): int
    {
        $session->forgetCachedState();

        return $this->ceilingFor($session, $this->modes->forAgent($session->agent())->resolve($session->mode()));
    }

    /**
     * The tools a workflow may run right now, read fresh from the session's
     * store. Subagents are left out: a subagent call spends from a run budget,
     * and a workflow node has no run to charge.
     *
     * @param  list<string>|null  $toolNames
     * @return array<string, array{tool: Tool, gated: bool}>
     */
    private function flowEntries(Session $session, ?array $toolNames): array
    {
        $session->forgetCachedState();
        $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());

        [$resolved, $offered] = $session->resolveReadOnly($mode->name, function (Session $view) use ($mode, $toolNames): array {
            $wantsSkills = $toolNames === null || in_array('skill_read', $toolNames, true);
            $names = $this->toolNamesFor($mode, $toolNames);
            $additional = [];
            if ($view->agent() === null && $mode->skills !== [] && $wantsSkills) {
                $names[] = 'skill_read';
            }
            if ($view->agent() !== null && $wantsSkills && in_array('skill_read', $mode->tools, true)) {
                $additional['skill_read'] = ['tool' => $this->skills->readerTool($mode->skills), 'owner' => 'harness', 'source' => 'builtin'];
            }
            $subagents = array_map(static fn ($subagent): string => $subagent->name, array_values($mode->subagents));
            $detailed = $this->tools->resolveDetailed(array_values(array_unique(array_diff($names, $subagents))), $view, $additional);
            $resolved = [];
            foreach (array_diff_key($detailed, array_flip($subagents)) as $name => $entry) {
                $resolved[$name] = $entry['tool'];
            }
            if ($view->agent() !== null && isset($resolved['complete_task'])) {
                $authorizer = $this->authorizer->forSession($view);
                if (! $authorizer->enabled() || ! $authorizer->hasCallPolicy()) {
                    throw new \LogicException('Named task completion requires enabled authorization and an explicit call policy.');
                }
            }

            return [$resolved, $this->authorizer->allowed($view, $resolved)];
        });

        $entries = [];
        foreach ($this->approvalScopedOffer($session, $offered) as $tool) {
            $name = $tool->name();
            $entries[$name] = ['tool' => $resolved[$name], 'gated' => $mode->needsApproval($name)];
        }

        return $entries;
    }

    /**
     * Why this call may not run, or null. Records each refusal.
     *
     * @param  array{tool: Tool, gated: bool}  $entry
     * @param  array<array-key, mixed>  $arguments
     */
    private function flowRefusal(Session $session, array $entry, array $arguments): ?ToolNotAvailable
    {
        $tool = $entry['tool'];

        foreach (array_keys($arguments) as $key) {
            if (is_int($key) || preg_match('/\A[+-]?[0-9]+\z/', $key) === 1) {
                return $this->recordFlowRefusal($session, $tool, $arguments, 'arguments', ToolNotAvailable::positionalArguments($tool->name()));
            }
        }

        try {
            if ($tool->needsApproval($arguments)) {
                return $this->recordFlowRefusal($session, $tool, $arguments, 'approval', ToolNotAvailable::awaitingApproval($tool->name()));
            }
            if (! $this->authorizer->allowsCall($session, $tool, $arguments)) {
                return $this->recordFlowRefusal($session, $tool, $arguments, 'authorization', ToolNotAvailable::refusedByPolicy($tool->name()));
            }
            // Read last, so a change made while the checks above ran still counts.
            if ($entry['gated'] && ! $this->toolPolicy()->allows($this->tools->declarationOf($tool))) {
                return $this->recordFlowRefusal($session, $tool, $arguments, 'approval', ToolNotAvailable::awaitingApproval($tool->name()));
            }
        } catch (Throwable $failure) {
            return $this->recordFlowRefusal($session, $tool, $arguments, 'check_failed', ToolNotAvailable::failed($tool->name(), $failure));
        }

        return null;
    }

    /** @param array<array-key, mixed> $arguments */
    private function recordFlowRefusal(Session $session, Tool $tool, array $arguments, string $reason, ToolNotAvailable $refusal): ToolNotAvailable
    {
        $this->emitToolDecision(ToolDenied::class, $session, self::FLOW_RUN, $tool, $tool->name(), $arguments, 'policy', $reason);
        // Deprecated, still emitted for listeners written against 0.26.
        event(new FlowToolRefused($session->key(), self::FLOW_RUN, $tool->name(), $reason));

        return $refusal;
    }

    /**
     * @param  list<string>|null  $requested
     * @return array{tools: list<Tool>, descriptors: list<array{name: string, owner: string, source: string}>}
     */
    private function ownedOffer(AgentMode $mode, Session $session, ?array $requested, ?RunContext $run = null, string $runId = 'inspection', bool $inspection = false): array
    {
        return $session->resolveReadOnly($mode->name, function (Session $view) use ($mode, $requested, $run, $runId, $inspection): array {
            $names = $this->toolNamesFor($mode, $requested, $view->agent() !== null);
            if ($view->agent() === null && $mode->skills !== []) {
                $names[] = 'skill_read';
            }
            $additional = [];
            if ($view->agent() !== null && in_array('skill_read', $mode->tools, true)) {
                $additional['skill_read'] = ['tool' => $this->skills->readerTool($mode->skills), 'owner' => 'harness', 'source' => 'builtin'];
            }
            foreach ($mode->subagents as $subagent) {
                if ($view->agent() !== null && ! in_array($subagent->name, $mode->tools, true)) {
                    continue;
                }
                if ($this->subagentRunner === null) {
                    throw new \LogicException('No Harness subagent runner is configured.');
                }
                if (! $inspection && $run === null) {
                    throw new \LogicException('Named subagent streaming requires a RunContext.');
                }
                if ($view->agent() === null) {
                    $names[] = $subagent->name;
                }
                $additional[$subagent->name] = ['tool' => ($this->subagentRunner)()->tool($subagent, $view, $run, $runId, $mode), 'owner' => 'harness.subagent', 'source' => 'builtin'];
            }
            // G-360: the plan tools, when the host and this mode turned plans on.
            if (! $inspection) {
                foreach (app(Plans::class)->tools($view, $mode->name) as $name => $tool) {
                    $names[] = $name;
                    $additional[$name] = ['tool' => $tool, 'owner' => 'harness.plans', 'source' => 'builtin'];
                }
            }
            $detailed = $this->tools->resolveDetailed($names, $view, $additional);
            $resolved = [];
            foreach ($detailed as $name => $entry) {
                // Plan tools gate themselves (G-360); the mode's gate never wraps them.
                $resolved[$name] = $mode->needsApproval($name) && ! in_array($name, PlanMachine::TOOLS, true)
                    ? $this->gated($view, $entry['tool'], $runId) : $entry['tool'];
            }
            if ($view->agent() !== null && isset($resolved['complete_task'])) {
                $authorizer = $this->authorizer->forSession($view);
                if (! $authorizer->enabled() || ! $authorizer->hasCallPolicy()) {
                    throw new \LogicException('Named task completion requires enabled authorization and an explicit call policy.');
                }
            }
            $offered = $this->authorizer->allowed($view, $resolved);
            $descriptors = [];
            foreach ($offered as $tool) {
                $descriptors[] = ['name' => $tool->name(), 'owner' => $detailed[$tool->name()]['owner'], 'source' => $detailed[$tool->name()]['source']];
            }

            return ['tools' => $offered, 'descriptors' => $descriptors];
        });
    }

    /**
     * @param  list<string>|null  $toolNames
     * @return list<Tool>
     */
    private function authorizedTools(AgentMode $mode, Session $session, ?array $toolNames, RunContext $run, string $runId): array
    {
        if ($session->agent() !== null) {
            return $this->cancelGuarded($this->approvalScopedOffer($session, $this->ownedOffer($mode, $session, $toolNames, $run, $runId)['tools']), $run, $runId);
        }
        $names = $this->toolNamesFor($mode, $toolNames);
        if ($mode->skills !== []) {
            $names[] = 'skill_read';
        }
        $resolved = $this->tools->resolve(array_values(array_unique($names)), $session);

        // Subagents are authority, so they go through the SAME authorization
        // pass as any other tool rather than around it.
        foreach ($this->subagentTools($mode, $session, $run, $runId) as $name => $tool) {
            $resolved[$name] = $tool;
        }

        // Marked BEFORE authorization, so an approval-gated tool that the policy
        // then removes never reaches the model at all — rather than being
        // offered and stopping the run on a request nobody can grant.
        foreach ($resolved as $name => $tool) {
            if ($mode->needsApproval($name)) {
                $resolved[$name] = $this->gated($session, $tool, $runId);
            }
        }

        // G-360: the plan tools, when the host and this mode turned plans on.
        // Added AFTER the mode's gate: they gate themselves (a draft, a change
        // and an approval pause are core approvals bound to their arguments),
        // so the host policy's per-call gate never sits in front of them.
        foreach (app(Plans::class)->tools($session, $mode->name) as $name => $tool) {
            $resolved[$name] = $tool;
        }

        return $this->cancelGuarded($this->approvalScopedOffer($session, $this->authorizer->allowed($session, $resolved)), $run, $runId);
    }

    /**
     * Every offered tool checks the run's cancel flag immediately before it
     * runs (G-285). See {@see CancelGuardedTool} for why, and for the tools
     * it leaves as they are.
     *
     * @param  list<Tool>  $tools
     * @return list<Tool>
     */
    private function cancelGuarded(array $tools, RunContext $run, string $runId): array
    {
        $ledger = $run->ledger;

        return array_map(static fn (Tool $tool): Tool => CancelGuardedTool::guard($tool,
            static fn (): bool => $ledger->cancelRequested(), $runId), $tools);
    }

    /**
     * @param  list<Tool>  $tools
     * @return list<Tool>
     */
    private function approvalScopedOffer(Session $session, array $tools): array
    {
        $scope = $session->thread()->approvalToolNames();

        // Intersect AFTER resolving and authorizing the current mode, including
        // builtins. A changed mode or registry cannot expand the stored offer.
        return $scope === null ? $tools : array_values(array_filter($tools,
            static fn (Tool $tool): bool => in_array($tool->name(), $scope, true)));
    }

    /**
     * @param  iterable<Message>  $messages
     * @param  list<Tool>  $tools
     */
    private function recordTurn(Session $session, iterable $messages, string $runId, array $tools): void
    {
        // Materialised once: the thread and the observers both read it.
        $messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        $ended = array_values(array_filter($messages, fn (Message $message): bool => $message instanceof ToolResultMessage && isset($this->endedByStart[$message])));
        $session->thread()->record($messages, $runId, array_map(static fn (Tool $tool): string => $tool->name(), $tools),
            app(RunTrees::class)->parkFacts($runId),
            array_map(fn (ToolResultMessage $message): array => ['message' => $message, 'approvals' => $this->endedByStart[$message]], $ended));
        // A runner start's task row is written: known in memory from here,
        // so nothing that settles the start needs a store read to keep it
        // (round 13), not even after a listener below throws.
        $this->startSettles->markRecorded($runId);
        // The calls a runner start ended are announced as denied only now
        // that the rows ending them are durable (G-387): a store failure on
        // that write announces nothing that was not recorded.
        foreach ($ended as $message) {
            unset($this->endedByStart[$message]);
            foreach ($message->toolResults as $result) {
                $this->emitToolDecision(ToolDenied::class, $session, $runId, $this->declaredTool($session, $result->toolName),
                    $result->toolName, $result->args, 'policy');
            }
        }
        // AFTER the durable record, so an observer can never lose a turn the
        // thread kept, and its failure (isolated in MemoryHooks) cannot undo one.
        app(MemoryHooks::class)->recorded($session, $runId, $messages);
    }

    /**
     * The tool names a run may resolve.
     *
     * THE MODE IS A CEILING, NOT A DEFAULT. A caller-supplied list narrows it
     * and can never widen it. Before this, `$toolNames ?? $mode->tools` let the
     * caller's list REPLACE the mode's, so a mode named `readonly` guaranteed
     * nothing: any caller could name any registered tool, and `'*'` reached
     * everything the registry held.
     *
     * That was survivable while a run was something an application started for
     * itself. It stops being survivable with subagents, whose entire safety
     * story is a narrowed toolset — a child that can widen its own authority
     * back to the registry is not narrowed, it is merely described that way.
     *
     * `'*'` is read in whichever position it appears:
     *  - in the MODE, it is an unrestricted ceiling, so the caller's list stands;
     *  - from the CALLER, it means "everything this mode allows" — never more.
     *
     * @param  list<string>|null  $requested
     * @return list<string>
     */
    private function toolNamesFor(AgentMode $mode, ?array $requested, bool $strict = false): array
    {
        $ceiling = $mode->tools;

        if ($requested === null || in_array('*', $requested, true)) {
            return $ceiling;
        }

        if (in_array('*', $ceiling, true)) {
            return $requested;
        }

        if ($strict && array_diff($requested, $ceiling) !== []) {
            throw new \LogicException('A requested tool is not available in the active agent mode.');
        }

        return array_values(array_intersect($requested, $ceiling));
    }

    /**
     * The step ceiling for THIS run: the mode's default unless the caller asked
     * for something else, and never more than the operator allows.
     *
     * A mode's constant is a sensible default, not a fact about every task run
     * in that mode. A caller holding a frozen, approved budget knows the size
     * of the job better than the config does — and when the two disagree the
     * agent is told one number and cut off at another, which discards work
     * rather than bounding it.
     *
     * `max_steps_ceiling` is the operator's word and outranks both. Without it
     * this method would let any caller lift its own limit, which is the exact
     * shape `RunBudget::nestedWithin()` refuses for subagents.
     */
    private function ceilingFor(Session $session, AgentMode $mode): int
    {
        if ($session->agent() !== null) {
            $requested = $session->maxSteps() ?? $mode->maxSteps;
            $ceiling = $this->stepCeilingConfig($session);

            return $ceiling === null ? $requested : min($requested, $ceiling);
        }
        $requested = $session->maxSteps();

        if ($requested === null) {
            return $mode->maxSteps;
        }

        $ceiling = $this->stepCeilingConfig(null);

        return $ceiling === null ? $requested : min($requested, $ceiling);
    }

    /**
     * `max_steps_ceiling` as a count (G-387): unset is no ceiling; a whole
     * number is itself (a negative one 0); anything else, a non-finite or
     * fractional number, one past the JSON-safe range, or a word, is 0. A
     * ceiling that cannot be read never removes the cap.
     */
    private function stepCeilingConfig(?Session $session): ?int
    {
        $value = $session === null ? ($this->config['max_steps_ceiling'] ?? null) : $this->agentValue($session, 'max_steps_ceiling');
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return max(0, min($value, 9007199254740991));
        }
        if (is_string($value) && preg_match('/\A[0-9]{1,15}\z/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value && $value >= 0 && $value <= 9007199254740991) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * The nested agents this mode may call, as tools.
     *
     * Empty unless the mode declares them — a run cannot reach a subagent it
     * was not given, and being nested grants nothing by itself.
     *
     * @return array<string, Tool>
     */
    private function subagentTools(AgentMode $mode, Session $session, RunContext $run, string $runId): array
    {
        if ($mode->subagents === [] || ! $this->subagentRunner instanceof Closure) {
            return [];
        }

        $runner = ($this->subagentRunner)();
        $tools = [];

        foreach ($mode->subagents as $subagent) {
            $tools[$subagent->name] = $runner->tool($subagent, $session, $run, $runId, $mode);
        }

        return $tools;
    }

    /**
     * What this turn ADDED to the conversation. That is not `$response->messages`.
     *
     * A real provider handler builds `$response->messages` from the request it
     * sent, and the request carries the history. Recording that list whole
     * re-appended the entire conversation on every turn, so a thread doubled
     * each time. The Lab's commentary thread reached 2,046 rows holding 20
     * distinct messages after ten runs, 2^11 - 2 exactly. It went unseen for
     * every release from v0.2.0 on because `Prism::fake()` returns only the
     * messages a test gives it, and every test gave it one exchange.
     *
     * The history is dropped by IDENTITY. It is exactly the objects this run
     * handed to Prism, and a handler that echoes them returns those same
     * instances, so every message that is not one of them is new. A response
     * with none of them (a fake, or a handler that returns only the new
     * exchange) is recorded whole.
     *
     * By identity, not as a prefix. Resuming an approval, Prism removes the
     * tool result messages after the assistant and appends one merged message,
     * so the response no longer BEGINS with the history. Matched as a prefix,
     * that recorded the whole conversation again, the doubling this method
     * exists to prevent, on the approval path only.
     *
     * @param  list<Message>  $history
     * @return list<Message>
     */
    private function newMessages(TextResponse $response, array $history): array
    {
        return $this->newMessageObjects($response->messages->values()->all(), $history);
    }

    /** @param list<Message> $messages
     * @param  list<Message>  $history
     * @return list<Message>
     */
    private function newMessageObjects(array $messages, array $history): array
    {
        $sent = [];

        foreach ($history as $message) {
            $sent[spl_object_id($message)] = true;
        }

        return array_values(array_filter(
            $messages,
            fn (Message $message): bool => ! isset($sent[spl_object_id($message)]),
        ));
    }

    /**
     * The tools a run actually invoked, in order.
     *
     * @return list<string>
     */
    private function toolCallNames(TextResponse $response): array
    {
        $names = [];

        foreach ($response->steps as $step) {
            foreach ($step->toolCalls as $call) {
                $names[] = $call->name;
            }
        }

        return $names;
    }

    /**
     * A cost limit from config (G-387): absent or null is no limit; present,
     * it is a finite non-negative number, or the configuration is refused.
     */
    private function floatConfig(string $key): ?float
    {
        return RunBudget::costLimit($this->config[$key] ?? null, $key);
    }

    /**
     * A count limit from config (G-387): absent or null is no limit; present,
     * it is a whole non-negative number, or the configuration is refused.
     */
    private function nullableIntConfig(string $key, ?Session $session = null): ?int
    {
        $value = $session === null ? ($this->config[$key] ?? null) : $this->agentValue($session, $key);

        return RunBudget::countLimit($value, $key);
    }

    /**
     * What a response cost, or null when the provider did not say.
     *
     * Passed through as null rather than coerced to zero — see
     * {@see RunLedger::recordCost()} for why that distinction is the whole
     * difference between an enforced cost cap and a decorative one.
     */
    private function costOf(TextResponse $response): ?float
    {
        return $response->usage->cost;
    }

    private function stringConfig(string $key, ?Session $session = null): string
    {
        $value = $session === null ? ($this->config[$key] ?? null) : $this->agentValue($session, $key);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Harness runtime [%s] is not configured.', $key));
        }

        return $value;
    }

    private function integerConfig(string $key, int $default, ?Session $session = null): int
    {
        $value = $session === null ? ($this->config[$key] ?? $default) : $this->agentValue($session, $key);

        return is_int($value) && $value >= 0 ? $value : $default;
    }

    private function agentValue(Session $session, string $key): mixed
    {
        $options = $session->agent() === null ? [] : ($this->agents[$session->agent()] ?? []);

        return is_array($options) && array_key_exists($key, $options) ? $options[$key] : ($this->config[$key] ?? null);
    }

    /**
     * The facts a run's own allowance is computed from (G-387), before its
     * tree and plan grants, and whether the turn resumes a parked run. Read
     * once, when the run begins; see {@see RunBudgets::allowance()}.
     *
     *  - `used`: what the parked run this turn resumes wrote it had used, on
     *    the row it parked on, children included, for ANY turn that resumes
     *    one except a runner start (no context, metadata or host); 0 for a
     *    new message, which starts a new run. Never less than the run's own transcript rows show it took: a row
     *    forged lower gains nothing. A value this runtime could not have
     *    written fails closed, with nothing left, and so does a count absent
     *    beside a saved ceiling (the two are written together). A row with
     *    neither, from an earlier release, is counted from the transcript.
     *  - `saved_ceiling`: the ceiling the runner saved with a child, for any
     *    turn the runner did not start.
     *  - `context_left`: what the runner's or the host's context has left.
     *  - `steps_ceiling`: `max_steps_ceiling` for a named agent's session, and
     *    for a workflow node the session's own ceiling too: on the run's whole
     *    count, so a node resumed after an approval continues it.
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function ownFacts(Session $session, AgentMode $mode, ?RunContext $context, string $prompt): array
    {
        $kind = app(RunTrees::class)->contextKind($context);
        $cap = $session->agent() === null ? null : $this->stepCeilingConfig($session);
        if ($context !== null && $context->workflowNode) {
            $own = $this->ceilingFor($session, $mode);
            $cap = $cap === null ? $own : min($cap, $own);
        }
        $state = 'none';
        $used = 0;
        $savedCeiling = $kind === 'runner' ? null : $session->childStepCeiling();
        // A linked child with no saved ceiling (an earlier release's, or one
        // whose ceiling is gone) fails CLOSED: it acts on its decisions with
        // no model step. Only a session that never was a child uses its mode.
        if ($kind !== 'runner' && $savedCeiling === null && $session->isLinkedChild()) {
            $savedCeiling = 0;
        }
        // Only a turn that resumes a parked run continues its count. A new
        // message starts a new run, held to the saved ceiling afresh; what a
        // child spends across runs is bounded by its run tree's record.
        if ($kind !== 'runner' && $prompt === '') {
            [$state, $written] = $session->thread()->pausedStepsUsed();
            // Written together with the saved ceiling: absent beside one, the
            // row is not this runtime's, and it fails closed.
            if ($state === 'absent' && $savedCeiling !== null) {
                $state = 'invalid';
            }
            $recount = $state === 'none' ? 0 : ($session->thread()->approvalRunSteps() ?? 0);
            $used = match ($state) {
                'valid' => max((int) $written, $recount),
                'absent' => $recount,
                default => 0,
            };
        }
        $facts = [
            'context' => $kind,
            'mode_steps' => $this->ceilingFor($session, $mode),
            'saved_ceiling' => $savedCeiling,
            'context_left' => $context === null ? null : max(0, $context->budget->maxSteps - $context->ledger->steps()),
            'steps_ceiling' => $cap,
            'used' => $used,
            'tree_left' => null,
            'plan_left' => null,
            'took' => 0,
        ];
        if ($state === 'invalid') {
            // FAIL CLOSED: the run has used its whole ceiling.
            $ceiling = $this->allowanceOf($facts)['ceiling'];
            $facts['saved_ceiling'] = $ceiling;
            $facts['used'] = $ceiling;
        }

        return [$facts, $prompt === '' && $state !== 'none'];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{ceiling: int, used: int, own_left: int, tree_left: ?int, plan_left: ?int, allowance: int, acts_without_step: bool, used_at_park: int, left_at_park: int}
     */
    private function allowanceOf(array $facts): array
    {
        $result = RunBudgets::allowance($facts);
        if (! isset($result['allowance'])) {
            throw new \LogicException('A run began with no step allowance.');
        }

        return $result['allowance'];
    }

    /**
     * The run, narrowed to what a shared plan budget holds for it when the
     * turn is one of the plan's own (G-366); unchanged otherwise. Any failure
     * after the run began closes the run first: a refusal as every other
     * budget refusal does, and a store or lock error as a failed run.
     *
     * Then the run's step allowance (G-387), computed ONCE from its own facts
     * and the tree's and the plan's grants, each asked for no more than the
     * run itself has left in one locked write, recorded beside the run's
     * membership, and the run narrowed to it: every consumer reads that.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route: string}  $membership
     * @param  array<string, mixed>  $facts
     * @param  ?int  $readBase  a runner start's ledger count as the start gate read it (G-387)
     * @param  ?string  $started  the delegation a runner start's commit opened (G-387)
     */
    private function planRun(Session $session, string $runId, RunContext $run, ?RunContext $context, string $prompt, array $membership, bool $begun, array $facts, ?int $readBase = null, ?string $started = null): RunContext
    {
        try {
            $trees = app(RunTrees::class);
            $plans = app(Plans::class);
            // A child's ceiling is its delegation's lifetime budget: the steps
            // it used since the runner last started it, live holds of other
            // runs included, are claimed from in ONE locked write, and this
            // run holds what it may take until it ends.
            $durable = $trees->claimDelegation($session, $runId, $run->ledger, function (int $durable) use ($facts): int {
                return $this->allowanceOf([...$facts, 'used' => max((int) $facts['used'], $durable)])['own_left'];
            }, $started);
            if ($durable === -1) {
                // The count is gone or is not this delegation's: FAIL CLOSED.
                $facts['saved_ceiling'] = $facts['used'] = $this->allowanceOf($facts)['ceiling'];
            } elseif ($durable !== null) {
                $facts['used'] = max((int) $facts['used'], $durable);
            }
            $own = $this->allowanceOf($facts)['own_left'];
            // A root run opens a tree; any turn on a child session the runner
            // did not start continues the run tree it was started in, a host
            // context narrowed to what that tree has left. Read from the
            // membership the run began with.
            $run = $trees->rootRun($session, $runId, $run, $membership, $own);
            $facts['tree_left'] = $trees->treeGrant($runId);
            $run = $plans->planTurn($session, $runId, $run, $context, $prompt, min($own, $facts['tree_left'] ?? $own));
            $facts['plan_left'] = $plans->planGrant($runId);

            $allowance = $this->allowanceOf($facts);
            // A runner start is narrowed from the count the gate read, so a
            // step the context took since counts against it (G-387).
            $base = $readBase ?? $run->ledger->steps();
            $budget = $run->budget;
            // The same context when the allowance is already its limit: a
            // host's own context stays the object it handed in.
            $run = $budget->maxSteps === $base + $allowance['allowance'] ? $run : $run->withBudget(new RunBudget(
                maxSteps: $base + $allowance['allowance'],
                maxCostUsd: $budget->maxCostUsd,
                maxSeconds: $budget->maxSeconds,
                maxOutputTokensPerCall: $budget->maxOutputTokensPerCall,
                maxToolCalls: $budget->maxToolCalls,
            ));
            $trees->recordAllowance($runId, $run, $allowance, $base);

            return $run;
        } catch (Throwable $failure) {
            if (! $begun || ($failure instanceof SessionLockLost && $failure->isWriteRejected())) {
                // No run state to close, so nothing else settles what the
                // run tree holds for this run: return it now rather than let
                // it be charged in full when it expires.
                app(RunTrees::class)->finished($session, $runId);

                throw $failure;
            }
            $refused = $failure instanceof RunNotPermitted || $failure instanceof PlanRefused;
            $this->settleStart($runId);
            $session->failRun($runId, $refused ? 'budget' : ClassLabel::of($failure));
            if (! $refused) {
                event(new RunFailed(
                    sessionKey: $session->key(),
                    runId: $runId,
                    exception: ClassLabel::of($failure),
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                    harnessAgent: $session->agent(),
                ));
            }

            throw $failure;
        }
    }

    /**
     * A root with no context opens a ledger of its own: it starts at what the
     * parked run it resumes had used, so the run's count (and the reason it
     * stops on) continues that run's. A resume with no context also records
     * that a person did not approve any call nobody answered, as for a
     * denial: the resume refuses it.
     *
     * @param  array<string, mixed>  $facts
     */
    private function continuePausedSteps(Session $session, string $prompt, ?RunContext $context, RunContext $run, string $runId, array $facts, bool $paused): void
    {
        if ($context !== null) {
            return;
        }
        $run->ledger->recordSteps((int) $facts['used']);
        if ($paused) {
            foreach ($session->thread()->unansweredApprovals() as $call) {
                $this->emitToolDecision(ToolDenied::class, $session, $runId, $this->declaredTool($session, $call['name']), $call['name'], $call['arguments'], 'person');
            }
        }
    }

    /**
     * The host tool policy in force (G-326).
     *
     * The policy set with {@see self::setToolPolicy()} for the current request
     * or queued job; otherwise the deployment's, configured by
     * `prism-harness.agent.tool_policy` (the shared JSON shape);
     * `ToolPolicy::none()` when neither is set. Validated against the
     * registered tools when first read.
     */
    public function toolPolicy(): ToolPolicy
    {
        $slot = $this->policySlot();

        return $slot->policy ??= $this->admitPolicy(isset($this->config['tool_policy'])
            ? ToolPolicy::fromArray($this->config['tool_policy'])
            : ToolPolicy::none());
    }

    /**
     * Replace the host tool policy for THIS request or queued job (G-326).
     * Never reachable from a tool.
     *
     * The runtime is a singleton, so the policy is NOT kept on it: it lives in
     * a scoped container slot, which Laravel empties when Octane starts the
     * next request and when a queue worker starts the next job. A policy set
     * for one tenant's request therefore never applies to a session a later
     * request or job serves; that one gets the deployment's policy from config
     * until its own host code sets another. Within one request or job it
     * applies to every session the request runs, so a request or job that
     * serves several tenants sets the policy before each one's runs.
     *
     * Applies from the next tool call. A call already waiting on a person
     * keeps waiting: a policy that would now allow it does not run it. A call
     * the old policy allowed that has not started yet is refused, not run,
     * when the new policy no longer allows it, so a revoke takes effect at
     * once. A policy naming a registered tool that declares `destructive`
     * throws ToolPolicyRefused and the old policy stays.
     */
    public function setToolPolicy(ToolPolicy $policy): void
    {
        $this->policySlot()->policy = $this->admitPolicy($policy);
    }

    /**
     * The scoped slot of the current request or job, when the application
     * bound one (the service provider does); the runtime's own otherwise.
     */
    private function policySlot(): ToolPolicySlot
    {
        $container = Container::getInstance();

        return $container->bound(ToolPolicySlot::class) ? $container->make(ToolPolicySlot::class) : $this->policySlot;
    }

    private function admitPolicy(ToolPolicy $policy): ToolPolicy
    {
        return ToolPolicy::fromArray($policy->toArray(), $this->tools->declarations());
    }

    /**
     * A clone of an approval-gated tool whose approval is decided PER CALL by
     * the host policy, through core's own approval mechanism: no second one.
     *
     * Core asks the closure when it meets the call. Allowed: the call is
     * marked, and runs without a request. Otherwise: it asks a person, as
     * before. At the moment a marked call would run the policy is read again,
     * so a revoke since the step began refuses it rather than letting it run.
     *
     * The list lifts the MODE's gate only. A tool's own approval setting is
     * still consulted, so a listed tool the application says needs a person
     * for these arguments still asks.
     *
     * A call that is neither marked nor approved in the thread does not run.
     */
    private function gated(Session $session, Tool $tool, string $runId): Tool
    {
        /** @var list<string> $marked */
        $marked = [];
        /** @var array<string, int> $approvedRuns */
        $approvedRuns = [];
        // What the policy decides on: the server as the REGISTRY knows it, so
        // a provider's tool is scoped to its provider whatever it claims.
        $declared = $this->tools->declarationOf($tool);
        $gate = (clone $tool)->requiresApproval(function (array $arguments) use ($session, $tool, $declared, $runId, &$marked): bool {
            if ($this->toolPolicy()->allows($declared) && ! $tool->needsApproval($arguments)) {
                $marked[] = CanonicalJson::digest($arguments);

                return false;
            }
            $this->emitToolDecision(ToolApprovalAsked::class, $session, $runId, $tool, $tool->name(), $arguments, 'person');

            return true;
        });

        return $gate->using(function (...$args) use ($session, $tool, $declared, $runId, &$marked, &$approvedRuns) {
            $digest = CanonicalJson::digest($args);
            $index = array_search($digest, $marked, true);
            if ($index === false) {
                // Not marked by the policy, so it runs only on a person's
                // recorded approval, and once for each one.
                $used = $approvedRuns[$digest] ?? 0;
                if ($used >= $session->thread()->approvedCallsAwaitingResult($tool->name(), $digest)) {
                    $this->emitToolDecision(ToolDenied::class, $session, $runId, $tool, $tool->name(), $args, 'policy');

                    return 'Not run: this call needs approval, and none is recorded.';
                }
                $approvedRuns[$digest] = $used + 1;

                return $tool->handle(...$args);
            }
            unset($marked[$index]);
            $marked = array_values($marked);
            if (! $this->toolPolicy()->allows($declared)) {
                $this->emitToolDecision(ToolDenied::class, $session, $runId, $tool, $tool->name(), $args, 'policy');

                return 'Not run: this call needs approval, and the tool policy no longer allows it.';
            }
            $this->emitToolDecision(ToolAutoAllowed::class, $session, $runId, $tool, $tool->name(), $args, 'policy');

            return $tool->handle(...$args);
        });
    }

    /**
     * Record each decision a person made, as it is written (G-326).
     *
     * @internal Called by Session when decisions are recorded.
     *
     * @param  list<ToolApprovalResponse>  $decisions
     */
    public function recordToolDecisions(Session $session, array $decisions): void
    {
        foreach ($decisions as $decision) {
            $call = $session->thread()->approvalCall($decision->approvalId);
            if ($call === null) {
                continue;
            }
            $this->emitToolDecision($decision->approved ? ToolApproved::class : ToolDenied::class, $session,
                (string) ($session->run()['id'] ?? ''), $this->declaredTool($session, $call['name']), $call['name'], $call['arguments'], 'person');
        }
    }

    /** The registered tool a stored call names, for what it declares; null when it no longer resolves. */
    private function declaredTool(Session $session, string $name): ?Tool
    {
        try {
            return $this->tools->resolveDetailed([$name], $session)[$name]['tool'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param class-string<ToolDecision> $event */
    private function emitToolDecision(string $event, Session $session, string $runId, ?Tool $tool, string $name, mixed $arguments, string $decidedBy, ?string $reason = null): void
    {
        $fields = [
            'sessionKey' => $session->key(),
            'runId' => $runId,
            'policyRevision' => $this->toolPolicy()->revision,
            'server' => $tool === null ? null : $this->tools->declarationOf($tool)['server'],
            'tool' => $name,
            'argsDigest' => CanonicalJson::digest(is_string($arguments) ? (json_decode($arguments, true) ?? []) : $arguments),
            'decidedBy' => $decidedBy,
            'harnessAgent' => $session->agent(),
        ];
        if ($event === ToolDenied::class) {
            $fields['reason'] = $reason;
        }

        event(new $event(...$fields));
    }

    /**
     * A workflow agent node's run: the smaller of this session's own step
     * ceiling and what the workflow has left, on the workflow's ledger
     * (G-348). Any other run is returned unchanged.
     */
    private function workflowNodeRun(Session $session, AgentMode $mode, RunContext $run): RunContext
    {
        if (! $run->workflowNode) {
            return $run;
        }

        return $run->forWorkflowNode(false)->withBudget((new RunBudget(
            maxSteps: $this->ceilingFor($session, $mode),
            maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
        ))->nestedWithin($run->budget, $run->ledger));
    }

    /**
     * A workflow model call's run on the workflow's ledger (G-348): labelled
     * with the session's named agent and held to its `spend_ceiling` and
     * output limit, with the host's cost observer attached. The same rules an
     * ordinary turn gets, so a workflow is not a way around them.
     *
     * @internal Called by {@see PrismLlmClient}.
     */
    public function flowRun(?Session $session, RunContext $run): RunContext
    {
        return $this->observedRun($session === null ? $run : $this->boundedAgentRun($session, $run, steps: true));
    }

    /**
     * The trusted price of one workflow model call, from the one price list.
     *
     * @internal Called by {@see PrismLlmClient}.
     */
    public function flowCallBudget(RunContext $run, string $provider, string $model): CallBudget
    {
        return CallBudget::forRun($run, $provider, $model, $this->pricing);
    }

    private function requestSteps(Session $session, RunContext $run): int
    {
        return max(0, $run->budget->maxSteps - $run->ledger->steps());
    }

    /**
     * The run labelled with the session's named agent and held to its output
     * limit. A harness run's steps are its allowance ({@see self::planRun()}),
     * which applies `max_steps_ceiling` to the run's whole count; only a
     * workflow model call (`$steps`), which has no allowance of its own, is
     * held to it here, from the workflow ledger's count.
     */
    private function boundedAgentRun(Session $session, RunContext $run, bool $steps = false): RunContext
    {
        // The label comes from the SESSION, so every run — a delegated child
        // included — charges the agent it actually runs as.
        if ($session->agent() === null) {
            return $run->agent === null && $run->agentCeilingUsd === null ? $run : $run->forAgent(null, null);
        }
        $stepCeiling = $steps ? $this->stepCeilingConfig($session) : null;
        $output = $this->nullableIntConfig('max_output_tokens_per_call', $session);
        $budget = $run->budget;

        return $run->withBudget(new RunBudget(
            maxSteps: $stepCeiling === null ? $budget->maxSteps : min($budget->maxSteps, $run->ledger->steps() + $stepCeiling),
            maxCostUsd: $budget->maxCostUsd,
            maxSeconds: $budget->maxSeconds,
            maxOutputTokensPerCall: $output === null ? $budget->maxOutputTokensPerCall : min($budget->maxOutputTokensPerCall ?? $output, $output),
        ))->forAgent($session->agent(), $this->agentCeiling($session));
    }

    /** A named agent's `spend_ceiling`, validated at construction; null for the legacy namespace. */
    private function agentCeiling(Session $session): ?float
    {
        $agent = $session->agent();
        $ceiling = $agent === null ? null : (is_array($this->agents[$agent] ?? null) ? ($this->agents[$agent]['spend_ceiling'] ?? null) : null);

        return is_int($ceiling) || is_float($ceiling) ? (float) $ceiling : null;
    }

    /**
     * Whether a stream must take the priced path before its run context exists.
     *
     * @see RunContext::capped()
     */
    private function takesPricedPath(Session $session, ?RunContext $context, string $prompt = ''): bool
    {
        return ($context === null ? $this->floatConfig('max_cost_usd') : $context->budget->maxCostUsd) !== null
            || $this->agentCeiling($session) !== null
            // A shared plan budget with a cost cap prices every call (G-366).
            || (app(Plans::class)->servingSharedPlan($session, $prompt, $context)['max_cost_micros'] ?? null) !== null;
    }

    /**
     * The run at the depth its membership gives it (G-381): the runner's
     * child one level below the run it was started from; a turn the runner
     * did not start at least as deep as the session was started, a host
     * context putting it deeper but never shallower.
     *
     * @param  array{depth: int}  $membership
     */
    private function atMemberDepth(array $membership, RunContext $run): RunContext
    {
        return $run->depth === $membership['depth'] ? $run : $run->atDepth($membership['depth']);
    }

    /**
     * The provider and model this run requests, per field (G-321 E), for a
     * turn that is not a runner start: a runner start begins only through
     * {@see self::startChild()}, after the start gate.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route: string}  $membership
     * @param  array<string, mixed>  $facts  the run's own step facts (G-387)
     * @return array{string, string}
     */
    private function bindingFor(Session $session, AgentMode $mode, ?RunContext $context, array $membership, array $facts): array
    {
        [$provider, $model, , $save] = $this->resolveBinding($session, $mode, $context);
        if ($save) {
            throw new \LogicException('A runner start begins only through the start gate.');
        }

        return [$provider, $model];
    }

    /**
     * The provider and model this run requests, per field (G-321 E), the
     * child binding it runs on and whether that binding is saved. Reads only.
     *
     * A root run: session override, then the mode, then the agent default,
     * then the global default. A subagent's child: see {@see ChildBinding} —
     * the child session's own override slot is not read.
     *
     * A child's binding is SAVED with its session, inside the lock, so a run
     * on that session the runner did not start (the host answering an
     * approval the child stopped on, say) keeps the provider and model the
     * runner gave it, and still never reads the child's own override slot.
     *
     * Saved in the session's DURABLE half, with the child's depth: a person
     * may answer the approval after the ephemeral half has expired. A
     * session whose thread is linked beneath a parent but has no saved
     * binding is refused rather than run on the root rules, which would
     * send the child's conversation to a provider nobody chose for it.
     *
     * Which binding, and whether it is saved: {@see ChildBinding::route()},
     * the rule every language shares. Only the runner's own context saves a
     * binding, and only once the start gate let it start
     * ({@see self::commitRunnerStart()}). A binding on a context the host
     * built never replaces a saved one (it may narrow what the tree has left,
     * never move the child to another provider or model, reset its depth, or
     * erase the run tree and plan budget it continues), cannot stand in for
     * one a linked child lost, and on a session that was never a child is
     * used for that turn only.
     *
     * A session is a linked child when the runner linked its thread beneath
     * a parent, or saved run tree references with it. The ports keep no
     * thread link and identify one by those references; here they are saved
     * in the same record as the binding, so in practice it is the thread
     * link that marks a child whose binding is gone. A runner start does not
     * read it (its binding is the runner's), so it never creates the thread.
     *
     * @return array{string, string, ?ChildBinding, bool}
     */
    private function resolveBinding(Session $session, AgentMode $mode, ?RunContext $context): array
    {
        $saved = $session->childBinding();
        $kind = app(RunTrees::class)->bindingContextKind($context);
        $runner = $kind === 'runner' && $context?->binding !== null;
        $route = ChildBinding::route(
            $kind,
            $context?->binding !== null,
            $saved !== null,
            ! $runner && ($session->thread()->parent_thread_id !== null || $session->childTree() !== null || $session->childPlanLedger() !== null),
        );
        $child = match ($route['binding']) {
            'runner', 'host' => $context?->binding,
            'saved' => $saved,
            'refused' => throw RunNotPermitted::unboundChild(),
            'root' => null,
        };

        return [
            ($child === null ? $session->provider() : $child->provider)
                ?? $mode->provider ?? $child->parentModeProvider ?? $this->stringConfig('provider', $session),
            ($child === null ? $session->model() : $child->model)
                ?? $mode->model ?? $child->parentModeModel ?? $this->stringConfig('model', $session),
            $child,
            $route['save'] && $context !== null && $child !== null,
        ];
    }

    /**
     * Commit steps (a) and (b) of a runner start the start gate let through
     * (G-387): (a) the run tree hold the gate read, in one conditional locked
     * write (refused when a race shrank it, with nothing else written); (b)
     * the child's records (a new delegation count under the new ceiling, the
     * binding, depth, refs, ceiling and delegation id, and the session's
     * mode) in ONE locked write that checks again that no turn holds the
     * child and that it has no approval a person can answer, refused on a
     * race. When (b) fails or refuses, the hold from (a) is released.
     *
     * Returns the undo, for a start that fails before its task row is
     * recorded: (b) put back in one locked write (each record only while it
     * is still what (b) wrote), then (a) released. Called at most once.
     *
     * @param  array{tree: array{session: string, run: string}|'host'|'none', spends: ?string, depth: int, route: string}  $membership
     * @param  array<string, mixed>  $facts
     *                                       The records (b) writes are PROVISIONAL (G-387): they carry this
     *                                       start's run id, and every reader treats them as absent until its task
     *                                       row exists. The undo is housekeeping: it may fail, and nothing depends
     *                                       on it. Returned with the delegation id, which the start's own run
     *                                       claims its steps from before its task row exists.
     * @param  array{mode: string, parent: Session, root: string, tree_left: ?int, base: int, context_left: int}  $start
     * @return array{0: Closure(): void, 1: string}
     */
    private function commitRunnerStart(Session $session, array $membership, array $facts, ChildBinding $child, array $start, string $runId): array
    {
        $trees = app(RunTrees::class);
        $release = $trees->holdForStart($session, $membership, $start['tree_left'], $runId);
        try {
            // The step ceiling the runner gives it: the ceiling of this
            // runner start's allowance, re-applied on every turn the runner
            // does not start. A runner start overwrites it.
            $ceiling = $this->allowanceOf($facts)['ceiling'];
            $delegation = 'dlg_'.bin2hex(random_bytes(12));
            // The run tree the child is in, with the shared plan budget of
            // the run it spends from (G-366), so a child resumed outside the
            // runner continues both; under a tree the host built, what it
            // saved before unless a person cancelled that tree. All from the
            // membership the run began with (G-381).
            $savedTree = $session->childTree();
            $refs = RunTrees::keptRefs(
                $membership,
                $membership['spends'] === null ? null : app(Plans::class)->ledgerOf($membership['spends']),
                $savedTree,
                $session->childPlanLedger(),
                $trees->treeCancelled($session, $savedTree),
            );
            $mode = $start['mode'];
            $undo = $trees->commitChild($session, $delegation, $ceiling,
                static function () use ($session, $child, $membership, $refs, $ceiling, $delegation, $mode, $runId): Closure {
                    // What (b) replaces, read inside its lock.
                    $priorChild = $session->childRecord();
                    $priorMode = $session->modeSlot();
                    // The refs the child reads as now: its last CONFIRMED
                    // tree and plan ledger, kept beside the provisional
                    // record until its task row exists.
                    $confirmed = ['tree' => $session->childTree(), 'plan_ledger' => $session->childPlanLedger()];
                    $restore = static function () use ($session, $priorChild, $priorMode, $mode, $runId): void {
                        $session->restoreChildRecord($priorChild);
                        $slot = $session->modeSlot();
                        if ($slot[0] && $slot[1] === $mode && $slot[2] && is_array($slot[3]) && ($slot[3]['run'] ?? null) === $runId) {
                            $session->restoreModeSlot($priorMode);
                        }
                    };
                    try {
                        $session->rememberChildBinding($child, $membership['depth'], $refs['plan_ledger'], $refs['tree'], $ceiling, $delegation,
                            provisional: $runId, confirmedRefs: $confirmed);
                        // Provisional too (G-387): the mode before it until the task row exists.
                        $session->usingProvisionalMode($mode, $runId);
                    } catch (Throwable $failure) {
                        try {
                            $restore();
                        } catch (Throwable $undoFailure) {
                            report(new RuntimeException('A subagent start could not be undone: '.ClassLabel::of($undoFailure).'.'));
                        }

                        throw $failure;
                    }

                    return $restore;
                },
                // A host turn may have parked an approval a person can answer
                // since the gate read the child: never dismissed by a start.
                function () use ($session): void {
                    if ($this->childPending($session) === 'answerable') {
                        throw RunNotPermitted::childWaiting();
                    }
                });
            if ($undo === null) {
                throw RunNotPermitted::childBusy();
            }
        } catch (Throwable $failure) {
            $release();

            throw $failure;
        }

        return [static function () use ($undo, $release): void {
            try {
                $undo();
            } finally {
                $release();
            }
        }, $delegation];
    }

    /**
     * Commit step (c) of a runner start begins with the thread link: the
     * child's thread linked beneath the parent's, under the tree's root run.
     * Returns the start's undo, extended to put the link back as it was
     * (only while it is still the link written here).
     *
     * @param  array{mode: string, parent: Session, root: string, tree_left: ?int, base: int, context_left: int}  $start
     * @param  Closure(): void  $undo
     * @return Closure(): void
     */
    private function linkStart(Session $session, array $start, Closure $undo): Closure
    {
        $existing = Thread::existingAt($session->address());
        $prior = $existing === null ? null : [
            'parent_thread_id' => $existing->getRawOriginal('parent_thread_id'),
            'root_run_id' => $existing->getRawOriginal('root_run_id'),
            'updated_at' => $existing->getRawOriginal('updated_at'),
        ];
        $parent = $start['parent']->thread();
        $session->thread()->linkBeneath($parent, $start['root']);
        $parentId = $parent->getKey();
        $done = false;

        return static function () use ($session, $prior, $parentId, $start, $undo, &$done): void {
            if (! $done) {
                $done = true;
                try {
                    $thread = Thread::existingAt($session->address());
                    if ($thread !== null && (string) $thread->parent_thread_id === (string) $parentId && $thread->root_run_id === $start['root']) {
                        $thread->timestamps = false;
                        $thread->setRawAttributes([...$thread->getAttributes(), ...($prior ?? ['parent_thread_id' => null, 'root_run_id' => null])]);
                        $thread->save();
                    }
                } catch (Throwable $failure) {
                    report(new RuntimeException('A subagent thread link could not be undone: '.ClassLabel::of($failure).'.'));
                }
            }
            $undo();
        };
    }

    /**
     * Whether the child has an approval a person could still answer
     * (`answerable`: one whose run was not cancelled, on a child whose
     * decision would be accepted, its binding saved and its run tree not
     * cancelled; or one a person decided that a resume could still act on,
     * G-387), one nobody can answer (`unanswerable`, which does not hold
     * the child for ever), or none. Reads only.
     *
     * Only the refusals that MEAN nobody can answer (no saved binding, a
     * cancelled run tree) read as `unanswerable`. Any other failure of the
     * read is thrown (G-387): read as "nobody can answer", it would let a
     * start dismiss an approval a person could still answer. The runner
     * reports it as a failed start, with nothing written.
     *
     * @internal Read by the subagent runner for the start gate, and again
     * under the child's lock before a start commits its records.
     *
     * @return 'none'|'answerable'|'unanswerable'
     */
    public function childPending(Session $child): string
    {
        $thread = Thread::existingAt($child->address());
        if ($thread === null) {
            return 'none';
        }
        $pending = array_filter($thread->pendingApprovalIds(),
            static fn (string $id): bool => $child->approvalStatus($id) === 'pending');
        // A decision a person recorded that no resume has acted on yet holds
        // the child exactly as a pending approval does (G-387): a start
        // would end the call, and a model must not void a person's decision
        // by delegating. Not one whose run was cancelled.
        $decided = array_filter($thread->decidedApprovalIdsAwaitingResult(),
            static fn (string $id): bool => ! $child->cancelRequestedFor((string) $thread->approvalRunId($id)));
        if ($pending === [] && $decided === []) {
            return 'none';
        }
        try {
            // The checks a decision on it would be refused by.
            $this->assertDecisionsActionable($child, null);
            app(RunTrees::class)->assertDecisionsFunded($child, null);
        } catch (RunNotPermitted $refused) {
            if (in_array($refused->code(), ['child_binding_missing', 'run_cancelled'], true)) {
                return 'unanswerable';
            }

            throw $refused;
        }

        return 'answerable';
    }

    /**
     * Every fact a runner start depends on, READ without a write (G-387):
     * the start gate's inputs ({@see RunStarts::gate()}) that this runtime
     * holds. The subagent runner adds the child's busy and pending state.
     *
     *  - steps_left is what this start's own allowance would give
     *    ({@see RunBudgets::allowance()} for a runner start: the context's
     *    remainder, capped by max_steps_ceiling);
     *  - cancelled and time are read on the context the child would run
     *    under AND on the context the subagent tool was wired with;
     *  - cost is `spent` when any cost limit that would stop the child is
     *    spent (the context's cap, the agent's spend ceiling), and
     *    `unenforceable` when one cannot be metered (a run that reported no
     *    cost, or a capped child whose provider and model have no price);
     *  - tool_calls_spent is PHP's own limit, which the ports do not have;
     *  - tree_left and plan_left are what the tree's and the plan's records
     *    would grant now, null when none applies.
     *
     * Any read that throws fails the start CLOSED: the runner frames it as
     * `failed`, with nothing written.
     *
     * @internal Called by the subagent runner.
     *
     * @return array<string, mixed>
     */
    public function runnerStartFacts(Session $child, RunContext $childContext, RunContext $wired, mixed $task, string $modeName): array
    {
        $mode = $this->modes->forAgent($child->agent())->resolve($modeName);
        $trees = app(RunTrees::class);
        $text = is_string($task) && ! RunStarts::blank($task);
        $membership = $trees->begin($child, 'run_start_gate', $childContext, $text ? $task : 'task');
        $ledger = $childContext->ledger;
        // The count read ONCE: the start is narrowed from it (G-387).
        $base = $ledger->steps();
        [$facts] = $this->ownFacts($child, $mode, $childContext, $text ? $task : 'task');
        $facts['context_left'] = max(0, $childContext->budget->maxSteps - $base);
        // Read the durable cancel flags the ledgers watch, then the result.
        $ledger->cancelRequested();
        $wired->ledger->cancelRequested();
        $spentTime = static fn (RunContext $context): bool => $context->budget->maxSeconds !== null
            && $context->ledger->elapsedSeconds() >= $context->budget->maxSeconds;
        $spentCalls = static fn (RunContext $context): bool => $context->budget->maxToolCalls !== null
            && $context->ledger->toolCalls() >= $context->budget->maxToolCalls;
        $run = $this->boundedAgentRun($child, $childContext);

        return [
            'task' => $task,
            'too_deep' => $childContext->tooDeep(),
            'cancelled' => $ledger->cancelled() || $wired->ledger->cancelled(),
            'time_spent' => $spentTime($childContext) || $spentTime($wired),
            'steps_left' => $this->allowanceOf($facts)['own_left'],
            'cost' => $this->startCost($child, $mode, $childContext, $run, $wired),
            'tool_calls_spent' => $spentCalls($childContext) || $spentCalls($wired),
            'tree_left' => $trees->startGrant($child, $membership),
            'plan_left' => $text ? app(Plans::class)->startGrant($child, $task, $childContext) : null,
            // Not a gate fact: what the commit narrows the start from.
            'read' => ['base' => $base, 'context_left' => (int) $facts['context_left']],
        ];
    }

    /**
     * Whether a cost limit would stop a runner start's child (G-387).
     *
     * @return 'ok'|'spent'|'unenforceable'
     */
    private function startCost(Session $child, AgentMode $mode, RunContext $childContext, RunContext $run, RunContext $wired): string
    {
        $spent = false;
        $unmetered = false;
        foreach ([$run, $wired] as $context) {
            $cap = $context->budget->maxCostUsd;
            if ($cap !== null) {
                $unmetered = $unmetered || $context->ledger->unmeteredRuns() > 0;
                $spent = $spent || ! is_finite($cap) || $context->ledger->costUsd() >= $cap;
            }
        }
        $ceiling = $run->agentCeilingUsd;
        if ($ceiling !== null && ($run->agent === null || ! is_finite($ceiling) || $ceiling <= 0
            || $run->ledger->agentCostUsd($run->agent) >= $ceiling)) {
            $spent = true;
        }
        if ($spent) {
            return 'spent';
        }
        if ($unmetered) {
            return 'unenforceable';
        }
        if ($run->capped()) {
            // A capped child is priced on every call: with no trusted price
            // for its provider and model, every call would be refused.
            [$provider, $model] = $this->resolveBinding($child, $mode, $childContext);
            try {
                CallBudget::forRun($run, $provider, $model, $this->pricing);
            } catch (RunNotPermitted) {
                return 'unenforceable';
            }
        }

        return 'ok';
    }

    /**
     * Start a subagent child the start gate let through (G-387): commit in
     * ONE order, under the child's session lock. (a) the run tree hold, a
     * conditional locked write, refused when a race shrank it; (b) the
     * child's records in one locked write that checks again that no turn
     * holds it, the hold released when it refuses or fails; (c) only then
     * the session's mode, the thread link, the run and its task row. Once
     * committed, a later stop only stops model steps.
     *
     * @internal Called by the subagent runner after the start gate.
     *
     * @param  array{base: int, context_left: int}  $read  the ledger count and the context's remainder the gate read
     */
    public function startChild(Session $child, string $task, RunContext $childContext, string $mode, Session $parent, string $rootRunId, ?int $treeLeft, array $read): AgentResponse
    {
        $runId = null;

        try {
            return $this->sendLocked($child, $task, null, $childContext, [], $runId, null,
                ['mode' => $mode, 'parent' => $parent, 'root' => $rootRunId, 'tree_left' => $treeLeft,
                    'base' => $read['base'], 'context_left' => $read['context_left']]);
        } finally {
            if ($runId !== null) {
                ToolRefusals::forget($runId);
            }
        }
    }
}
