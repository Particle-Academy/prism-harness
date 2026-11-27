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
use Prism\Harness\Enums\CancelOutcome;
use Prism\Harness\Events\RunCancelled;
use Prism\Harness\Events\RunCancelRequested;
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
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Support\MessageMapper;
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

    /**
     * The runner start this Session object is running, while its run lasts
     * (G-387): read by {@see self::ownsStart()}. Shared with the read-only
     * views cloned from it.
     */
    private ?StartView $startView = null;

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
        $state = $this->state();
        $mode = $state['mode'] ?? null;
        if (array_key_exists(self::MODE_MARK, $state)) {
            // Written by a runner start (G-387): its mode only once that
            // start's task row exists; until then the mode before it. A mark
            // this runtime could not have written reads as no mode.
            $mark = $state[self::MODE_MARK];
            $run = is_array($mark) ? ($mark['run'] ?? null) : null;
            if (! is_string($run) || ! self::isRunId($run)) {
                return null;
            }
            if (! $this->ownsStart($run) && ! $this->taskRowExists($run)) {
                $mode = $mark['prior'] ?? null;
            }
        }

        return is_string($mode) ? $mode : null;
    }

    public function usingMode(string $mode): self
    {
        // The host's own choice: no runner start's mark stands over it.
        $this->assertWritableResolution();
        $state = $this->state();
        unset($state[self::MODE_MARK]);
        $state['mode'] = $mode;

        return $this->putState($state);
    }

    /** The mark beside a mode a runner start wrote (G-387). */
    private const MODE_MARK = 'mode_provisional';

    /**
     * The reason a decision for a step whose call ids repeat is recorded as.
     */
    public const REPEATED_CALL_ID_REASON = 'Tool call was not run because another call in this step has the same id';

    /**
     * The mode a runner start's commit (b) writes (G-387): PROVISIONAL like
     * the rest of (b), read as the mode before it until the start's task row
     * exists ({@see self::mode()}).
     *
     * @internal Written by the runtime when the subagent runner starts a child run.
     */
    public function usingProvisionalMode(string $mode, string $runId): self
    {
        $this->assertWritableResolution();
        $prior = $this->mode();
        $state = $this->state();
        $state['mode'] = $mode;
        $state[self::MODE_MARK] = ['run' => $runId, 'prior' => $prior];

        return $this->putState($state);
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

    /**
     * The shared plan budget the turn that started this child spent from, as
     * the session key the plan belongs to and the plan id; null when it spent
     * from none (G-366). A child resumed outside the runner spends from it.
     *
     * @internal Read by the plan budget for a run the runner did not start.
     *
     * @return array{session: string, plan: string}|null
     */
    public function childPlanLedger(): ?array
    {
        $ledger = $this->savedRefs()['plan_ledger'] ?? null;

        return is_array($ledger) && is_string($ledger['session'] ?? null) && is_string($ledger['plan'] ?? null)
            ? ['session' => $ledger['session'], 'plan' => $ledger['plan']] : null;
    }

    /**
     * The run tree this child was last started in, as the root session key
     * and root run id; null for a session never started in a tree of this
     * runtime. A child resumed outside the runner continues that tree's
     * budget rather than starting a fresh one.
     *
     * @internal Read by RunTrees for a run the runner did not start.
     *
     * @return array{session: string, run: string}|null
     */
    public function childTree(): ?array
    {
        $tree = $this->savedRefs()['tree'] ?? null;

        return is_array($tree) && is_string($tree['session'] ?? null) && is_string($tree['run'] ?? null)
            ? ['session' => $tree['session'], 'run' => $tree['run']] : null;
    }

    /**
     * The step ceiling the subagent runner gave this session's child run when
     * it last started it (G-387): min(its declared max_steps, what the parent
     * had left, max_steps_ceiling). Re-applied on every turn the runner did
     * not start, so a child resumed by its host keeps the ceiling it was
     * given. It is the delegation's LIFETIME budget: every turn the runner
     * did not start, resumes and new messages alike, spends from it until
     * the runner starts the child again (see {@see self::childDelegation()}).
     * Null for a session never started as a child, or saved before the
     * ceiling was kept (a linked child with none fails closed).
     *
     * @internal Read by the runtime for a run the runner did not start.
     */
    public function childStepCeiling(): ?int
    {
        $saved = $this->savedChild();
        if ($saved === null || ! array_key_exists('step_ceiling', $saved)) {
            return null;
        }
        $ceiling = $saved['step_ceiling'];

        // A value this runtime could not have written fails closed: no steps.
        return is_int($ceiling) && $ceiling >= 0 && $ceiling <= 9007199254740991 ? $ceiling : 0;
    }

    /**
     * The delegation the runner last started this child under (G-387): the
     * id of the durable count of the steps the child has used since, kept
     * beside the ceiling. Null for a session never started as a child.
     *
     * @internal
     */
    public function childDelegation(): ?string
    {
        $delegation = $this->savedChild()['delegation'] ?? null;

        return is_string($delegation) && $delegation !== '' ? $delegation : null;
    }

    /**
     * Whether this session is, or was, a subagent child: its thread is linked
     * beneath a parent, or it saved a child record (a binding, a run tree or
     * a plan ledger). Only a session that never was runs on its own mode.
     *
     * @internal
     */
    public function isLinkedChild(): bool
    {
        // The runner's address for a child, whatever was or was not saved
        // with it (an earlier release saved less).
        return str_contains($this->scope(), '::sub::')
            || $this->savedChild() !== null
            // Read, never created: this is a read the tool-resolution view allows.
            || Thread::existingAt($this->address())?->parent_thread_id !== null;
    }

    /**
     * @internal Written by the runtime when the subagent runner starts a child run.
     *
     * @param  array{session: string, plan: string}|null  $planLedger
     * @param  array{session: string, run: string}|null  $tree
     * @param  array{tree: array{session: string, run: string}|null, plan_ledger: array{session: string, plan: string}|null}|null  $confirmedRefs  the refs a provisional record reads as until it is confirmed
     */
    public function rememberChildBinding(ChildBinding $binding, int $depth, ?array $planLedger = null, ?array $tree = null, ?int $stepCeiling = null, ?string $delegation = null, ?string $provisional = null, ?array $confirmedRefs = null): self
    {
        $this->assertWritableResolution();
        $child = [
            'provider' => $binding->provider,
            'model' => $binding->model,
            'parent_mode_provider' => $binding->parentModeProvider,
            'parent_mode_model' => $binding->parentModeModel,
            'depth' => $depth,
        ];
        if ($planLedger !== null) {
            $child['plan_ledger'] = $planLedger;
        }
        if ($tree !== null) {
            $child['tree'] = $tree;
        }
        if ($stepCeiling !== null) {
            $child['step_ceiling'] = max(0, $stepCeiling);
        }
        if ($delegation !== null) {
            $child['delegation'] = $delegation;
        }
        if ($provisional !== null) {
            // The runner start that wrote it (G-387): read as absent until
            // that run's task row exists ({@see self::savedChild()}).
            $child['provisional'] = $provisional;
            // The run tree and plan ledger the child was last CONFIRMED in
            // (G-387): what the refs read as until this start's task row
            // exists, so an unconfirmed start never detaches the child from
            // the tree and plan budget that bound it.
            $child['confirmed_refs'] = [
                'tree' => $confirmedRefs['tree'] ?? null,
                'plan_ledger' => $confirmedRefs['plan_ledger'] ?? null,
            ];
        }
        // Under a key of its own (G-381): the session's durable record holds
        // host state, such as capabilities, that another process may change
        // while a child starts, and must never be rewritten from a stale read.
        $this->durable->put($this->childKey(), ['child_binding' => $child]);

        return $this;
    }

    /**
     * The child record exactly as stored (binding, depth, refs, ceiling and
     * delegation id), or null when there is none: what a runner start's
     * commit restores when the start fails before its task row (G-387).
     *
     * @internal
     *
     * @return array<string, mixed>|null
     */
    public function childRecord(): ?array
    {
        return $this->durable->get($this->childKey());
    }

    /**
     * Put the child record back as {@see self::childRecord()} read it.
     *
     * @internal
     *
     * @param  array<string, mixed>|null  $record
     */
    public function restoreChildRecord(?array $record): void
    {
        $this->assertWritableResolution();
        if ($record === null) {
            $this->durable->forget($this->childKey());
        } else {
            $this->durable->put($this->childKey(), $record);
        }
    }

    /**
     * The session's mode slot as stored: whether it is set, and its value,
     * then the same for the mark a runner start keeps beside it.
     *
     * @internal
     *
     * @return array{0: bool, 1: mixed, 2: bool, 3: mixed}
     */
    public function modeSlot(): array
    {
        $state = $this->state();

        return [array_key_exists('mode', $state), $state['mode'] ?? null,
            array_key_exists(self::MODE_MARK, $state), $state[self::MODE_MARK] ?? null];
    }

    /**
     * Put the mode slot back as {@see self::modeSlot()} read it, absent
     * included, in one write.
     *
     * @internal
     *
     * @param  array{0: bool, 1: mixed, 2: bool, 3: mixed}  $slot
     */
    public function restoreModeSlot(array $slot): void
    {
        $this->assertWritableResolution();
        $state = $this->state();
        unset($state['mode'], $state[self::MODE_MARK]);
        if ($slot[0]) {
            $state['mode'] = $slot[1];
        }
        if ($slot[2]) {
            $state[self::MODE_MARK] = $slot[3];
        }
        $this->putState($state);
    }

    /**
     * The child record every reader goes through: the binding, depth, refs,
     * ceiling and delegation id. A record a runner start wrote is
     * PROVISIONAL (G-387): it names that start's run id, and it is the
     * child's record only once that run's task row (the user row recorded
     * with its run id) exists in the child's thread. Until then it reads as
     * ABSENT, so a start that failed before its task row needs no second
     * write to be undone: a linked child with no record is refused, no
     * saved ceiling allows nothing, and an approval on it is unanswerable.
     * The row is the authority; nothing is written to confirm it.
     *
     * A record with no mark (written before marks were kept) is the record.
     * A mark that is not a run id this runtime could have written fails
     * closed: the record reads as absent.
     *
     * The runner's own run reads none of this before its task row: its
     * binding is its context's, and its delegation id is handed to it.
     *
     * @return array<string, mixed>|null
     */
    private function savedChild(): ?array
    {
        [$saved, $confirmed] = $this->storedChild();

        return $confirmed ? $saved : null;
    }

    /**
     * The run tree and plan ledger the child reads as: those of its record
     * once confirmed; for a record not yet confirmed, the refs it was last
     * CONFIRMED with, kept beside it (G-387). Only ever narrowing: a start
     * that never recorded its task row cannot detach the child from the tree
     * and plan budget that bound it, nor move it into a fresh one. A garbage
     * mark reads as no record, and no refs.
     *
     * @return array<string, mixed>|null
     */
    private function savedRefs(): ?array
    {
        [$saved, $confirmed] = $this->storedChild();
        if ($saved === null || $confirmed) {
            return $saved;
        }
        $refs = $saved['confirmed_refs'] ?? null;

        return is_string($saved['provisional'] ?? null) && self::isRunId($saved['provisional']) && is_array($refs) ? $refs : null;
    }

    /**
     * The child record as stored, and whether it is confirmed.
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function storedChild(): array
    {
        $saved = $this->durable->get($this->childKey())['child_binding'] ?? null;
        // A binding saved before it had a key of its own.
        $saved ??= ($this->durable->get($this->durableKey()) ?? [])['child_binding'] ?? null;
        if (! is_array($saved)) {
            return [null, false];
        }
        if (array_key_exists('provisional', $saved)) {
            $run = $saved['provisional'];
            if (! is_string($run) || ! self::isRunId($run) || (! $this->ownsStart($run) && ! $this->taskRowExists($run))) {
                return [$saved, false];
            }
        }

        return [$saved, true];
    }

    /**
     * Whether the run recorded its task row in this session's thread: the
     * user row recorded with its run id (G-387). Read, never written; a
     * session with no thread has none.
     *
     * @internal
     */
    public function taskRowExists(string $runId): bool
    {
        return Thread::existingAt($this->address())?->storedMessages()
            ->where('type', MessageMapper::TYPE_USER)->where('run_id', $runId)->exists() ?? false;
    }

    /**
     * The start's own run reads its own state (G-387). From commit (b) until
     * its run ends, the Session object the run uses (and every read-only
     * view of it) reads the records and the mode that start wrote as
     * confirmed: a host tool provider, a tool body, a memory hook or the
     * plan service asking this Session for its mode, binding, refs, depth or
     * ceiling during the start's run gets the start's values, never the
     * child's previous ones. Every other Session object, in this process or
     * another, keeps the provisional rule: absent until the task row exists.
     * In memory only; nothing is written to set or clear it.
     *
     * Only a view the RUNTIME entered confirms anything: it keeps the views
     * it entered, and a view host code makes by calling this itself is not
     * one of them, so it confirms nothing, even for a run the slot still
     * says is running (a crash residue).
     *
     * @internal Set by the runtime once a runner start's commit (b) succeeds.
     */
    public function enterStart(string $runId): StartView
    {
        $this->leaveStart();

        return $this->startView = new StartView($runId);
    }

    /**
     * End the start's own view (G-387), for this Session object and every
     * view cloned from it.
     *
     * @internal Called by the runtime when the start's run ends, failed or not.
     */
    public function leaveStart(): void
    {
        if ($this->startView !== null) {
            $this->startView->run = null;
            $this->startView = null;
        }
    }

    /**
     * Whether this Session object is running the runner start that wrote
     * `$runId`'s records (G-387): its view is one the runtime entered and
     * names that run, AND the run slot
     * holds that run, still running. A view set on a session whose start is
     * not running (one that failed, or that a host named by hand) confirms
     * nothing, so it can never make a dead approval answerable.
     */
    private function ownsStart(string $runId): bool
    {
        $view = $this->startView;
        $run = $view?->run;
        if ($view === null || $run === null || $run !== $runId || ! ($this->runtime ?? app(AgentRuntime::class))->enteredStart($view)) {
            return false;
        }
        $stored = $this->storedRun();

        return $stored !== null && ($stored['id'] ?? null) === $runId && ($stored['status'] ?? null) === 'running';
    }

    private static function isRunId(string $id): bool
    {
        return preg_match('/\Arun_[0-9a-f]{24}\z/D', $id) === 1;
    }

    private function childKey(): string
    {
        return $this->key().':child';
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
        return $this->runtime->resumeDecided($this, function () use ($decisions, $context): void {
            $this->recordDecisions($decisions, $context);
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

        return $this->runtime->streamDecisionSteps($this, $decisions, function () use ($decisions, $context): array {
            // Inside the held lock: the run state read here is the one the
            // lock protects, and the decisions are durable before anything runs.
            $parked = $this->run();
            $parkedId = is_array($parked) && ($parked['status'] ?? null) !== 'running' && is_string($parked['id'] ?? null)
                ? $parked['id'] : null;
            $lastSeq = $parkedId !== null && is_int($parked['last_seq'] ?? null) ? $parked['last_seq'] : 0;

            $recorded = $this->recordDecisions($decisions, $context);

            return [$parkedId, $lastSeq, $recorded];
        }, $context);
    }

    /**
     * Where decisions are made durable, for every path that takes them.
     *
     * @param  list<ToolApprovalResponse>  $decisions
     * @return list<ToolApprovalResponse> the decisions as recorded
     */
    protected function recordDecisions(array $decisions, ?RunContext $context = null): array
    {
        // BEFORE anything is written or emitted, and so before any provider
        // call: a decision answers a question the run asked (G-354).
        $this->assertDecisionsPending($decisions);
        $decisions = $this->deniedWhenCallIdsRepeat($decisions);
        // A decision the resuming turn could not act on at all (a lost child
        // binding, a cancelled or spent context) is refused here, with
        // nothing recorded, rather than consumed by a resume that refuses.
        $this->runtime?->assertDecisionsActionable($this, $context,
            array_filter($decisions, static fn (ToolApprovalResponse $decision): bool => $decision->approved) === []);
        // A decision a spent shared plan budget could not run is refused
        // here, with nothing recorded, rather than consumed by a resume that
        // is then refused (G-366).
        app(Plans::class)->assertDecisionsFunded($this, array_map(
            static fn (ToolApprovalResponse $decision): string => $decision->approvalId, $decisions), $context);
        app(RunTrees::class)->assertDecisionsFunded($this, $context);

        $this->runtime?->recordToolDecisions($this, $decisions);
        $this->thread()->record([
            new ToolResultMessage(toolApprovalResponses: $decisions),
        ], $this->run()['id'] ?? null);

        // A plan held on one of these approvals reads the decision from the
        // thread, before the run resumes (G-360).
        app(Plans::class)->decided($this);

        return $decisions;
    }

    /**
     * Every decision for a step whose call ids repeat, as a denial.
     *
     * A decision reaches a call through the call id its request names, and a
     * provider can give two calls in one step the same id. Such a step is
     * denied whatever was decided, so no gated call in it runs and the
     * recorded decisions say so.
     *
     * @param  list<ToolApprovalResponse>  $decisions
     * @return list<ToolApprovalResponse>
     */
    protected function deniedWhenCallIdsRepeat(array $decisions): array
    {
        if (! $this->thread()->pausedStepRepeatsCallIds()) {
            return $decisions;
        }

        return array_map(
            static fn (ToolApprovalResponse $decision): ToolApprovalResponse => new ToolApprovalResponse($decision->approvalId, false, self::REPEATED_CALL_ID_REASON),
            $decisions,
        );
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
        $thread = $this->thread();
        $pending = array_fill_keys($thread->pendingApprovalIds(), true);
        foreach ($decisions as $decision) {
            if (! isset($pending[$decision->approvalId])) {
                throw ApprovalNotPending::forId($decision->approvalId);
            }
            // Settled-cancelled, never pending: a cancelled run never resumes (G-285).
            $runId = $thread->approvalRunId($decision->approvalId);
            if ($runId !== null && $this->cancelRequestedFor($runId)) {
                throw ApprovalNotPending::cancelled($decision->approvalId);
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

    /**
     * This session's latest run, or null when it has none.
     *
     * A run parked on an approval when a person cancelled it reads `status`
     * `cancelled` (G-285): the cancel is durable even though the parked run
     * had no process to write it.
     *
     * @return array<string, mixed>|null
     */
    public function run(): ?array
    {
        $run = $this->currentRun($this->storedRun());

        return $run === null ? null : $this->withCancel($run);
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function withCancel(array $run): array
    {
        $status = $run['status'] ?? null;
        if ($status !== 'running' && $status !== 'cancelled' && is_string($run['id'] ?? null) && $this->cancelRequestedFor($run['id'])) {
            $run['status'] = 'cancelled';
        }

        return $run;
    }

    /**
     * The run slot exactly as stored.
     *
     * @return array<string, mixed>|null
     */
    private function storedRun(): ?array
    {
        $run = $this->state()['run'] ?? null;

        return is_array($run) ? $run : null;
    }

    /**
     * The run a reader of this session's current run sees (G-387). A runner
     * start's run is PROVISIONAL until its task row exists: one that ended
     * without it never happened, and the run it replaced (the run the child
     * is parked on, say) is still the current one, so it can be cancelled
     * and its approvals stamped and answered as before. While it is still
     * running it is the current run. Nothing is written to settle this.
     *
     * @param  array<string, mixed>|null  $run
     * @return array<string, mixed>|null
     */
    private function currentRun(?array $run): ?array
    {
        if ($run === null || ($run['provisional'] ?? null) !== true || ($run['status'] ?? null) === 'running') {
            return $run;
        }
        $id = $run['id'] ?? null;
        if (is_string($id) && $this->taskRowExists($id)) {
            return $run;
        }
        $prior = $run['prior'] ?? null;

        return is_array($prior) ? $prior : null;
    }

    /**
     * How many run ids a session remembers, so cancel() can tell one of its
     * own earlier runs (`not-running`) from an id it never issued (`refused`).
     */
    private const RUN_HISTORY = 20;

    /**
     * @param  array{tree: array{session: string, run: string}|'host'|'none', depth: int}|null  $tree  the run tree the run belongs to, as it began (G-381)
     */
    public function beginRun(string $id, string $mode, string $provider, string $model, ?string $parentRunId = null, ?string $rootRunId = null, ?string $turnId = null, ?array $tree = null, bool $provisional = false): self
    {
        $history = is_array($this->state()['run_history'] ?? null) ? array_values(array_filter($this->state()['run_history'], 'is_string')) : [];
        $history = array_slice(array_values(array_unique([...$history, $id])), -self::RUN_HISTORY);
        // The run tree the run belongs to, kept DURABLY by run id: a cancel
        // marks it and a cancel check reads it, from any process and after
        // the ephemeral half has expired, for as long as the run id is one
        // this session remembers.
        $membership = ['tree' => $tree['tree'] ?? 'none', 'depth' => $tree['depth'] ?? 0];
        $this->rememberRunTree($id, $membership);

        return $this->writeAll([
            'run' => [
                'id' => $id, 'status' => 'running', 'mode' => $mode,
                'provider' => $provider, 'model' => $model, 'started_at' => now()->toIso8601String(),
                // The run's tags, so a cancel from another process carries them.
                'parent_run_id' => $parentRunId, 'root_run_id' => $rootRunId, 'turn_id' => $turnId,
                // The run tree it belongs to, as it began (G-381).
                'tree' => $membership['tree'], 'depth' => $membership['depth'],
                // A runner start (G-387): the current run only once its task
                // row exists; the run it replaces is kept beside it.
                ...($provisional ? ['provisional' => true, 'prior' => $this->priorRun()] : []),
            ],
            'run_history' => $history,
        ]);
    }

    /**
     * The status of this run in the run slot as stored, or null when the
     * slot holds another run (G-387).
     *
     * @internal
     */
    public function runStatus(string $id): ?string
    {
        $run = $this->storedRun();
        $status = $run !== null && ($run['id'] ?? null) === $id ? ($run['status'] ?? null) : null;

        return is_string($status) ? $status : null;
    }

    /**
     * The current run a runner start replaces, as a reader sees it, without
     * the run it replaced in turn.
     *
     * @return array<string, mixed>|null
     */
    private function priorRun(): ?array
    {
        $prior = $this->currentRun($this->storedRun());
        if ($prior !== null) {
            unset($prior['prior'], $prior['provisional']);
        }

        return $prior;
    }

    /**
     * Ask this session's run to stop (G-285), from any process.
     *
     * The flag is DURABLE: written to the session's durable store, so a queue
     * worker running the run sees it at its next check even when the request
     * calling this is a different process. The run checks it between model
     * steps and before each tool call, and stops at the first check after the
     * flag is set. A tool call already executing is NOT interrupted: it
     * finishes, its result is recorded, and the next step or tool is refused.
     * The run then ends `cancelled`, with {@see RunCancelled}, and never
     * resumes. Cancelling a run stops the whole run tree it belongs to: its
     * subagents, and the workflow it is a node of.
     *
     * A run parked on an approval is cancelled at once: every approval it is
     * waiting on reads as settled-cancelled ({@see self::approvalStatus()}),
     * and deciding one is refused.
     *
     * The run id must be this session's run. An id this session never issued
     * is `refused`, whatever session it belongs to.
     *
     * WHO MAY CANCEL IS THE APPLICATION'S DECISION, as with approve(): the
     * session is scoped to a participant, but "this user may stop THIS run"
     * is a question only the host can answer. Authorize before calling.
     */
    public function cancel(string $runId): CancelOutcome
    {
        $this->assertWritableResolution();
        if (! $this->runtime instanceof AgentRuntime || $this->heldLocks() === null) {
            return CancelOutcome::Unsupported;
        }
        if (preg_match('/\Arun_[0-9a-f]{24}\z/D', $runId) !== 1) {
            return CancelOutcome::Refused;
        }

        /** @var list<RunCancelRequested|RunCancelled> $events */
        $events = [];
        $outcome = $this->underCancelLock(function () use ($runId, &$events): CancelOutcome {
            // Read fresh: the run is written by whichever process executes it.
            $this->cachedState = null;
            $state = $this->state();
            $stored = is_array($state['run'] ?? null) ? $state['run'] : null;
            $run = $this->currentRun($stored);
            // A runner start still running before its task row (G-387): the
            // run it replaced is still the one a person may be parked on.
            $prior = $stored !== null && $run === $stored && ($stored['provisional'] ?? null) === true ? ($stored['prior'] ?? null) : null;
            if (is_array($prior) && ($prior['id'] ?? null) === $runId && ! $this->taskRowExists((string) ($stored['id'] ?? ''))) {
                $run = $prior;
            }
            if ($run === null) {
                return CancelOutcome::NoTurn;
            }
            if (($run['id'] ?? null) !== $runId) {
                $history = is_array($state['run_history'] ?? null) ? $state['run_history'] : [];

                return in_array($runId, $history, true) ? CancelOutcome::NotRunning : CancelOutcome::Refused;
            }
            $tag = static fn (string $key): ?string => is_string($run[$key] ?? null) ? $run[$key] : null;
            $requested = $this->cancelRequestedFor($runId);
            $status = $run['status'] ?? null;
            if ($status === 'running') {
                // Twice is harmless: the flag is already set, and the run
                // stops once. Only the first cancel announces it.
                if (! $requested) {
                    $this->recordCancel($runId);
                    $this->cancelTreeOf($runId);
                    $events[] = new RunCancelRequested($this->key(), $runId, $tag('parent_run_id'), $tag('root_run_id'),
                        $this->agent(), $tag('turn_id'), 'running');
                }

                return CancelOutcome::Ok;
            }
            if ($requested || $status === 'cancelled' || ! $this->parkedOn($runId)) {
                return CancelOutcome::NotRunning;
            }
            // Parked on a person: no process is running it, so it is settled
            // here. Its approvals now read as cancelled and cannot be decided.
            $this->recordCancel($runId);
            $this->cancelTreeOf($runId);
            $events[] = new RunCancelRequested($this->key(), $runId, $tag('parent_run_id'), $tag('root_run_id'),
                $this->agent(), $tag('turn_id'), 'parked');
            $events[] = new RunCancelled($this->key(), $runId, $tag('parent_run_id'), $tag('root_run_id'),
                $this->agent(), $tag('turn_id'));

            return CancelOutcome::Ok;
        });
        foreach ($events as $event) {
            event($event);
        }

        return $outcome;
    }

    /**
     * Where an approval stands (G-285): `pending`, `approved`, `denied`,
     * `cancelled` (its run was cancelled before anyone decided), `superseded`
     * (never decided, and a later turn ended it; or decided, and a subagent
     * runner start ended the call before any resume acted on it, so it never
     * ran), or `unknown` (this thread never issued it). An approval in a
     * cancelled run never reads `pending`.
     */
    public function approvalStatus(string $approvalId): string
    {
        // Read, never created: a read the tool-resolution view allows.
        $thread = Thread::existingAt($this->address());
        $runId = $thread?->approvalRunId($approvalId);
        if ($thread === null || $runId === null) {
            return 'unknown';
        }
        $decided = $thread->approvalDecisions();
        if (array_key_exists($approvalId, $decided)) {
            // A runner start ended the call, and it never ran (G-387): the
            // decision was never acted on, so the call reads as ended.
            if ($thread->endedByStart($approvalId)) {
                return 'superseded';
            }

            return $decided[$approvalId] ? 'approved' : 'denied';
        }
        if ($this->cancelRequestedFor($runId)) {
            return 'cancelled';
        }

        return in_array($approvalId, $thread->pendingApprovalIds(), true) ? 'pending' : 'superseded';
    }

    /**
     * Whether a person cancelled this run (G-285), read from the durable store.
     *
     * @internal Read by the runtime's cancel checks.
     */
    public function cancelRequestedFor(string $runId): bool
    {
        $record = $this->durable->get($this->cancelKey());
        $runs = is_array($record['runs'] ?? null) ? $record['runs'] : [];

        // A run of a run tree a person cancelled is cancelled with it,
        // whichever session the cancel was made on: the tree the run began
        // in, and nothing else (G-381).
        return array_key_exists($runId, $runs) || app(RunTrees::class)->treeCancelled($this, $this->runTree($runId));
    }

    /**
     * The durable run tree of the parked run an empty-prompt turn resumes
     * (G-381): the run its paused approvals were issued in, read from the
     * thread, so it holds after the ephemeral half has expired. Null for a
     * new prompt, or a run in no durable tree.
     *
     * @internal Read by RunTrees when a root turn begins.
     *
     * @return array{session: string, run: string}|null
     */
    public function resumedRunTree(string $prompt): ?array
    {
        if ($prompt !== '') {
            return null;
        }
        $thread = $this->thread();
        foreach ($thread->pausedApprovalIds() as $approvalId) {
            $runId = $thread->approvalRunId($approvalId);
            $tree = $runId === null ? null : $this->runTree($runId);
            if ($tree !== null) {
                return $tree;
            }
        }

        return null;
    }

    /**
     * The durable run tree a run of this session began in (G-381), or null
     * for a run in the host's tree, in none, or not remembered.
     *
     * @return array{session: string, run: string}|null
     */
    private function runTree(string $runId): ?array
    {
        $runs = $this->durable->get($this->runTreesKey())['runs'] ?? null;
        $tree = is_array($runs) && is_array($runs[$runId] ?? null) ? ($runs[$runId]['tree'] ?? null) : null;

        return is_array($tree) && is_string($tree['session'] ?? null) && is_string($tree['run'] ?? null)
            ? ['session' => $tree['session'], 'run' => $tree['run']] : null;
    }

    /**
     * Whether an empty-prompt turn would continue a run parked on approvals
     * that a person cancelled (G-285). Such a turn is refused: a cancelled
     * run never resumes.
     *
     * @internal
     */
    public function resumesCancelledRun(string $prompt): bool
    {
        if ($prompt !== '') {
            return false;
        }
        $thread = $this->thread();
        foreach ($thread->pausedApprovalIds() as $approvalId) {
            $runId = $thread->approvalRunId($approvalId);
            if ($runId !== null && $this->cancelRequestedFor($runId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * End a run as cancelled (G-285).
     *
     * @internal Called by the runtime when a cancel stopped the run.
     */
    public function cancelRun(string $id): self
    {
        return $this->finishRun($id, 'cancelled', ['failure' => 'cancelled']);
    }

    /**
     * Whether this run ended cancelled (G-285).
     *
     * @internal
     */
    public function runWasCancelled(string $id): bool
    {
        // The run as stored: a provisional run that ended is still this run.
        $stored = $this->storedRun();
        $run = $stored === null ? null : $this->withCancel($stored);

        return is_array($run) && ($run['id'] ?? null) === $id && ($run['status'] ?? null) === 'cancelled';
    }

    /** Whether this run's thread rows end on approvals nobody has decided yet. */
    private function parkedOn(string $runId): bool
    {
        $thread = $this->thread();
        foreach ($thread->pendingApprovalIds() as $approvalId) {
            if ($thread->approvalRunId($approvalId) === $runId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mark the run tree a cancelled run belongs to, so the other runs of it
     * parked in other sessions, or running in other processes, stop too: the
     * tree it began in (G-381). A run in the host's tree, or in none, marks
     * nothing; only its own flag is set.
     */
    private function cancelTreeOf(string $runId): void
    {
        $tree = $this->runTree($runId);
        if ($tree !== null) {
            app(RunTrees::class)->cancelTree($this, $tree);
        }
    }

    /**
     * Keep a run's tree under its own durable key (G-381), so it never
     * rewrites the session's other durable state, written under the session
     * lock the run begins in. The newest runs are kept, by an explicit
     * sequence rather than the store's key order, and a run whose approvals
     * the thread is still parked on is never dropped: a resume that keeps
     * failing must not forget the tree it resumes.
     *
     * @param  array{tree: mixed, depth: mixed}  $membership
     */
    private function rememberRunTree(string $id, array $membership): void
    {
        $this->assertWritableResolution();
        $stored = $this->durable->get($this->runTreesKey()) ?? [];
        $runs = is_array($stored['runs'] ?? null) ? $stored['runs'] : [];
        $seq = is_int($stored['seq'] ?? null) ? $stored['seq'] + 1 : 1;
        $runs[$id] = [...$membership, 'seq' => $seq];
        $parked = [];
        $thread = $this->thread();
        foreach ($thread->pausedApprovalIds() as $approvalId) {
            $runId = $thread->approvalRunId($approvalId);
            if ($runId !== null) {
                $parked[$runId] = true;
            }
        }
        $order = [];
        foreach ($runs as $runId => $entry) {
            $order[(string) $runId] = is_array($entry) && is_int($entry['seq'] ?? null) ? $entry['seq'] : 0;
        }
        arsort($order, SORT_NUMERIC);
        $kept = [];
        foreach ($order as $runId => $unused) {
            if (count($kept) < self::RUN_HISTORY || isset($parked[$runId])) {
                $kept[$runId] = $runs[$runId];
            }
        }
        $this->durable->put($this->runTreesKey(), ['seq' => $seq, 'runs' => $kept]);
    }

    private function runTreesKey(): string
    {
        return $this->key().':run-trees';
    }

    private function recordCancel(string $runId): void
    {
        $record = $this->durable->get($this->cancelKey()) ?? [];
        $runs = is_array($record['runs'] ?? null) ? $record['runs'] : [];
        $runs[$runId] = now()->toIso8601String();
        // Bounded: the newest flags are the ones a run or an approval can still ask about.
        $runs = array_slice($runs, -self::RUN_HISTORY, preserve_keys: true);
        $this->durable->put($this->cancelKey(), ['runs' => $runs]);
    }

    /**
     * Run a cancel decision, or a run's end, under this session's cancel lock,
     * so a cancel racing a run's last step cannot both return `ok` and leave
     * the run `completed`: whichever takes the lock second sees the other.
     *
     * Its own key, never the session lock, which the running run holds. The
     * order is always session lock, then cancel lock, so they cannot deadlock.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function underCancelLock(Closure $work): mixed
    {
        $store = $this->heldLocks();
        if ($store === null) {
            return $work();
        }
        $key = $this->key().':cancel-lock';
        $token = $store->acquireFencedLock($key, 10, 10);
        try {
            return $work();
        } finally {
            $store->releaseFencedLock($key, $token);
        }
    }

    /** The ephemeral store, when it can hold a lock outside a callback; null when it cannot. */
    private function heldLocks(): ?HeldSessionLocks
    {
        $store = $this->ephemeral;
        if (! $store instanceof HeldSessionLocks
            || ($store instanceof IndexedSessionStore && ! $store->supportsHeldLocks())) {
            return null;
        }

        return $store;
    }

    private function cancelKey(): string
    {
        return $this->key().':cancel';
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
        $run = $this->storedRun();
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
        return $this->writeAll([$key => $value]);
    }

    /** @param array<string, mixed> $values written together, in one store write */
    protected function writeAll(array $values): self
    {
        $this->assertWritableResolution();
        $state = $this->state();
        foreach ($values as $key => $value) {
            $state[$key] = $value;
        }

        return $this->putState($state);
    }

    /** @param array<string, mixed> $state the whole ephemeral state, written in one store write */
    private function putState(array $state): self
    {
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
        // The run as stored: a runner start's provisional run is still this run.
        $stored = $this->storedRun();
        $run = $stored === null ? null : $this->withCancel($stored);
        if (! is_array($run) || ($run['id'] ?? null) !== $id) {
            throw new \LogicException('Harness run state changed while the run was active.');
        }

        // Under the cancel lock (G-285): a cancel that set the flag before
        // this point ends the run cancelled, even when its last step had
        // already finished, so a cancel that returned `ok` is never followed
        // by a run that reads `completed`.
        $this->underCancelLock(function () use ($id, $run, $status, $details): void {
            if ($status !== 'cancelled' && $this->cancelRequestedFor($id)) {
                $details = ['failure' => 'cancelled', 'ended_as' => $status];
                $status = 'cancelled';
            }
            $this->write('run', [
                ...$run, ...$details, 'status' => $status, 'finished_at' => now()->toIso8601String(),
            ]);
        });

        // G-360: when the run is over, the plan reads the thread it left:
        // the approvals the run staged, and the ones a new message ended.
        app(Plans::class)->afterTurn($this);
        // A root run that holds part of a run tree's budget settles it.
        app(RunTrees::class)->finished($this, $id);

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
