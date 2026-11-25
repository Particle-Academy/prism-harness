<?php

declare(strict_types=1);

namespace Prism\Harness\Sessions;

use Closure;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Prism\Harness\AgentResponse;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\AgentTaskSource;
use Prism\Harness\Contracts\FencedSessionStore;
use Prism\Harness\Contracts\HeldSessionLocks;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Events\RunStepped;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\PlansDisabled;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\SessionLockLost;
use Prism\Harness\Exceptions\StructuredSchemaViolation;
use Prism\Harness\Flow\FlowToolset;
use Prism\Harness\Models\Thread;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\Plans\PlanHandle;
use Prism\Harness\Plans\Plans;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\StructuredAgentResponse;
use Prism\Harness\Subagents\ChildBinding;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tasks\StoreTaskSource;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

/**
 * One participant's live runtime, reconstructed per request.
 *
 * Resolved, never held. A Laravel request boots, serves and dies, so nothing
 * survives in memory between turns — a fresh worker resolving the same address
 * has to see the same active mode, the same model and the same conversation as
 * the request that set them. Everything here reads through a store for that
 * reason, rather than being a property that happens to be populated.
 *
 * State is split deliberately:
 *
 *  - mode and model are **ephemeral**. Lose them and the next request falls
 *    back to a default, which is a shrug.
 *  - the thread is **durable**, and lives in the database as Eloquent rows.
 */
class Session
{
    /** @var array<string, mixed>|null */
    protected ?array $cachedState = null;

    /** @var (Closure(array<string, mixed>): bool)|null */
    private ?Closure $writeFence = null;

    private bool $resolutionOnly = false;

    private ?string $resolvedMode = null;

    protected readonly SessionAddress $address;

    protected readonly SessionStore $ephemeral;

    protected readonly SessionStore $durable;

    public function __construct(
        protected readonly Model|ParticipantRef $participant,
        protected readonly string $scope,
        SessionStore $ephemeral,
        SessionStore $durable,
        protected readonly ?int $ttlSeconds = null,
        protected readonly ?AgentRuntime $runtime = null,
        protected readonly int $taskLeaseSeconds = AgentTaskSource::DEFAULT_LEASE_SECONDS,
        protected readonly ?string $agent = null,
        protected readonly ?object $actor = null,
    ) {
        if ($participant instanceof ParticipantRef && $actor === null) {
            throw new \LogicException('Typed participants require an explicit authorization actor.');
        }
        $this->address = SessionAddress::forParticipant($participant, $scope, $agent);
        $indexed = $agent !== null || $participant instanceof ParticipantRef;
        $index = $indexed ? DatabaseAddressIndex::configured((new Thread)->getConnection()) : null;
        $this->ephemeral = $index !== null && ! $ephemeral instanceof IndexedSessionStore
            ? new IndexedSessionStore($ephemeral, $index, $this->address, 'ephemeral') : $ephemeral;
        $this->durable = $index !== null && ! $durable instanceof IndexedSessionStore
            ? new IndexedSessionStore($durable, $index, $this->address, 'durable') : $durable;
    }

    public function agent(): ?string
    {
        return $this->agent;
    }

    public function actor(): object
    {
        return $this->actor ?? $this->participant;
    }

    public function address(): SessionAddress
    {
        return $this->address;
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function participant(): Model|ParticipantRef
    {
        return $this->participant;
    }

    /**
     * The address this session is resolved by.
     *
     * Participant AND scope, because one participant holds several unrelated
     * conversations at once and they must not collide. The morph class is
     * hashed rather than interpolated: a class name contains backslashes, which
     * make for awkward Redis keys and leak the application's namespace layout
     * into a key that may be visible in tooling.
     */
    public function key(): string
    {
        return $this->address->sessionKey();
    }

    public function mode(): ?string
    {
        if ($this->resolvedMode !== null) {
            return $this->resolvedMode;
        }
        $mode = $this->state()['mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }

    public function usingMode(string $mode): self
    {
        return $this->write('mode', $mode);
    }

    public function model(): ?string
    {
        $model = $this->state()['model'] ?? null;

        return is_string($model) ? $model : null;
    }

    public function usingModel(string $model): self
    {
        return $this->write('model', $model);
    }

    public function provider(): ?string
    {
        $provider = $this->state()['provider'] ?? null;

        return is_string($provider) ? $provider : null;
    }

    public function usingProvider(string $provider): self
    {
        return $this->write('provider', $provider);
    }

    /**
     * The provider and model binding the subagent runner gave this session's
     * child runs, or null for a session that has never been a child (G-321 E).
     *
     * Kept in the DURABLE half, beside the thread and its pending approvals.
     * A child is resumed by a person answering one of those approvals, which
     * may happen long after the ephemeral half has expired; a binding stored
     * there would be gone by then and the child would run on the root rules.
     *
     * @internal Read by the runtime for a run the runner did not start.
     */
    public function childBinding(): ?ChildBinding
    {
        $saved = $this->savedChild();
        if ($saved === null) {
            return null;
        }
        $field = static fn (string $key): ?string => is_string($saved[$key] ?? null) ? $saved[$key] : null;

        return new ChildBinding($field('provider'), $field('model'), $field('parent_mode_provider'), $field('parent_mode_model'));
    }

    /**
     * The nesting depth the subagent runner gave this session's child runs,
     * or 0 for a session that has never been a child.
     *
     * @internal Read by the runtime, so a resumed child cannot delegate past
     * {@see RunContext::MAX_DEPTH}.
     */
    public function childDepth(): int
    {
        $depth = $this->savedChild()['depth'] ?? null;

        return is_int($depth) && $depth > 0 ? $depth : 0;
    }

    /** @internal Written by the runtime when the subagent runner starts a child run. */
    public function rememberChildBinding(ChildBinding $binding, int $depth): self
    {
        $this->assertWritableResolution();
        $durable = $this->durable->get($this->durableKey()) ?? [];
        $durable['child_binding'] = [
            'provider' => $binding->provider,
            'model' => $binding->model,
            'parent_mode_provider' => $binding->parentModeProvider,
            'parent_mode_model' => $binding->parentModeModel,
            'depth' => $depth,
        ];
        $this->durable->put($this->durableKey(), $durable);

        return $this;
    }

    /** @return array<string, mixed>|null */
    private function savedChild(): ?array
    {
        $durable = $this->durable->get($this->durableKey()) ?? [];
        $saved = $durable['child_binding'] ?? null;

        return is_array($saved) ? $saved : null;
    }

    /**
     * The step ceiling this session asks for, if it asked for one.
     *
     * A mode declares a default ceiling for every run in it. That is right for
     * a mode used the same way every time and wrong for one driven by a caller
     * that knows more — a Lab benchmark carries a FROZEN, human-approved budget
     * and then hands the agent a task sized to it. When the mode's constant and
     * that budget disagree, the agent is told one number and cut off at
     * another: it plans for what it was promised, spends the promise, and its
     * work is discarded at the smaller limit. Measured: an agent told
     * `max_turns: 20` was truncated at the mode's 10, mid-build, and the run
     * recorded it as a completion.
     *
     * The override is BOUNDED, because the alternative is not a budget. See
     * `RunBudget::nestedWithin()` for the same argument about children: a limit
     * the thing being limited can raise without bound is a limit in name only.
     * A caller may ask for more than the mode's default and never for more than
     * the operator's ceiling.
     */
    public function maxSteps(): ?int
    {
        $steps = $this->state()['max_steps'] ?? null;

        return is_int($steps) && $steps > 0 ? $steps : null;
    }

    public function usingMaxSteps(int $steps): self
    {
        return $this->write('max_steps', max(1, $steps));
    }

    /** @return array<string, mixed>|null */
    public function capability(string $name): ?array
    {
        $state = $this->durable->get($this->durableKey()) ?? [];
        $capability = $state['capabilities'][$name] ?? null;

        return is_array($capability) ? $capability : null;
    }

    /** @param array<string, mixed> $state */
    public function usingCapability(string $name, array $state): self
    {
        $this->assertWritableResolution();
        $durable = $this->durable->get($this->durableKey()) ?? [];
        $capabilities = is_array($durable['capabilities'] ?? null) ? $durable['capabilities'] : [];
        $capabilities[$name] = $state;
        $durable['capabilities'] = $capabilities;
        $this->durable->put($this->durableKey(), $durable);

        return $this;
    }

    public function forgetCapability(string $name): self
    {
        $this->assertWritableResolution();
        $durable = $this->durable->get($this->durableKey()) ?? [];
        $capabilities = is_array($durable['capabilities'] ?? null) ? $durable['capabilities'] : [];
        unset($capabilities[$name]);
        $durable['capabilities'] = $capabilities;
        $this->durable->put($this->durableKey(), $durable);

        return $this;
    }

    /**
     * The stored conversation this session is bound to.
     *
     * Durable by construction — an Eloquent thread, not session state — so it
     * is the one thing here that a flushed Redis cannot take away.
     */
    public function thread(): Thread
    {
        $this->assertWritableResolution();

        return Thread::forAddress($this->address);
    }

    /**
     * Start a fresh conversation at this same address.
     *
     * The "new chat" every chat product needs, and the harness had no way to
     * offer it: a thread is resolved by participant and scope, so a scope held
     * exactly one conversation for ever. Applications worked around it by
     * minting a scope per conversation, which defeats the addressing that lets
     * a restarted worker find the same session again.
     *
     * WHAT THIS IS NOT is a delete. The previous thread is retired, not
     * removed: every message it held stays readable and addressable by id.
     * "Clear my context" and "erase my history" are two different requests that
     * one button is very often asked to mean at once, and conflating them is
     * unrecoverable in the direction that matters.
     *
     * The session's own durable state — mode, model, provider, capabilities —
     * is deliberately untouched. Clearing the conversation should not silently
     * put the agent back into a different mode than the one the operator chose.
     */
    public function newConversation(): Thread
    {
        $this->assertWritableResolution();
        $this->thread()->retire();

        return $this->thread();
    }

    /**
     * The durable task list for this session.
     *
     * Addressed at `<session key>:tasks`, beside the session's own durable
     * state and mirroring how {@see self::thread()} is addressed — so a
     * restarted worker that resolves the same session finds the same list, and
     * any task its predecessor was holding is either still leased or has
     * expired back to `todo`.
     *
     * ON THE DURABLE STORE, ALWAYS. The list is the record of what is left to
     * do: a half-finished list that vanishes on a deploy is indistinguishable
     * from a finished one, and the run that resolves this session afterwards
     * reports success having dropped the rest of its work.
     */
    public function tasks(): StoreTaskSource
    {
        $this->assertWritableResolution();

        return new StoreTaskSource(
            store: $this->durable instanceof IndexedSessionStore ? $this->durable->forTasks() : $this->durable,
            list: $this->key(),
            leaseSeconds: $this->taskLeaseSeconds,
        );
    }

    /**
     * This session's agent plan (G-360).
     *
     * Plans are optional and off unless the host turns on
     * `prism-harness.plans.enabled` AND the session's mode sets `plans`.
     *
     * @throws PlansDisabled
     */
    public function plan(): PlanHandle
    {
        $this->assertWritableResolution();

        return app(Plans::class)->handle($this);
    }

    /**
     * Where this session's plan is stored: the durable store, always. A
     * half-finished plan that vanished on a deploy would read as finished.
     *
     * @internal
     */
    public function planStore(): SessionStore
    {
        return $this->durable;
    }

    /**
     * Hold the plan driver's lock, so one advance runs at a time per session.
     * A second advance gets SessionLocked and does nothing.
     *
     * @internal
     *
     * @return Closure(): void
     */
    public function holdPlanLock(int $ttlSeconds): Closure
    {
        $this->assertWritableResolution();
        if (! $this->ephemeral instanceof HeldSessionLocks) {
            throw new \LogicException('Plans require the ephemeral store to implement HeldSessionLocks.');
        }
        $store = $this->ephemeral;
        $key = $this->key().':plan-advance';
        $token = $store->acquireFencedLock($key, $ttlSeconds, 0);
        $released = false;

        return function () use ($store, $key, $token, &$released): void {
            if (! $released) {
                $released = true;
                $store->releaseFencedLock($key, $token);
            }
        };
    }

    /**
     * Take a turn.
     *
     * `$additionalContent` is media sent with the prompt: an Image, Document,
     * Audio or Video built from its bytes or a provider file id. Media built
     * from a URL or a path is refused, and so is media with an empty prompt.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent
     */
    public function send(string $prompt, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null): AgentResponse
    {
        $this->assertWritableResolution();
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        return $this->runtime->send($this, $prompt, $toolNames, $context, $additionalContent);
    }

    /**
     * This session's tools, for fancy-flow's `llm_call`, `tool_use` and `agent`
     * nodes. Build the workflow adapters from what this returns:
     *
     *     $tools = $session->flowTools();
     *     $llm = $tools->llmClient();
     *     $invoker = $tools->toolInvoker();
     *
     * A tool that needs approval fails its node, because a workflow node cannot
     * ask a person. Use a session turn for those.
     *
     * Each tool call and each model call holds the session lock while it runs,
     * as a turn does. When another turn holds it, nothing is read, sent, run or
     * written, and the node fails.
     *
     * @param  list<string>|null  $toolNames  narrows the mode, and cannot widen it
     */
    public function flowTools(?array $toolNames = null): FlowToolset
    {
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        return FlowToolset::of($this, $toolNames);
    }

    /** @internal The workflow toolset's route to the runtime. */
    public function flowRuntime(): AgentRuntime
    {
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        return $this->runtime;
    }

    /** @internal Makes the next read come from the store, for a long-held session. */
    public function forgetCachedState(): void
    {
        $this->cachedState = null;
    }

    /**
     * Take a turn whose answer is a document.
     *
     * The same run as `send()` — the mode's system prompt, tools and budget all
     * apply — with a schema the answer has to satisfy. What comes back is the
     * parsed document, and the thread keeps the text the model wrote, so a
     * later turn replaying this conversation reads what was actually said.
     *
     * A document that cannot be read, or that misses the schema, raises
     * `StructuredSchemaViolation` carrying the model's text. It is never
     * returned as an empty result: an empty document reads to the code that
     * receives it exactly like a considered answer of "nothing".
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent  media sent with the prompt, as for send()
     *
     * @throws StructuredSchemaViolation
     */
    public function sendStructured(string $prompt, Schema $schema, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null): StructuredAgentResponse
    {
        $this->assertWritableResolution();
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        return $this->runtime->sendStructured($this, $prompt, $schema, $toolNames, $context, $additionalContent);
    }

    /**
     * The same turn, delivered as it happens.
     *
     * Yields Prism's stream events unchanged, so an application already
     * rendering thinking, tool calls and results keeps the payloads it renders
     * today; the turn is recorded durably when the stream ends.
     *
     * THE RECORDED TRANSCRIPT IS THE SAME AS `send()`'s, because the same code
     * writes both: Prism's StreamCollector assembles the messages while the
     * events pass through. There is no choice to make between incremental
     * delivery and a faithful record, and there should never have been one —
     * a transcript that differs only when streamed is a difference nothing
     * reports, surfacing much later as a model that remembers the conversation
     * differently than it happened.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent  media sent with the prompt, as for send()
     * @return Generator<int, StreamEvent>
     */
    public function stream(string $prompt, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null): Generator
    {
        $this->assertWritableResolution();
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        yield from $this->runtime->stream($this, $prompt, $toolNames, $additionalContent, $context);
    }

    /**
     * The same turn as {@see self::stream()}, delivered as tagged run steps.
     *
     * Every step names the run it belongs to (`runId`, `parentRunId`,
     * `rootRunId`, and `turnId` when the RunContext carries one) and its place
     * in that run (`seq`, from 1, with no gaps). Tool results carry how long the
     * tool took where it ran, and whether it was REFUSED — denied, not allowed,
     * gated — rather than failed. See {@see RunStep} for the shape.
     *
     * `stream()` is unchanged and yields core's events exactly as before; both
     * dispatch a {@see RunStepped} event for every step.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent  media sent with the prompt, as for send()
     * @return Generator<int, RunStep>
     */
    public function streamSteps(string $prompt, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null): Generator
    {
        $this->assertWritableResolution();
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        yield from $this->runtime->streamSteps($this, $prompt, $toolNames, $additionalContent, $context);
    }

    /**
     * Answer a pending tool approval and continue the run.
     *
     * The decision is RECORDED IN THE THREAD, not held anywhere else. That is
     * what makes it survive: the approval a person granted this morning is a
     * durable row, so the worker that resumes tonight — a different process,
     * possibly after a deploy — reads the same answer. Mastra can treat an
     * approval as an in-memory promise because Node holds one process; here it
     * has to be storage.
     *
     * Prism DENIES BY DEFAULT when it finds no response for a pending request,
     * so a lost or unanswered approval fails closed rather than executing.
     *
     * WHO MAY APPROVE IS THE APPLICATION'S DECISION, not this package's. The
     * session is already scoped to a participant, so nobody can answer another
     * participant's pending approval through it — but "this user may approve
     * THIS action" is a question only the host can answer, and passing a raw
     * request value straight in would let anyone who can reach the route
     * approve anything their own session is waiting on. Authorize before
     * calling.
     *
     * @param  ToolApprovalRequest|string  $approval  the request, or its approval id
     */
    public function approve(ToolApprovalRequest|string $approval, bool $approved = true, ?string $reason = null, ?RunContext $context = null): AgentResponse
    {
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        $id = $approval instanceof ToolApprovalRequest ? $approval->approvalId : $approval;

        return $this->decide([new ToolApprovalResponse($id, $approved, $reason)], $context);
    }

    /**
     * Answer SEVERAL pending approvals, then continue the run once.
     *
     * A run can stop on more than one call that needs a person. Answering them
     * one at a time with approve() resumes after the first answer, and Prism
     * denies by default every call it has no answer for yet: the second call is
     * refused before anyone was asked about it. Answer them together here.
     *
     * The same authorization rule as approve(): decide who may answer before
     * calling.
     *
     * Every decision must name an approval this session is waiting on. An id
     * the run never issued, or one already answered, throws
     * ApprovalNotPending before any provider call or write (G-354).
     *
     * Throws SessionLocked, having recorded nothing, while another request
     * holds the session; the same decision can then be sent again.
     *
     * @param  list<ToolApprovalResponse>  $decisions
     *
     * @throws ApprovalNotPending
     * @throws SessionLocked
     */
    public function decide(array $decisions, ?RunContext $context = null): AgentResponse
    {
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        if ($decisions === []) {
            throw new \InvalidArgumentException('decide() needs at least one decision.');
        }

        // Checked and written INSIDE the session's fenced lock, which the
        // resumed run then keeps: a session another request holds refuses with
        // SessionLocked having written nothing, and two racing decisions for
        // one approval cannot both pass the pending check.
        //
        // Resumed with an EMPTY prompt: the conversation already contains the
        // request, the decisions, and everything before them. A new prompt here
        // would be a second instruction competing with the one the tool call
        // came from.
        return $this->runtime->resumeDecided($this, function () use ($decisions): void {
            $this->recordDecisions($decisions);
        }, $context);
    }

    /**
     * Answer pending approvals and continue the run AS IT HAPPENS (G-284).
     *
     * The same decisions as {@see self::decide()}, written to the thread by the
     * same code, WHEN THE STREAM STARTS: on its first iteration, once the
     * session lock is held and before any tool runs or any provider is called.
     * Recording at call time instead would write into a session another
     * request may be running, and would leave a lock held by a stream nobody
     * iterates. A stream that is never iterated records nothing; one dropped
     * after it started has recorded every decision, and streaming the same
     * decision again is refused, since that approval is no longer pending.
     *
     * Throws ApprovalNotPending, having recorded and sent nothing, for an id
     * the run never issued or one already answered (G-354).
     *
     * Throws SessionLocked, having recorded nothing, while another request
     * holds the session.
     *
     * Yields one `decision_recorded` step per decision first, then the rest of
     * the run as {@see self::streamSteps()} does. `seq` continues the parked
     * run's sequence. The `actor` on a decision step is the RunContext's — the
     * application's own statement of who decided — never anything from the
     * model or the request.
     *
     * The same authorization rule as approve(): decide who may answer before
     * calling.
     *
     * @param  list<ToolApprovalResponse>  $decisions
     * @return Generator<int, RunStep>
     */
    public function streamDecision(array $decisions, ?RunContext $context = null): Generator
    {
        $this->assertWritableResolution();
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        if ($decisions === []) {
            throw new \InvalidArgumentException('streamDecision() needs at least one decision.');
        }

        return $this->runtime->streamDecisionSteps($this, $decisions, function () use ($decisions): array {
            // Inside the held lock: the run state read here is the one the
            // lock protects, and the decisions are durable before anything runs.
            $parked = $this->run();
            $parkedId = is_array($parked) && ($parked['status'] ?? null) !== 'running' && is_string($parked['id'] ?? null)
                ? $parked['id'] : null;
            $lastSeq = $parkedId !== null && is_int($parked['last_seq'] ?? null) ? $parked['last_seq'] : 0;

            $this->recordDecisions($decisions);

            return [$parkedId, $lastSeq];
        }, $context);
    }

    /**
     * Where decisions are made durable, for every path that takes them.
     *
     * @param  list<ToolApprovalResponse>  $decisions
     */
    protected function recordDecisions(array $decisions): void
    {
        // BEFORE anything is written or emitted, and so before any provider
        // call: a decision answers a question the run asked (G-354).
        $this->assertDecisionsPending($decisions);

        $this->runtime?->recordToolDecisions($this, $decisions);
        $this->thread()->record([
            new ToolResultMessage(toolApprovalResponses: $decisions),
        ], $this->run()['id'] ?? null);

        // A plan held on one of these approvals reads the decision from the
        // thread, before the run resumes (G-360).
        app(Plans::class)->decided($this);
    }

    /**
     * Refuse every decision unless each names an approval this session is
     * waiting on, once (G-354).
     *
     * An id the run never issued, or one already answered, is not an answer to
     * anything. Accepted, it closed a pending call as unanswered, so a bogus
     * decision cancelled the person's real one; with nothing pending, it
     * started a model turn with no new user message, in which an ungated tool
     * could run. The whole batch is refused, with nothing recorded, when any
     * one of its decisions does not name a pending approval.
     *
     * @param  list<ToolApprovalResponse>  $decisions
     *
     * @throws ApprovalNotPending
     */
    protected function assertDecisionsPending(array $decisions): void
    {
        $pending = array_fill_keys($this->thread()->pendingApprovalIds(), true);
        foreach ($decisions as $decision) {
            if (! isset($pending[$decision->approvalId])) {
                throw ApprovalNotPending::forId($decision->approvalId);
            }
            // Answered once: the same id again in this batch is a repeat.
            unset($pending[$decision->approvalId]);
        }
    }

    /**
     * Reject a pending approval. The tool does not run.
     */
    public function deny(ToolApprovalRequest|string $approval, ?string $reason = null, ?RunContext $context = null): AgentResponse
    {
        return $this->approve($approval, false, $reason, $context);
    }

    /** @return array<string, mixed>|null */
    public function run(): ?array
    {
        $run = $this->state()['run'] ?? null;

        return is_array($run) ? $run : null;
    }

    public function beginRun(string $id, string $mode, string $provider, string $model): self
    {
        return $this->write('run', [
            'id' => $id, 'status' => 'running', 'mode' => $mode,
            'provider' => $provider, 'model' => $model, 'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  list<string>  $toolCalls  the NAMES of the tools this run invoked, in order
     */
    public function completeRun(string $id, string $finishReason, array $toolCalls = []): self
    {
        // NAMES ONLY, and that boundary is deliberate. "Which tools did this
        // run reach for" is what an operator needs to audit a guardrail, and a
        // tool name is not PII. ARGUMENTS are, and prism-opentelemetry already
        // carries them behind an opt-in capture gate with a length cap —
        // recording them a second time here, ungated, would quietly undo that
        // decision for everyone who installed both.
        return $this->finishRun($id, 'completed', [
            'finish_reason' => $finishReason,
            'tool_calls' => $toolCalls,
        ]);
    }

    public function failRun(string $id, string $failure): self
    {
        return $this->finishRun($id, 'failed', ['failure' => $failure]);
    }

    /**
     * The last run step `seq` this run emitted, so a resumed stream continues it.
     *
     * @internal
     */
    public function rememberStepSeq(string $id, int $seq): self
    {
        $run = $this->run();
        if (! is_array($run) || ($run['id'] ?? null) !== $id) {
            return $this;
        }

        return $this->write('run', [...$run, 'last_seq' => $seq]);
    }

    /**
     * Take this session's fenced lock and HOLD it until the returned release is
     * called — the same lock and fencing as {@see self::fencedLock()}, for a
     * streamed run, which cannot live inside a callback because a closure
     * cannot yield to whoever is iterating the stream.
     *
     * While held, every state write goes through `putIfOwned()` with this
     * lock's token, exactly as inside fencedLock(): a lease that lapses
     * mid-stream turns the next write into SessionLockLost, never a silent
     * unfenced write. The release restores the previous fence, drops cached
     * state and releases the lock; calling it again does nothing.
     *
     * @internal Called by the streaming runtime.
     *
     * @return Closure(): void
     */
    public function holdFencedLock(int $ttlSeconds = 10, int $waitSeconds = 5): Closure
    {
        $this->assertWritableResolution();
        if (! $this->ephemeral instanceof HeldSessionLocks) {
            throw new \LogicException('Streaming agent runs require the ephemeral store to implement HeldSessionLocks.');
        }
        $store = $this->ephemeral;
        $key = $this->key();
        $token = $store->acquireFencedLock($key, $ttlSeconds, $waitSeconds);

        // Re-read inside the lock, as fencedLock() does.
        $this->cachedState = null;
        $previous = $this->writeFence;
        $this->writeFence = fn (array $state): bool => $store->putIfOwned($key, $token, $state, $this->ttlSeconds);
        $released = false;

        return function () use ($store, $key, $token, $previous, &$released): void {
            if ($released) {
                return;
            }
            $released = true;
            $this->writeFence = $previous;
            $this->cachedState = null;
            $store->releaseFencedLock($key, $token);
        };
    }

    /**
     * Runtime acquisition with atomic state persistence; ordinary lock() remains
     * available to consumers of the original synchronous store contract.
     *
     * @template TReturn
     *
     * @param  Closure(self): TReturn  $callback
     * @return TReturn
     */
    public function fencedLock(Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        $this->assertWritableResolution();
        if (! $this->ephemeral instanceof FencedSessionStore) {
            throw new \LogicException('Agent runs require the ephemeral store to implement FencedSessionStore.');
        }
        $store = $this->ephemeral;

        return $store->withFencedLock($this->key(), function (Closure $owned, string $token) use ($callback, $store): mixed {
            $this->cachedState = null;
            $previous = $this->writeFence;
            $this->writeFence = fn (array $state): bool => $store->putIfOwned($this->key(), $token, $state, $this->ttlSeconds);
            try {
                $owned();

                return $callback($this);
            } finally {
                $this->writeFence = $previous;
                $this->cachedState = null;
            }
        }, $ttlSeconds, $waitSeconds);
    }

    /**
     * Run something that must not happen twice.
     *
     * Two workers can hold the same session at the same moment: a queued job
     * finishing a run while the user sends another message is ordinary. Advance
     * a run inside this, not outside it.
     *
     * @template TReturn
     *
     * @param  Closure(self): TReturn  $callback
     * @return TReturn
     */
    public function lock(Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
    {
        $this->assertWritableResolution();

        return $this->ephemeral->withLock(
            $this->key(),
            function () use ($callback): mixed {
                // Re-read inside the lock. State written by whoever held it
                // before us is otherwise invisible to this instance, and acting
                // on a stale read is the thing the lock is meant to prevent.
                $this->cachedState = null;

                return $callback($this);
            },
            $ttlSeconds,
            $waitSeconds,
        );
    }

    /**
     * Drop the ephemeral half. The conversation is untouched.
     */
    public function forget(): self
    {
        $this->assertWritableResolution();
        $this->ephemeral->forget($this->ephemeralKey());
        // Pre-runtime releases stored ephemeral state at the unsuffixed key.
        $this->ephemeral->forget($this->key());
        $this->cachedState = null;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->cachedState ??= $this->ephemeral->get($this->ephemeralKey())
            ?? $this->ephemeral->get($this->key())
            ?? [];
    }

    /**
     * @template T
     *
     * Ordinary store reads retain expired-entry cleanup; explicit logical writes refuse.
     *
     * @param  Closure(Session): T  $resolve
     * @return T
     */
    public function resolveReadOnly(string $mode, Closure $resolve): mixed
    {
        $view = clone $this;
        $view->cachedState = null;
        $view->resolutionOnly = true;
        $view->resolvedMode = $mode;
        try {
            return $resolve($view);
        } finally {
            $view->resolutionOnly = false;
            $view->cachedState = null;
        }
    }

    /** @internal Tool factories may defer resource access while resolving an offer. */
    public function isReadOnlyResolution(): bool
    {
        return $this->resolutionOnly;
    }

    private function assertWritableResolution(): void
    {
        if ($this->resolutionOnly) {
            throw new \LogicException('Harness tool resolution is read-only.');
        }
    }

    protected function write(string $key, mixed $value): self
    {
        $this->assertWritableResolution();
        $state = $this->state();
        $state[$key] = $value;

        if ($this->writeFence !== null) {
            if (! ($this->writeFence)($state)) {
                $this->cachedState = null;
                throw SessionLockLost::afterRejectedWrite($this->key());
            }
        } else {
            $this->ephemeral->put($this->ephemeralKey(), $state, $this->ttlSeconds);
        }
        $this->cachedState = $state;

        return $this;
    }

    /** @param array<string, mixed> $details */
    protected function finishRun(string $id, string $status, array $details): self
    {
        $run = $this->run();
        if (! is_array($run) || ($run['id'] ?? null) !== $id) {
            throw new \LogicException('Harness run state changed while the run was active.');
        }

        $this->write('run', [
            ...$run, ...$details, 'status' => $status, 'finished_at' => now()->toIso8601String(),
        ]);

        // G-360: when the run is over, the plan reads the thread it left:
        // the approvals the run staged, and the ones a new message ended.
        app(Plans::class)->afterTurn($this);

        return $this;
    }

    protected function ephemeralKey(): string
    {
        return $this->key().':ephemeral';
    }

    protected function durableKey(): string
    {
        return $this->key().':durable';
    }
}
