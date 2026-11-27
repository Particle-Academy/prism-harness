<?php

declare(strict_types=1);

namespace Prism\Harness\Models;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Prism\Harness\Context\NoCompaction;
use Prism\Harness\Context\ToolPairGuard;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\Sessions\DatabaseAddressIndex;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Subagents\RunBudgets;
use Prism\Harness\Support\InRunRecording;
use Prism\Harness\Support\MessageMapper;
use Prism\Harness\Support\PausedStep;
use Prism\Harness\Support\StepRows;
use Prism\Harness\Support\ThreadReads;
use Prism\Harness\Support\ThreadView;
use Prism\Harness\Support\ToolResultRuns;
use Prism\Harness\Tools\CanonicalJson;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Thread as ThreadContract;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;

/**
 * A stored conversation.
 *
 * Satisfies Prism's `Thread` contract, so it can be handed straight to
 * `withThread()` and Prism reads the history from the database instead of the
 * caller rebuilding a message array on every request.
 *
 * Addressed by participant AND scope, not by participant alone. One user can
 * hold several unrelated conversations at once — a support chat and a coding
 * session are not the same thread and must not bleed into one another — so the
 * scope is part of the address rather than a label hung off it.
 *
 * @property int $id
 * @property int|null $parent_thread_id
 * @property string $scope
 * @property string|null $root_run_id
 * @property string|null $title
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $retired_at
 * @property string|null $live_key
 * @property string|null $agent_id
 * @property string|null $participant_ref_type
 * @property string|null $participant_ref_id
 * @property string|null $address_key
 * @property-read Collection<int, ThreadMessage> $storedMessages
 * @property-read self|null $parentThread
 */
class Thread extends Model implements ThreadContract
{
    protected $table = 'harness_threads';

    /**
     * Explicitly listed rather than `$guarded = []`. A host application that
     * reaches for `Thread::create($request->all())` should not be able to set
     * the participant columns from request input and address somebody else's
     * conversation.
     *
     * @var list<string>
     */
    protected $fillable = [
        'participant_type',
        'participant_id',
        'scope',
        'title',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $thread): void {
            $fields = ['participant_type', 'participant_id', 'participant_ref_type', 'participant_ref_id', 'scope', 'agent_id'];
            $indexed = $thread->agent_id !== null || $thread->participant_ref_type !== null
                || $thread->getOriginal('agent_id') !== null || $thread->getOriginal('participant_ref_type') !== null;
            if ($thread->exists && ($indexed || config('prism-harness.addresses.immutable_legacy_threads', false)) && $thread->isDirty($fields)) {
                throw new LogicException('A resolved harness thread address is immutable; import into a new address explicitly.');
            }
            $address = $thread->address();
            // Publish ownership before the row. All retired generations keep
            // this indexed address_key; failed row writes leave harmless entries.
            if ($address->agent !== null || $address->reference) {
                DatabaseAddressIndex::configured($thread->getConnection())->record($address);
                $thread->address_key = $address->indexKey();
            } elseif ($thread->isDirty('address_key')) {
                $thread->address_key = $thread->getOriginal('address_key');
            }
            // Derived, never mass-assignable. Retirement releases the unique key
            // in the very UPDATE that stamps retired_at. An unrelated save on a
            // stale model must not reclaim a key released by another worker.
            if (! $thread->exists || $thread->isDirty(['participant_type', 'participant_id', 'scope', 'retired_at'])) {
                $thread->live_key = $thread->retired_at !== null ? null : $address->threadKey();
            } elseif ($thread->isDirty('live_key')) {
                $thread->live_key = $thread->getOriginal('live_key');
            }
        });
    }

    /** The address this thread is resolved by: the session it belongs to. */
    private function address(): SessionAddress
    {
        return new SessionAddress(
            $this->participant_ref_type ?? $this->getAttribute('participant_type'),
            $this->participant_ref_id ?? ($this->getAttribute('participant_id') === null ? null : (string) $this->getAttribute('participant_id')),
            $this->scope,
            $this->agent_id,
            $this->participant_ref_type !== null,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<ThreadMessage, $this>
     */
    public function storedMessages(): HasMany
    {
        $relation = $this->hasMany(ThreadMessage::class, 'thread_id');

        // Ordered on the underlying query rather than by chaining, which would
        // hand back a builder instead of the relation. Ordering belongs here so
        // that every read — eager load, lazy cursor, count — is oldest-first
        // without each caller having to remember.
        $relation->getQuery()->orderBy('position');

        return $relation;
    }

    /**
     * The LIVE thread at this address, created if there is not one.
     *
     * A session is RESOLVED, not constructed: a fresh worker asking for the
     * same address must land on the same conversation rather than starting a
     * new one.
     *
     * "Live" is the whole of the change here: a retired thread is skipped, so
     * the next resolve after {@see self::retire()} starts a fresh conversation
     * at the same address rather than reopening the old one. Retired rows stay
     * exactly where they are — see the migration for why that is not optional.
     */
    public static function forParticipant(Model|ParticipantRef $participant, string $scope, ?string $agent = null): self
    {
        return static::forAddress(SessionAddress::forParticipant($participant, $scope, $agent));
    }

    public static function findLive(Model|ParticipantRef $participant, string $scope, ?string $agent = null): ?self
    {
        return static::lookupAddress(SessionAddress::forParticipant($participant, $scope, $agent))->first();
    }

    public static function findShared(string $scope, ?string $agent = null): ?self
    {
        return static::lookupAddress(SessionAddress::forParticipant(null, $scope, $agent))->first();
    }

    /**
     * Resolve a live thread addressed by scope alone, without a participant.
     *
     * This makes no claim about who may read it. A host may restrict a shared
     * thread to admins only; authorization is the host's decision. A shared
     * conversation belonging to an agency should instead use forParticipant()
     * with that agency, because the agency is its participant.
     */
    public static function shared(string $scope, ?string $agent = null): self
    {
        return static::forAddress(SessionAddress::forParticipant(null, $scope, $agent));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function addressFields(SessionAddress $address): array
    {
        if ($address->agent === null && ! $address->reference) {
            // Keep the published legacy query and create fields: these columns
            // exist before the optional named-address migration is installed.
            return [
                'participant_type' => $address->participantType,
                'participant_id' => $address->participantId,
                'scope' => $address->scope,
            ];
        }
        DatabaseAddressIndex::configured(static::query()->getModel()->getConnection());

        return [
            'participant_type' => $address->reference ? null : $address->participantType,
            'participant_id' => $address->reference ? null : $address->participantId,
            'participant_ref_type' => $address->reference ? $address->participantType : null,
            'participant_ref_id' => $address->reference ? $address->participantId : null,
            'scope' => $address->scope,
            'agent_id' => $address->agent,
        ];
    }

    /** @return Builder<self> */
    protected static function lookupAddress(SessionAddress $address): Builder
    {
        /** @var Builder<self> $query */
        $query = static::query()->where(static::addressFields($address))
            ->where('live_key', $address->threadKey())
            ->whereNull('retired_at')
            ->orderByDesc('id');

        return $query;
    }

    /**
     * The live thread at this address, or null, creating nothing.
     *
     * @internal Read by the subagent runner before it touches a child.
     */
    public static function existingAt(SessionAddress $address): ?self
    {
        /** @var self|null $live */
        $live = static::lookupAddress($address)->first();

        return $live;
    }

    public static function forAddress(SessionAddress $address): self
    {
        $lookup = static::lookupAddress($address);
        /** @var self|null $live */
        $live = (clone $lookup)->first();
        if ($live !== null) {
            return $live;
        }

        try {
            // A savepoint keeps PostgreSQL's outer transaction usable after a
            // rejected INSERT. Lookup-only fields never enter the create data.
            return static::query()->withSavepointIfNeeded(function () use ($address): self {
                $thread = static::query()->getModel()->newInstance();
                // Trusted address fields are not mass-assignable request input.
                $thread->forceFill(array_filter(static::addressFields($address), fn (mixed $value): bool => $value !== null));
                $thread->save();

                return $thread;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Read the writer, not a replica. A locking read also sees the winner
            // inside an InnoDB REPEATABLE READ transaction instead of its old snapshot.
            /** @var self|null $winner */
            $winner = $lookup->useWritePdo()->lockForUpdate()->first();

            return $winner ?? throw $exception;
        }
    }

    /**
     * End this conversation without ending the record of it.
     *
     * The next {@see self::forParticipant()} at the same address returns a new,
     * empty thread. This one keeps every message it ever had, still readable,
     * still addressable by id — which is what separates "start a new chat" from
     * "delete my history", two things a single button is very often asked to
     * mean at once.
     *
     * Idempotent: retiring an already-retired thread keeps the original
     * timestamp, because the question the column answers is when the
     * conversation ENDED, not when someone last pressed the button.
     */
    public function retire(): self
    {
        if ($this->retired_at === null) {
            // `freshTimestamp()` rather than `now()`: the model's own clock,
            // typed as the Carbon the property declares, and the same instant
            // Eloquent would stamp `updated_at` with on this save.
            $this->retired_at = $this->freshTimestamp();
            $this->save();
        }

        return $this;
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    /**
     * Record that this thread is a subagent's, run beneath another.
     *
     * Assigned directly rather than through `$fillable` for the same reason the
     * participant columns are not fillable: lineage decides which conversation
     * a run belongs to, and nothing reachable from request input should be able
     * to set it. The harness writes it; a host application never does.
     */
    public function linkBeneath(self $parent, string $rootRunId): self
    {
        $this->parent_thread_id = $parent->getKey();
        $this->root_run_id = $rootRunId;
        $this->save();

        return $this;
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentThread(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_thread_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAddressedTo(Builder $query, Model|ParticipantRef $participant, ?string $scope = null, ?string $agent = null): Builder
    {
        $fields = static::addressFields(SessionAddress::forParticipant($participant, $scope ?? '', $agent));
        unset($fields['scope']);
        $query->where($fields);

        return $scope === null ? $query : $query->where('scope', $scope);
    }

    /**
     * The conversation so far, oldest first, after compaction.
     *
     * Yields rather than building an array: Prism materialises what it needs
     * for the payload, and this way a long history is paged out of the database
     * in chunks instead of every row being hydrated at once.
     *
     * COMPACTION SHORTENS THE VIEW, NEVER THE STORAGE. Every row stays where it
     * is; what changes is which of them are replayed to the model. That
     * separation is what makes the decision reversible — change the strategy and
     * the next turn sees a different window over the same unaltered history —
     * and it is why the thread can still be rendered whole for a human while
     * the model sees less.
     *
     * The default strategy replays everything, so this is exactly what it has
     * always been until an application chooses otherwise.
     *
     * A GENERATOR, not the relation. `$thread->messages()->count()` therefore
     * fails with "Call to undefined method Generator::count()" -- reach for
     * {@see self::storedMessages()} when you want the Eloquent relation to
     * count, paginate or query. Noted here because the error names Generator
     * and not this method, so it points at PHP rather than at the choice made
     * on this line; a consumer lost time to it.
     *
     * @return Generator<int, Message>
     */
    #[\Override]
    public function messages(): Generator
    {
        $strategy = $this->compactionStrategy();

        if ($strategy instanceof NoCompaction) {
            // The default: no strategy to materialise the conversation for.
            // The rows are read once and placed with their steps (round 15).
            yield from ToolResultRuns::fold($this->replayRows());

            return;
        }

        $messages = iterator_to_array(ToolResultRuns::fold($this->replayRows()), false);

        $outcome = app(ToolPairGuard::class)->enforce($strategy->compact($messages));

        if ($outcome->compacted()) {
            // Handed over BEFORE the turn is sent, so the detail is recoverable
            // by the very turn that lost it — an agent that calls a recall tool
            // in the same run finds what was just evicted. Doing this afterwards
            // would leave exactly one turn unable to see what it needed.
            //
            // A sink that fails must not take the turn down with it: the model
            // can still answer from what remains, and trading a degraded
            // conversation for no conversation is the wrong way round.
            // Resolved OUTSIDE the try, deliberately.
            //
            // An earlier version built the scope inside it and called a method
            // that does not exist on this model. The catch-all turned that
            // programming error into a silent no-op: compaction ran, the
            // messages were evicted, NOTHING was ever stored, and the tests
            // still passed because they asserted on the window rather than on
            // the sink. A guard meant for a failing sink must not also swallow
            // our own bugs.
            $sink = app(EvictionSink::class);
            $scope = (string) $this->getKey();

            try {
                $sink->store($outcome->evicted, $scope);
            } catch (Throwable $failure) {
                report($failure);
            }
        }

        yield from $outcome->kept;
    }

    /**
     * Every stored row as a message, oldest first and NOT folded: what a
     * runner start reads to know which calls of a step have a result of
     * their own (G-387 round 13). A fold keys results by call id. Placed as
     * the replay places them ({@see self::replayRows()}): a started call is
     * answered there, so a start never ends a call that may have run.
     *
     * @internal
     *
     * @return list<Message>
     */
    public function storedHistory(): array
    {
        return $this->replayRows();
    }

    /**
     * Every stored row as a Prism message, oldest first, before the fold,
     * placed as {@see ThreadView::placed()} places them (G-387 rounds 15 and
     * 16): started and journal rows never sent, marked rows with their step,
     * every call of a step no longer paused answered, and every decision the
     * one that stood.
     *
     * @return list<Message>
     */
    private function replayRows(): array
    {
        return array_map(static fn (array $row): Message => MessageMapper::fromArray($row['type'], $row['payload']), $this->view()->placed());
    }

    /**
     * The rows in replay order (see {@see self::replayRows()}). Pure: the
     * thread-rows corpus drives it through the fold (G-387 round 15).
     *
     * @internal
     *
     * @param  list<array{position: int, type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}>  $rows  oldest first
     * @return list<array{type: string, payload: array<array-key, mixed>}>
     */
    public static function placedRows(array $rows): array
    {
        return ThreadView::of($rows)->placed();
    }

    /**
     * This thread's rows, read as {@see ThreadReads} reads them: once per
     * locked operation, then only what was appended since.
     */
    private function view(): ThreadView
    {
        $key = $this->getKey();

        return $key === null ? ThreadView::of([]) : ThreadReads::view($this->getConnection(), (int) $key);
    }

    /**
     * The configured strategy, resolved per call.
     *
     * Per call rather than cached on the model, because a strategy is a
     * container binding an application may swap — in a test, or per mode — and
     * a thread holding the one that existed when it was hydrated would ignore
     * that silently.
     */
    private function compactionStrategy(): CompactionStrategy
    {
        return app(CompactionStrategy::class);
    }

    /**
     * Append messages to the end of the conversation.
     *
     * Made for `$response->messages`, which is the full exchange including the
     * tool calls and results from every step — so recording a turn is one call
     * and a conversation interrupted mid-tool-loop resumes where it stopped.
     *
     * `$runId` attributes each row to the run that produced it. Optional, and
     * null for anything written outside a run — but without it a parent and a
     * subagent writing into the same conversation are indistinguishable after
     * the fact, which makes a tree impossible to reconstruct from storage.
     * It is NEVER a trust signal (G-387 round 16): a row this call writes is a
     * host row whatever run id it names, and no harness mark on it is read.
     * Only the runtime's own write, under its private token, stores rows as
     * the harness's (the `harness_written` column, which nothing a host
     * passes can set).
     *
     * A row that supersedes the paused step is NEVER refused (G-387 round
     * 15): a call a run has started (its write-ahead started row) is
     * answered-in-progress, so it gets no replaced denial and keeps the one
     * outcome its real result gives it. No lock is taken here, so a host tool
     * that records on its own session mid-run neither waits nor fails.
     *
     * `$parked` and `$endedByStart` are the RUNTIME's: given by anyone else
     * (no private token), record() throws InvalidArgumentException and
     * writes nothing (PHP run17 #1).
     *
     * @param  iterable<Message>  $messages
     * @param  list<string>|null  $toolNames  the actual offer, stored only beside pending approvals
     * @param  array{harness_steps_used: int, harness_steps_left: int}|null  $parked  runtime-only: stored on the row a run parks on (G-387)
     * @param  list<array{message: Message, approvals: list<string>}>  $endedByStart  runtime-only: the result rows a runner start ENDED calls with, never ran (G-387), each marked with the approval ids of the calls it ended
     */
    public function record(iterable $messages, ?string $runId = null, ?array $toolNames = null, ?array $parked = null, array $endedByStart = []): self
    {
        $messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        // The runtime's OWN record call, and only that one (G-406): claimed
        // on entry, so a record() a host makes while this write runs (from a
        // model event, say) is outside, whatever run id it names.
        $context = $runId === null ? null : InRunRecording::take($runId);
        $inRun = $context === null ? null : $runId;
        if ($inRun === null && ($parked !== null || $endedByStart !== [])) {
            throw new InvalidArgumentException('Only the harness runtime records a parked count or the rows a runner start ended calls with.');
        }
        $answers = $context['answers'] ?? null;
        $journal = array_values(array_filter(is_array($context['journal'] ?? null) ? $context['journal'] : [], 'is_int'));

        return $this->recordRows($messages, $runId, $toolNames, $parked, $endedByStart, $inRun, is_int($answers) ? $answers : null, $journal);
    }

    /**
     * @param  list<Message>  $messages
     * @param  list<string>|null  $toolNames
     * @param  array{harness_steps_used: int, harness_steps_left: int}|null  $parked
     * @param  list<array{message: Message, approvals: list<string>}>  $endedByStart
     * @param  int|null  $answers  the step the run's result rows answer before it writes a step of its own
     * @param  list<int>  $journal  the run's journal rows this write answers, removed with it (round 16)
     */
    private function recordRows(array $messages, ?string $runId, ?array $toolNames, ?array $parked, array $endedByStart, ?string $inRun, ?int $answers = null, array $journal = []): self
    {
        // ATOMIC ACROSS THE WHOLE TURN, and serialised against other recorders.
        //
        // Reading the high-water mark and then inserting is a read-modify-write:
        // two requests recording to one thread both read the same max and both
        // claim the next slot. `unique(thread_id, position)` means the loser
        // gets a QueryException rather than a silently misordered conversation
        // — the right failure mode, and still a lost turn.
        //
        // Two workers on one thread is NORMAL here, not exotic: a queued job
        // recording a response while the user sends another message is enough,
        // and an approval is written outside the run lock by design, because
        // taking that lock here would deadlock against the resume it precedes.
        //
        // The transaction also makes a multi-step tool exchange all-or-nothing.
        // A turn that half-writes leaves a tool call with no result, which
        // replays to the model as an unanswered question rather than as an
        // error anyone can see.
        return DB::transaction(function () use ($messages, $runId, $toolNames, $parked, $endedByStart, $inRun, $answers, $journal): self {
            // Locks the thread ROW so a concurrent recorder waits instead of
            // reading the same max. A no-op on SQLite, which is why the suite
            // cannot exercise this and why the guarantee lives in the database.
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($inRun !== null && $journal !== []) {
                // The journal rows of the calls whose results this write
                // stores (round 16): removed in the same write, so a call is
                // journaled until its result is durable, and never after.
                $owned = $this->storedMessages()->whereIn('id', $journal)->where('run_id', $inRun);
                if (ThreadMessage::hasTrustColumn($this->getConnectionName())) {
                    $owned->where('harness_written', true);
                }
                $gone = array_map('intval', $owned->pluck('id')->all());
                $owned->delete();
                ThreadReads::removed((int) $this->getKey(), $gone);
            }

            $position = (int) $this->storedMessages()->max('position');

            // DECISION B (G-387 round 13): a row that supersedes the paused
            // step (an assistant row or a user row) is recorded after ONE
            // denial for each of that step's gated calls that has no result,
            // in this batch or before it. A runner start ends them itself.
            if ($endedByStart === []) {
                $messages = $this->withSupersededDenials($messages, $inRun);
            }

            // The step the run's own result rows answer (round 15): the step it
            // resolves, until it writes a step of its own in this write.
            $target = $inRun === null ? null : $answers;
            foreach ($messages as $message) {
                $endedApprovals = null;
                foreach ($endedByStart as $ended) {
                    if ($message instanceof ToolResultMessage && $ended['message'] === $message) {
                        $endedApprovals = $ended['approvals'];
                    }
                }
                $denials = $message instanceof ToolResultMessage && $this->supersededRows !== null && isset($this->supersededRows[$message]);
                $marked = $inRun !== null && $target !== null && ! $denials && $message instanceof ToolResultMessage && $message->toolResults !== [];
                if ($marked) {
                    // Exactly one stored outcome per call: what the step
                    // already holds an outcome for (a result, or the denial
                    // a superseding write gave a call that had not started)
                    // is not written again.
                    $kept = $this->withoutAnswered($message, $target);
                    if ($kept === null) {
                        continue;
                    }
                    $message = $kept;
                    $marked = $message->toolResults !== [];
                }
                $payload = $denials
                    ? self::supersededPayload($message, $this->supersededRows[$message])
                    : self::rowPayload($message, $toolNames, $parked, $endedApprovals);
                if ($message instanceof AssistantMessage && $message->toolApprovalRequests !== []) {
                    // Each request bound to the exact call it asks about (round 15).
                    $payload = StepRows::withBoundRequests($payload);
                }
                if ($marked) {
                    // Harness metadata, beside the results: the step they
                    // answer, wherever the row lands (round 15).
                    $payload[StepRows::ANSWERS_STEP] = ['position' => $target];
                }
                // The harness's own row: the runtime's write, or a denial the
                // harness itself gave a superseded step (round 16).
                $this->store(++$position, MessageMapper::typeOf($message), $runId, $payload, $inRun !== null || $denials);
                if ($inRun !== null && $message instanceof AssistantMessage && $message->toolCalls !== []) {
                    $target = $position;
                }
            }

            return $this->afterRecording();
        });
    }

    /**
     * Store one row. `$harness`: the runtime wrote it (the trust column no
     * public write sets, G-387 round 16).
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function store(int $position, string $type, ?string $runId, array $payload, bool $harness): ThreadMessage
    {
        $row = $this->storedMessages()->make(['position' => $position, 'type' => $type, 'run_id' => $runId, 'payload' => $payload]);
        if (ThreadMessage::hasTrustColumn($this->getConnectionName())) {
            $row->forceFill(['harness_written' => $harness]);
        }
        $row->save();

        return $row;
    }

    /**
     * The payload {@see self::record()} stores for one message: what
     * MessageMapper writes, then the harness's own metadata, each key only
     * on the row it belongs to. Pure, so the cross-language thread-rows
     * corpus pins the stored shape by driving this rather than a copy of it.
     *
     * The ended mark (G-387) is the reference shape every port writes: a
     * top-level `harness_ended_by_start` AFTER `tool_approval_responses`,
     * holding `{approval_ids: [...]}` in the order given.
     *
     * @internal
     *
     * @param  list<string>|null  $toolNames  the actual offer, stored only beside pending approvals
     * @param  array{harness_steps_used: int, harness_steps_left: int}|null  $parked  stored on the row a run parks on
     * @param  list<string>|null  $endedApprovals  set when this is the row a runner start ENDED calls with: the approvals those calls were asked under
     * @return array<string, mixed>
     */
    public static function rowPayload(Message $message, ?array $toolNames = null, ?array $parked = null, ?array $endedApprovals = null): array
    {
        $payload = MessageMapper::toArray($message);
        if ($message instanceof AssistantMessage && $message->toolApprovalRequests !== [] && $toolNames !== null) {
            // Harness metadata on the same durable approval record.
            // MessageMapper does not send it back to the provider.
            $payload['harness_tool_names'] = array_values(array_unique($toolNames));
        }
        if ($message instanceof AssistantMessage && $message->toolApprovalRequests !== [] && $parked !== null) {
            // The steps the parked run used, its children included, and
            // what it had left: the count its resume continues (G-387).
            $payload['harness_steps_used'] = $parked['harness_steps_used'];
            $payload['harness_steps_left'] = $parked['harness_steps_left'];
        }
        if ($message instanceof ToolResultMessage && $endedApprovals !== null) {
            // Harness metadata, beside (never inside) the results: these
            // calls were ended, never run, and these are the approvals they
            // were asked under. A tool controls only its result text, never
            // this key.
            $payload[self::ENDED_BY_START] = ['approval_ids' => array_values(array_filter($endedApprovals, 'is_string'))];
        }

        return $payload;
    }

    /**
     * `$message` without the results whose call in the step at `$position`
     * already has an outcome; null when nothing is left to write.
     */
    private function withoutAnswered(ToolResultMessage $message, int $position): ?ToolResultMessage
    {
        $view = $this->view();
        $index = $view->at[$position] ?? null;
        if ($index === null || ! StepRows::isStep($view->rows[$index]['type'], $view->rows[$index]['payload'])) {
            return $message;
        }
        $kept = self::newResults($view->ownedPayloads($index), $message->toolResults);
        if (count($kept) === count($message->toolResults)) {
            return $message;
        }

        return $kept === [] && $message->toolApprovalResponses === [] ? null : new ToolResultMessage($kept, $message->toolApprovalResponses);
    }

    /**
     * Of `$results`, the ones a step's rows do not already hold (round 15;
     * pinned by the corpus in round 16). A result is the same as one the
     * step holds when its call id, tool name and arguments are (the fold's
     * key), occurrence by occurrence: what any ONE row of the step holds of a
     * key is already written, and only results beyond that are new. So a
     * result answers the first call that has no outcome, and an answer row
     * writes ONLY the new results.
     *
     * @param  list<array<array-key, mixed>>  $owned  the step's result payloads (honoured)
     * @param  list<ToolResult>  $results
     * @return list<ToolResult>
     */
    public static function newResults(array $owned, array $results): array
    {
        $key = static fn (mixed $id, mixed $name, mixed $args): string => (string) json_encode([is_scalar($id) ? (string) $id : '', is_string($name) ? $name : '', StepRows::argsDigest($args)]);
        $held = [];
        foreach ($owned as $payload) {
            $inRow = [];
            foreach (is_array($payload['tool_results'] ?? null) ? $payload['tool_results'] : [] as $entry) {
                $k = is_array($entry) ? $key($entry['tool_call_id'] ?? null, $entry['tool_name'] ?? null, $entry['args'] ?? []) : '';
                $inRow[$k] = ($inRow[$k] ?? 0) + 1;
            }
            foreach ($inRow as $k => $count) {
                $held[$k] = max($held[$k] ?? 0, $count);
            }
        }
        $kept = [];
        $here = [];
        foreach ($results as $result) {
            $k = $key($result->toolCallId, $result->toolName, $result->args);
            $here[$k] = ($here[$k] ?? 0) + 1;
            if ($here[$k] > ($held[$k] ?? 0)) {
                $kept[] = $result;
            }
        }

        return $kept;
    }

    /**
     * The row the runtime stores when it answers the step at `$position` of
     * `$rows` with `$message` (G-387 round 16): only the new results
     * ({@see self::newResults()}), its decisions, and the step mark last; null
     * when nothing is left to write. Pure: the thread-rows corpus pins it.
     *
     * @internal
     *
     * @param  list<array{position: int, type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}>  $rows
     * @return array<string, mixed>|null
     */
    public static function answerPayload(array $rows, int $position, ToolResultMessage $message): ?array
    {
        $view = ThreadView::of($rows);
        $index = $view->at[$position] ?? null;
        $kept = $index === null ? $message->toolResults : self::newResults($view->ownedPayloads($index), $message->toolResults);
        if ($kept === [] && $message->toolApprovalResponses === []) {
            return null;
        }
        $payload = MessageMapper::toArray(new ToolResultMessage($kept, $message->toolApprovalResponses));
        if ($kept !== []) {
            $payload[StepRows::ANSWERS_STEP] = ['position' => $position];
        }

        return $payload;
    }

    /** Why the last {@see self::recordStarted()} refused, when it can say: {@see self::START_DENIED}, else null. */
    public function startRefusal(): ?string
    {
        return $this->startRefusal;
    }

    /**
     * Record the write-ahead STARTED row (G-387 round 15) for the call of the
     * step at `$position` that the runtime is about to run, under the
     * runtime's private single-use token. True: recorded, run it. False: the
     * call is no longer open (it has a result, a started row, or the denial
     * of a superseding write), or the decision that stands for it is no
     * longer an approval, and it must NOT run.
     *
     * @internal Called by the runtime, inside its own token window.
     *
     * @param  mixed  $arguments  the call's arguments, as the resolver holds them
     */
    public function recordStarted(string $runId, int $position, string $callId, string $toolName, mixed $arguments): bool
    {
        if (InRunRecording::take($runId) === null) {
            throw new LogicException('Only the harness runtime records a started row.');
        }

        $this->startRefusal = null;

        return DB::transaction(function () use ($runId, $position, $callId, $toolName, $arguments): bool {
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();
            // The start gate decides on the STORE (round 17, PHP run19 Info
            // 5b): what this operation kept of the step's rows is checked
            // first, so a row changed in place in the same second as the
            // latest update (invisible to the kept rows' fingerprint) is read
            // as it now is.
            ThreadReads::verifyFrom($this->getConnection(), (int) $this->getKey(), $position);
            $view = $this->view();
            $index = $view->at[$position] ?? null;
            if ($index === null || ! StepRows::isStep($view->rows[$index]['type'], $view->rows[$index]['payload'])) {
                return false;
            }
            $paired = self::pairedStep($view->step($index), $view->ownedPayloads($index));
            $digest = is_array($arguments) ? CanonicalJson::digest($arguments) : null;
            $at = null;
            foreach ([true, false] as $byArguments) {
                foreach ($paired['calls'] as $i => $call) {
                    $stored = is_array($call) ? ($call['arguments'] ?? []) : [];
                    $stored = is_string($stored) ? json_decode($stored, true) : $stored;
                    if ($at === null && ! $paired['answered'][$i] && is_array($call) && ($call['id'] ?? null) === $callId && ($call['name'] ?? null) === $toolName
                        && (! $byArguments || ($digest !== null && is_array($stored) && CanonicalJson::digest($stored) === $digest))) {
                        $at = $i;
                    }
                }
            }
            if ($at === null) {
                return false;
            }
            // The call runs only under its OWN request, bound to exactly this
            // call: id, tool and the digest of the arguments the person was
            // shown (round 15). One approval never runs more than one call.
            $approval = null;
            foreach ($paired['requests'] as ['request' => $request, 'call' => $call]) {
                if ($call === $at && is_string($request['approval_id'] ?? null) && ($request['tool_call_id'] ?? null) === $callId) {
                    $approval = StepRows::binds($request, $paired['calls'][$at])
                        && ($digest === null || StepRows::argsDigest($paired['calls'][$at]['arguments'] ?? []) === $digest) ? $request['approval_id'] : false;
                }
            }
            // The decision that STANDS for it, read now, by the one rule
            // (round 16): a denial recorded since the run read its history,
            // while the call had no outcome, stops the call (G-401).
            if (! is_string($approval) || (($paired['standing'][$approval] ?? null)['approved'] ?? false) !== true) {
                // Said precisely (round 17, Py run21 I3): a denial that
                // stands for it is not the same as a call no longer open.
                $this->startRefusal = is_string($approval) && is_array($paired['standing'][$approval] ?? null) ? self::START_DENIED : null;

                return false;
            }
            $this->store((int) $this->storedMessages()->max('position') + 1, MessageMapper::TYPE_TOOL_RESULT, $runId,
                self::startedPayload($callId, $toolName, $approval, $position), true);
            $this->afterRecording();

            return true;
        });
    }

    /**
     * The stored payload of a started row (G-387 round 15). Pure: the
     * cross-language thread-rows corpus pins it.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public static function startedPayload(string $callId, string $toolName, string $approvalId, int $position): array
    {
        return [
            ...MessageMapper::toArray(new ToolResultMessage([])),
            StepRows::STARTED => ['tool_call_id' => $callId, 'tool_name' => $toolName, 'approval_id' => $approvalId],
            StepRows::ANSWERS_STEP => ['position' => $position],
        ];
    }

    /**
     * Record the write-ahead JOURNAL row (G-387 round 16) for an ungated call
     * the runtime is about to run inside a model step, under its private
     * single-use token: the call's step is not stored yet, so the row names
     * the call itself. The write that stores the call's result removes it;
     * one left standing reads (and replays) as interrupted. The row's id.
     *
     * @internal Called by the runtime, inside its own token window.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function recordJournal(string $runId, string $toolName, array $arguments): int
    {
        if (InRunRecording::take($runId) === null) {
            throw new LogicException('Only the harness runtime records a journal row.');
        }

        return DB::transaction(function () use ($runId, $toolName, $arguments): int {
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();
            $row = $this->store((int) $this->storedMessages()->max('position') + 1, MessageMapper::TYPE_TOOL_RESULT, $runId,
                self::journalPayload($toolName, $arguments), true);
            $this->afterRecording();

            return (int) $row->getKey();
        });
    }

    /**
     * The stored payload of a journal row (G-387 round 16). Pure.
     *
     * @internal
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function journalPayload(string $toolName, array $arguments): array
    {
        return [...MessageMapper::toArray(new ToolResultMessage([])), StepRows::JOURNAL => ['tool_name' => $toolName, 'args' => $arguments]];
    }

    /**
     * Whether the call this approval was asked for has a started row and no
     * result of its own: it was interrupted, and it may have run (G-387
     * round 15). Never run again; running it again needs a fresh approval.
     *
     * @internal
     */
    public function interrupted(string $approvalId): bool
    {
        $found = $this->stepOf($approvalId);
        if ($found === null) {
            return false;
        }
        $step = self::pairedStep($found['assistant'], $found['results']);
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            if (($request['approval_id'] ?? null) === $approvalId && $at !== null && $step['interrupted'][$at]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the call this approval was asked for can never get an outcome
     * of its own: its step is no longer the paused one (something replaced
     * it), and no result, started row or denial answers the call. A thread an
     * earlier version wrote can hold such a step; it reads replaced (the
     * replay gives the call the replaced denial) and it never runs.
     *
     * @internal
     */
    public function replacedWithoutOutcome(string $approvalId): bool
    {
        $view = $this->view();
        $index = $view->askerOf($approvalId);
        if ($index === null || $index === $view->paused) {
            return false;
        }
        $step = self::pairedStep($view->step($index), $view->ownedPayloads($index));
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            if (($request['approval_id'] ?? null) === $approvalId) {
                return $at !== null && ! $step['answered'][$at];
            }
        }

        return false;
    }

    /**
     * The position of the step a run beginning now resolves: the paused step,
     * or null when nothing is paused (G-387 round 15).
     *
     * @internal Read by the runtime once, when a run reads its history.
     */
    public function pausedStepPosition(): ?int
    {
        $view = $this->view();

        return $view->paused === null ? null : $view->rows[$view->paused]['position'];
    }

    /**
     * The highest stored position (0 for an empty thread).
     *
     * @internal Read by the runtime when a run reads its history (round 16).
     */
    public function lastPosition(): int
    {
        $rows = $this->view()->rows;

        return $rows === [] ? 0 : (int) $rows[array_key_last($rows)]['position'];
    }

    /**
     * Whether a step NEWER than `$position` that the run `$runId` did not
     * write is now the paused step and waits on approval: another run (a
     * tool's own nested send) or the host parked this session after the run
     * read it (G-387 round 16). The run then takes no further model step: its
     * answer would replace the step a caller was just told is waiting.
     *
     * @internal Read by the runtime before each model step.
     */
    public function parkedAfter(int $position, string $runId): bool
    {
        $view = $this->view();
        if ($view->paused === null) {
            return false;
        }
        $row = $view->rows[$view->paused];
        if ($row['position'] <= $position || (($row['harness'] ?? false) === true && ($row['run_id'] ?? null) === $runId)) {
            return false;
        }

        return $this->pendingApprovalIds() !== [];
    }

    /**
     * Scope of the approval at the transcript tail, including decisions that
     * decide() just recorded. Read raw rows so compaction cannot erase it.
     * A later assistant answer or user turn ends this approval's scope.
     *
     * @return list<string>|null null means there is no approval to resume
     */
    public function approvalToolNames(): ?array
    {
        $paused = $this->pausedStep();
        if ($paused === null) {
            return null;
        }
        $payload = $paused['assistant'];
        $requests = $payload['tool_approval_requests'] ?? [];
        if (! is_array($requests) || $requests === []) {
            return null;
        }
        $scope = $payload['harness_tool_names'] ?? null;
        if (is_array($scope) && array_is_list($scope) && array_filter($scope, static fn (mixed $name): bool => ! is_string($name) || $name === '' || $name === '*') === []) {
            /** @var list<string> $scope */
            return array_values(array_unique($scope));
        }

        // No offer the runtime stored: an older worker did not persist it,
        // or a HOST wrote the step (round 17: the offer is run state, read
        // only on the runtime's own step, so a host cannot widen it). Never
        // reconstruct the whole mode: only the calls this step asked a
        // person about are knowable. Every one of them, approved or not, so
        // the resolution can answer each (a denial, or no response) as it
        // answers a step the runtime wrote; a call runs only on its own
        // approval, so offering a denied one runs nothing.
        // Constant diagnostic: no arguments, tool names or participant ids.
        Log::warning('Pending approval has no valid stored tool scope; offering the requested tools only.');
        $names = [];
        $step = self::pairedStep($payload, $paused['results']);
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            $call = $at === null ? null : ($step['calls'][$at] ?? null);
            if (is_string($request['approval_id'] ?? null)
                && is_array($call) && is_string($call['name'] ?? null) && $call['name'] !== '' && $call['name'] !== '*') {
                $names[] = $call['name'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The model steps the run paused at the transcript tail has already
     * taken, or null when nothing at the tail is waiting on an approval.
     *
     * A run that pauses for a person and is resumed is the SAME run, so its
     * step limit continues rather than starting again. Counted from the raw
     * stored rows (one assistant row per model step since the turn's user
     * row), so the count survives a reload, another worker and a deploy, and
     * compaction cannot erase it.
     *
     * Not the count a resume continues (G-387): that is `harness_steps_used`
     * on the row the run parked on ({@see self::pausedStepsUsed()}), which
     * includes the steps of its subagents, which these rows never hold. This
     * is its floor, and the count for a row written before it was kept.
     */
    public function approvalRunSteps(): ?int
    {
        $paused = null;
        $steps = 0;
        $rows = $this->view()->rows;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            $stored = $rows[$i];
            if ($stored['type'] === MessageMapper::TYPE_USER) {
                break;
            }
            if ($stored['type'] !== MessageMapper::TYPE_ASSISTANT) {
                continue;
            }
            if ($paused === null) {
                $requests = $stored['payload']['tool_approval_requests'] ?? [];
                $paused = is_array($requests) && $requests !== [];
                if (! $paused) {
                    return null;
                }
            }
            $steps++;
        }

        return $paused === true ? $steps : null;
    }

    /**
     * What the run paused at the transcript tail wrote it had used, on the
     * row it parked on (G-387): `none` when nothing at the tail is waiting on
     * an approval, `absent` for a row written before the count was kept (or
     * by anyone but the runtime: the count is a harness mark, round 16),
     * `invalid` for a value this runtime could not have written, `valid`
     * with the count otherwise.
     *
     * @internal Read by the runtime once, when a run that resumes it begins.
     *
     * @return array{0: 'none'|'absent'|'invalid'|'valid', 1: ?int}
     */
    public function pausedStepsUsed(): array
    {
        $view = $this->view();
        for ($i = count($view->rows) - 1; $i >= 0; $i--) {
            $stored = $view->rows[$i];
            if ($stored['type'] === MessageMapper::TYPE_USER) {
                return ['none', null];
            }
            if ($stored['type'] !== MessageMapper::TYPE_ASSISTANT) {
                continue;
            }
            $payload = $view->honoured[$i];
            $requests = $payload['tool_approval_requests'] ?? [];
            if (! is_array($requests) || $requests === []) {
                return ['none', null];
            }
            if (! array_key_exists('harness_steps_used', $payload)) {
                return ['absent', null];
            }
            $used = $payload['harness_steps_used'];

            return RunBudgets::validUsed($used) ? ['valid', $used] : ['invalid', null];
        }

        return ['none', null];
    }

    /**
     * Whether the approval paused at the transcript tail has a call the person
     * has decided (approved or denied) that has no result yet: a resume has
     * those decisions to act on before anything else.
     *
     * @internal
     */
    public function decidedCallsAwaitingResult(): bool
    {
        return $this->decidedApprovalIdsAwaitingResult() !== [];
    }

    /**
     * The approval ids of the approval paused at the transcript tail that a
     * person has decided (approved or denied) and whose call has no result
     * yet: decisions a resume would act on (G-387). A call's result is one of
     * its OWN, matched by position (round 13): a same-id sibling's result
     * never makes it look finished.
     *
     * @internal
     *
     * @return list<string>
     */
    public function decidedApprovalIdsAwaitingResult(): array
    {
        $paused = $this->pausedStep();
        if ($paused === null) {
            return [];
        }
        $step = self::pairedStep($paused['assistant'], $paused['results']);
        $answered = self::decisionsOf($step);
        $ids = [];
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            if (is_string($request['approval_id'] ?? null) && isset($answered[$request['approval_id']])
                && is_string($request['tool_call_id'] ?? null) && $at !== null && ! $step['answered'][$at]) {
                $ids[] = $request['approval_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The approval ids of the paused step that stand APPROVED and whose call
     * has no outcome and was never started: approvals no run has acted on,
     * which a denial may still revoke (G-401).
     *
     * @internal
     *
     * @return list<string>
     */
    public function approvedIdsAwaitingRun(): array
    {
        $paused = $this->pausedStep();
        if ($paused === null) {
            return [];
        }
        $step = self::pairedStep($paused['assistant'], $paused['results']);
        $decisions = self::decisionsOf($step);
        $ids = [];
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            $id = $request['approval_id'] ?? null;
            if (is_string($id) && ($decisions[$id] ?? false) === true && $at !== null && ! $step['answered'][$at]) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The step a decision can apply to: the run paused at the transcript
     * tail, chosen by the ONE rule every such reader uses ({@see PausedStep},
     * G-397), with the result rows that belong to it ({@see StepRows}), oldest
     * first. Null when the conversation moved on past it, or nothing is paused.
     *
     * `$exceptStartedBy`: the started rows of that run are left out, so the
     * run that is about to run a call it started still finds it awaiting.
     *
     * The run ids it names (the step's own, and each run that recorded one
     * of its results) are only those of rows the RUNTIME wrote (round 16): a
     * run id on any other row is a host's claim and owns nothing.
     *
     * @return array{position: int, run_id: ?string, assistant: array<array-key, mixed>, results: list<array<array-key, mixed>>, result_runs: list<string>}|null
     */
    private function pausedStep(?string $exceptStartedBy = null): ?array
    {
        return self::pausedOf($this->view(), $exceptStartedBy);
    }

    /**
     * {@see self::pausedStep()} over a view.
     *
     * @return array{position: int, run_id: ?string, assistant: array<array-key, mixed>, results: list<array<array-key, mixed>>, result_runs: list<string>}|null
     */
    private static function pausedOf(ThreadView $view, ?string $exceptStartedBy = null): ?array
    {
        if ($view->paused === null) {
            return null;
        }
        $index = $view->paused;
        $runs = [];
        foreach ($view->owned[$index] ?? [] as $i) {
            $row = $view->rows[$i];
            if (($view->honoured[$i]['tool_results'] ?? []) !== [] && ($row['harness'] ?? false) === true
                && is_string($row['run_id'] ?? null) && $row['run_id'] !== '') {
                $runs[] = $row['run_id'];
            }
        }
        $step = $view->rows[$index];

        return [
            'position' => $step['position'],
            'run_id' => ($step['harness'] ?? false) === true && is_string($step['run_id'] ?? null) && $step['run_id'] !== '' ? $step['run_id'] : null,
            'assistant' => $view->step($index),
            'results' => $view->ownedPayloads($index, $exceptStartedBy),
            'result_runs' => array_values(array_unique($runs)),
        ];
    }

    /**
     * The approvals the harness itself answered in result rows (G-387 round
     * 14): named by its replaced-step mark, the 0.26.x line's replaced mark,
     * or a runner start's ended mark. Every reader of what is still pending
     * treats them as answered, whatever row follows. Read only on rows the
     * runtime wrote (the payloads given are honoured).
     *
     * @param  list<array<array-key, mixed>>  $results  oldest first
     * @return array<string, true>
     */
    private static function markedApprovals(array $results): array
    {
        $marked = [];
        foreach ($results as $payload) {
            foreach ([self::SUPERSEDED, self::REPLACED_026, self::ENDED_BY_START] as $key) {
                $mark = $payload[$key] ?? null;
                foreach (is_array($mark) && is_array($mark['approval_ids'] ?? null) ? $mark['approval_ids'] : [] as $id) {
                    if (is_string($id)) {
                        $marked[$id] = true;
                    }
                }
            }
        }

        return $marked;
    }

    /**
     * The decision that stands for each approval decided on a paired step,
     * by approval id: true when it is an approval ({@see StepRows::standing()}).
     * An approval whose decisions all came after its call's outcome has none.
     *
     * @param  array{standing: array<string, array<array-key, mixed>|null>}  $step
     * @return array<string, bool>
     */
    private static function decisionsOf(array $step): array
    {
        $decisions = [];
        foreach ($step['standing'] as $id => $response) {
            if ($response !== null) {
                $decisions[$id] = ($response['approved'] ?? false) === true;
            }
        }

        return $decisions;
    }

    /**
     * One stored step paired by position ({@see ThreadView::pair()}).
     *
     * @param  array<array-key, mixed>  $assistant  the step's payload, as read
     * @param  list<array<array-key, mixed>>  $results  the tool_result payloads that belong to it, oldest first (honoured)
     * @return array{calls: list<mixed>, answered: list<bool>, interrupted: list<bool>, requests: list<array{request: array<array-key, mixed>, call: int|null}>, standing: array<string, array<array-key, mixed>|null>}
     */
    private static function pairedStep(array $assistant, array $results): array
    {
        return ThreadView::pair($assistant, $results);
    }

    /**
     * The stored step that FIRST issued `$approvalId` and the result rows that
     * belong to it ({@see StepRows}), or null (G-387 round 13; round 15).
     *
     * @return array{index: int, assistant: array<array-key, mixed>, results: list<array<array-key, mixed>>}|null
     */
    private function stepOf(string $approvalId): ?array
    {
        $view = $this->view();
        $index = $view->askerOf($approvalId);
        if ($index === null) {
            return null;
        }

        return ['index' => $index, 'assistant' => $view->step($index), 'results' => $view->ownedPayloads($index)];
    }

    /**
     * Whether a runner start ended the call this approval id was requested
     * for, never run (G-387).
     *
     * Decided ONLY by the harness's own mark, on a row the runtime wrote
     * (round 16), and scoped to the approval's own request: the approval
     * reads ended when ANY result row after the row that asked for it, and
     * before the next model step, carries the mark and the mark names this
     * approval id (round 13). Never by result text (a tool can return any
     * text) and never by a tool-call id across the thread (a provider may
     * reuse ids: Gemini names them by function).
     *
     * @internal
     */
    public function endedByStart(string $approvalId): bool
    {
        return $this->markedAfterRequest($approvalId, self::ENDED_BY_START);
    }

    /**
     * Whether a row after the step that first asked for `$approvalId`, and
     * before the next assistant row, carries one of the marks `$keys` naming
     * it, read only on rows the runtime wrote.
     */
    private function markedAfterRequest(string $approvalId, string ...$keys): bool
    {
        $view = $this->view();
        $index = $view->askerOf($approvalId);
        if ($index === null) {
            return false;
        }
        for ($i = $index + 1, $n = count($view->rows); $i < $n; $i++) {
            $type = $view->rows[$i]['type'];
            if ($type === MessageMapper::TYPE_ASSISTANT) {
                break;
            }
            if ($type !== MessageMapper::TYPE_TOOL_RESULT) {
                continue;
            }
            foreach ($keys as $key) {
                $mark = $view->honoured[$i][$key] ?? null;
                if (is_array($mark) && is_array($mark['approval_ids'] ?? null) && in_array($approvalId, $mark['approval_ids'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * {@see self::endedByStart()} over stored rows (each a payload with its
     * `type`, and `harness: true` on a row the runtime wrote), oldest first.
     * Pure: the cross-language thread-rows corpus pins the reading by driving
     * this (G-387 round 13; the trust signal, round 16).
     *
     * @internal
     *
     * @param  iterable<array<array-key, mixed>>  $rows
     */
    public static function endedByStartIn(iterable $rows, string $approvalId): bool
    {
        return self::markedIn($rows, $approvalId, self::ENDED_BY_START);
    }

    /**
     * Whether a result row after the approval's own request, and before the
     * next model step, carries the harness mark `$key` naming it (on a row
     * the runtime wrote: `harness: true`).
     *
     * @param  iterable<array<array-key, mixed>>  $rows
     */
    private static function markedIn(iterable $rows, string $approvalId, string $key): bool
    {
        $asked = false;
        $issued = false;
        $ended = false;
        foreach ($rows as $row) {
            $type = $row['type'] ?? null;
            if ($type === MessageMapper::TYPE_ASSISTANT) {
                // Only a step (G-408) asks for anything.
                $requests = StepRows::objectCalls($row['tool_calls'] ?? null) === null ? [] : ($row['tool_approval_requests'] ?? []);
                $names = false;
                foreach (is_array($requests) ? $requests : [] as $request) {
                    $names = $names || (is_array($request) && ($request['approval_id'] ?? null) === $approvalId && is_string($request['tool_call_id'] ?? null));
                }
                if ($names && ! $issued) {
                    // The FIRST step that asked for it is its request (round
                    // 15): a later step repeating the id asks for nothing.
                    $issued = true;
                    $asked = true;
                    $ended = false;
                } elseif ($asked) {
                    // The next model step: its turn is over.
                    $asked = false;
                }

                continue;
            }
            if (! $asked || $type !== MessageMapper::TYPE_TOOL_RESULT || ($row['harness'] ?? false) !== true) {
                continue;
            }
            $mark = $row[$key] ?? null;
            if (is_array($mark) && is_array($mark['approval_ids'] ?? null) && in_array($approvalId, $mark['approval_ids'], true)) {
                $ended = true;
            }
        }

        return $ended;
    }

    /** The payload key marking a result row a runner start ended calls with (G-387). */
    public const ENDED_BY_START = 'harness_ended_by_start';

    /** The payload key marking the row that denied a superseded step's calls (DECISION B, G-387 round 13). */
    public const SUPERSEDED = 'harness_superseded';

    /** The mark 0.26.x writes on the same row (release/0.26.x Thread::REPLACED_MARK), read for upgraded threads. */
    private const REPLACED_026 = 'harness_replaced';

    /** The result each gated call of a superseded step is given, the same in every language (DECISION B). */
    public const SUPERSEDED_RESULT = 'Tool call was not run because a later step replaced it';

    /**
     * The result of an approved call whose start the decision that stands
     * refused (G-387 round 17, Py run21 I3): a denial recorded while it had
     * no outcome stands over the approval (G-401, denial wins), so it never
     * started.
     */
    public const START_DENIED = 'Not run: this call was denied before it started.';

    /**
     * Whether a new message would supersede the paused step now (DECISION
     * B): it has calls with no result. The runtime then records the message
     * and those calls' denials in ONE write before its turn is sent (G-404,
     * round 14), so no decision can land between them, and a turn that fails
     * afterwards still leaves the step replaced, beside the message that
     * replaced it.
     *
     * @internal Called by the runtime when a new message starts a turn.
     */
    public function supersedes(): bool
    {
        return $this->supersededDenials([]) !== null;
    }

    /**
     * `$messages` with the superseded step's denials before the first row
     * that supersedes it (DECISION B), or unchanged.
     *
     * What supersedes the paused step (the refinement of DECISION B): ANY
     * later assistant row with tool calls; or a later assistant text row or
     * user row recorded OUTSIDE the run that owns the step. The harness's own
     * rows inside that run (a structured turn's own answer) do not. Inside is
     * `$inRun`, the run the RUNTIME says it is recording for
     * ({@see InRunRecording}, set only by the runtime's own,
     * private recording): a run id a caller passes to record(), or any other
     * public route, proves nothing, so a host row reads as outside whatever
     * run id it names.
     *
     * @param  list<Message>  $messages
     * @return list<Message>
     */
    private function withSupersededDenials(array $messages, ?string $inRun): array
    {
        $found = $this->supersedingAt($messages, $inRun);
        if ($found === null) {
            return $messages;
        }
        ['at' => $at, 'denials' => $denials] = $found;
        $this->supersededRows ??= new \WeakMap;
        $this->supersededRows[$denials['message']] = $denials['approvals'];

        return [...array_slice($messages, 0, $at), $denials['message'], ...array_slice($messages, $at)];
    }

    /**
     * Where `$messages` would supersede the paused step, and the denials its
     * open calls would get there; null when they supersede nothing (see
     * {@see self::withSupersededDenials()}).
     *
     * @param  list<Message>  $messages
     * @return array{at: int, denials: array{message: ToolResultMessage, approvals: list<string>}}|null
     */
    private function supersedingAt(array $messages, ?string $inRun): ?array
    {
        $paused = $this->pausedStep();
        if ($paused === null) {
            return null;
        }
        // The runs that own the step: the one that recorded it, and every
        // run that recorded one of its results (a resume answering it), each
        // read only from a row the runtime wrote (round 16).
        $owners = array_filter([$paused['run_id'], ...$paused['result_runs']], 'is_string');
        $outside = $inRun === null || ! in_array($inRun, $owners, true);
        $at = null;
        foreach ($messages as $index => $message) {
            if (($message instanceof AssistantMessage && $message->toolCalls !== [])
                || ($outside && ($message instanceof UserMessage || $message instanceof AssistantMessage))) {
                $at = $index;

                break;
            }
        }
        if ($at === null) {
            return null;
        }
        $denials = $this->supersededDenials(array_values(array_filter(array_slice($messages, 0, $at), static fn (Message $m): bool => $m instanceof ToolResultMessage)));

        return $denials === null ? null : ['at' => $at, 'denials' => $denials];
    }

    /** @var \WeakMap<ToolResultMessage, list<string>>|null */
    private ?\WeakMap $supersededRows = null;

    /** Why the last start was refused, when it can say ({@see self::startRefusal()}). */
    private ?string $startRefusal = null;

    /**
     * The denials the paused step's open gated calls get when it is
     * superseded, and the approvals they were asked under (call order); null
     * when nothing is paused or nothing of it is open. A gated call is one an
     * approval request was issued for; one with a result of its own, stored
     * or in `$alsoResults`, is not open.
     *
     * @param  list<ToolResultMessage>  $alsoResults
     * @return array{message: ToolResultMessage, approvals: list<string>}|null
     */
    private function supersededDenials(array $alsoResults): ?array
    {
        $paused = $this->pausedStep();
        if ($paused === null) {
            return null;
        }
        $extra = array_map(static fn (ToolResultMessage $m): array => MessageMapper::toArray($m), $alsoResults);
        $step = self::pairedStep($paused['assistant'], [...$paused['results'], ...$extra]);
        // Every call of the step with no result of its own: a call that ran
        // has one, so the rest were waiting on a person.
        $open = array_keys(array_filter($step['answered'], static fn (bool $answered): bool => ! $answered));
        if ($open === []) {
            return null;
        }
        $approvals = [];
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            if ($at !== null && in_array($at, $open, true) && is_string($request['approval_id'] ?? null)) {
                $approvals[$at] = $request['approval_id'];
            }
        }
        ksort($approvals);
        $results = [];
        foreach ($open as $at) {
            $results[] = ThreadView::endedResult($step['calls'][$at], self::SUPERSEDED_RESULT);
        }

        return ['message' => new ToolResultMessage($results), 'approvals' => array_values($approvals)];
    }

    /**
     * The stored payload of a superseded step's denials: the result row, then
     * the harness's mark naming the approvals (DECISION B), the same shape as
     * the ended mark of a runner start.
     *
     * @internal Pure: the cross-language corpora pin it.
     *
     * @param  list<string>  $approvals
     * @return array<string, mixed>
     */
    public static function supersededPayload(ToolResultMessage $message, array $approvals): array
    {
        return [...MessageMapper::toArray($message), self::SUPERSEDED => ['approval_ids' => $approvals]];
    }

    /**
     * Whether the paused step this approval was asked on was superseded with
     * its call open (DECISION B): the harness's own mark, scoped as the ended
     * mark is ({@see self::endedByStart()}).
     *
     * @internal
     */
    public function supersededByLaterStep(string $approvalId): bool
    {
        // This line's mark, or the one 0.26.x writes on the same row
        // (`harness_replaced: {approval_ids}`): an upgraded thread reads the
        // same. 0.26.x rows written before that mark carry none, and read by
        // the decision recorded, as they did there.
        return $this->markedAfterRequest($approvalId, self::SUPERSEDED, self::REPLACED_026);
    }

    /**
     * The calls of the approval paused at the transcript tail that have no
     * recorded decision and no outcome, oldest first. Empty when nothing is
     * waiting.
     *
     * @return list<array{name: string, arguments: mixed}>
     */
    public function unansweredApprovals(): array
    {
        $paused = $this->pausedStep();
        if ($paused === null) {
            return [];
        }
        // Each request's OWN call, by position (round 13): never the last
        // call that happens to share its id.
        $step = self::pairedStep($paused['assistant'], $paused['results']);
        $answered = [...self::decisionsOf($step), ...self::markedApprovals($paused['results'])];
        $unanswered = [];
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            $call = $at === null ? null : ($step['calls'][$at] ?? null);
            if (is_string($request['approval_id'] ?? null) && ! isset($answered[$request['approval_id']]) && ($at === null || ! $step['answered'][$at])
                && is_string($request['tool_call_id'] ?? null) && is_array($call) && is_string($call['id'] ?? null) && is_string($call['name'] ?? null)) {
                $unanswered[] = ['name' => $call['name'], 'arguments' => $call['arguments'] ?? []];
            }
        }

        return $unanswered;
    }

    /**
     * The approval ids the run paused at the transcript tail is still waiting
     * on: issued by the last assistant step and not yet answered (G-354).
     *
     * Read from the raw stored rows, newest first, so compaction cannot erase
     * it. A user turn, or an assistant step that asked nothing, ends the scope:
     * nothing before it is pending. An id answered once is no longer pending,
     * and neither is one whose call already has an OUTCOME (a result, a
     * started row: round 16), by the same pairing the replay and the resolver
     * use: a decision on it could never be acted on.
     *
     * @return list<string>
     */
    public function pendingApprovalIds(): array
    {
        return self::pendingOf($this->view());
    }

    /**
     * {@see self::pendingApprovalIds()} over a view.
     *
     * @return list<string>
     */
    private static function pendingOf(ThreadView $view): array
    {
        $paused = self::pausedOf($view);
        if ($paused === null) {
            return [];
        }
        $step = self::pairedStep($paused['assistant'], $paused['results']);
        // A decision, or the harness's own mark (round 14): an approval whose
        // call the harness ended or denied as replaced is answered, even with
        // no row after the one that says so.
        $answered = [...self::decisionsOf($step), ...self::markedApprovals($paused['results'])];
        $closed = [];
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            if (is_string($request['approval_id'] ?? null) && $at !== null && $step['answered'][$at]) {
                $closed[$request['approval_id']] = true;
            }
        }
        $pending = [];
        foreach ($paused['assistant']['tool_approval_requests'] ?? [] as $request) {
            if (is_array($request) && is_string($request['approval_id'] ?? null) && $request['approval_id'] !== ''
                && ! array_key_exists($request['approval_id'], $answered) && ! isset($closed[$request['approval_id']])) {
                $pending[] = $request['approval_id'];
            }
        }

        return array_values(array_unique($pending));
    }

    /**
     * Whether the step the run paused at the transcript tail repeats a call
     * id, among its tool calls or among its approval requests. A decision
     * reaches a call through the call id its request names, so a repeated id
     * cannot say which call a decision belongs to.
     *
     * @internal Read when decisions are recorded.
     */
    public function pausedStepRepeatsCallIds(): bool
    {
        $paused = $this->pausedStep();
        if ($paused === null) {
            return false;
        }
        $callIds = [];
        // The step's calls by the ONE rule (G-408): its object entries.
        foreach (StepRows::objectCalls($paused['assistant']['tool_calls'] ?? null) ?? [] as $call) {
            $callIds[] = is_scalar($call['id'] ?? null) ? (string) $call['id'] : '';
        }
        $requestCallIds = [];
        $approvalIds = [];
        foreach ($paused['assistant']['tool_approval_requests'] ?? [] as $request) {
            $requestCallIds[] = is_array($request) && is_scalar($request['tool_call_id'] ?? null) ? (string) $request['tool_call_id'] : '';
            $approvalIds[] = is_array($request) && is_scalar($request['approval_id'] ?? null) ? (string) $request['approval_id'] : '';
        }

        // A repeated APPROVAL id across distinct calls fails closed too: one
        // decision cannot say which call it answers (the release lines' rule).
        return count($callIds) !== count(array_unique($callIds, SORT_STRING))
            || count($requestCallIds) !== count(array_unique($requestCallIds, SORT_STRING))
            || count($approvalIds) !== count(array_unique($approvalIds, SORT_STRING));
    }

    /**
     * Every approval id the run paused at the transcript tail asked for,
     * answered or not; empty when the tail is a user turn or an assistant
     * step that asked nothing. A turn with no new message continues exactly
     * these calls (G-366).
     *
     * @internal
     *
     * @return list<string>
     */
    public function pausedApprovalIds(): array
    {
        $ids = [];
        foreach ($this->pausedStep()['assistant']['tool_approval_requests'] ?? [] as $request) {
            if (is_array($request) && is_string($request['approval_id'] ?? null) && $request['approval_id'] !== '') {
                $ids[] = $request['approval_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The run that asked for an approval id, read from the stored row that
     * carries the request; null when no row in this thread issued it (G-285).
     *
     * @internal
     */
    public function approvalRunId(string $approvalId): ?string
    {
        $view = $this->view();
        $index = $view->askerOf($approvalId);
        $runId = $index === null ? null : ($view->rows[$index]['run_id'] ?? null);

        return is_string($runId) && $runId !== '' ? $runId : null;
    }

    /**
     * The call an approval id was requested for, or null.
     *
     * @return array{name: string, arguments: mixed}|null
     */
    public function approvalCall(string $approvalId): ?array
    {
        // The request's OWN call, paired by position (round 13): with a
        // repeated id, never the first or last call that shares it.
        $found = $this->stepOf($approvalId);
        if ($found === null) {
            return null;
        }
        $step = self::pairedStep($found['assistant'], $found['results']);
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            if (($request['approval_id'] ?? null) !== $approvalId) {
                continue;
            }
            $call = $at === null ? null : ($step['calls'][$at] ?? null);
            if (is_array($call) && ($call['id'] ?? null) === ($request['tool_call_id'] ?? false) && is_string($call['name'] ?? null)) {
                return ['name' => $call['name'], 'arguments' => $call['arguments'] ?? []];
            }

            return null;
        }

        return null;
    }

    /**
     * How many calls of the turn paused at the transcript tail a person
     * approved, for this tool and these arguments, that have no result yet.
     *
     * The decision that STANDS for an approval id is the answer, as in
     * {@see self::approvalDecisions()} and in the history a provider is sent:
     * a later approval never overrides a denial.
     *
     * @internal The evidence a gated tool requires before it runs. `$runId`: the run asking.
     */
    public function approvedCallsAwaitingResult(string $name, string $argumentsDigest, ?string $runId = null): int
    {
        // The run that started a call (round 15) still finds it awaiting:
        // its own started rows are left out, every other run's count.
        $paused = $this->pausedStep($runId);
        if ($paused === null) {
            return 0;
        }
        // A step whose call ids repeat runs none of its gated calls, whatever
        // was decided or however the decision reached the thread (G-392,
        // G-397): it gives no gated tool any approval evidence.
        if ($this->pausedStepRepeatsCallIds()) {
            return 0;
        }
        // Each approved request's OWN call, and a result of its own
        // (round 13): a same-id sibling's result finishes nothing.
        $step = self::pairedStep($paused['assistant'], $paused['results']);
        $approved = array_diff_key(array_filter(self::decisionsOf($step)), self::markedApprovals($paused['results']));
        $count = 0;
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            $call = $at === null ? null : ($step['calls'][$at] ?? null);
            if (! is_string($request['approval_id'] ?? null) || ! isset($approved[$request['approval_id']])
                || ! is_string($request['tool_call_id'] ?? null) || ! is_array($call) || ! is_string($call['id'] ?? null)
                || $step['answered'][$at] || ($call['name'] ?? null) !== $name || ! StepRows::binds($request, $call)) {
                continue;
            }
            $arguments = $call['arguments'] ?? [];
            if (CanonicalJson::digest(is_string($arguments) ? (json_decode($arguments, true) ?? []) : $arguments) === $argumentsDigest) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Every pending approval at the transcript tail, with the tool it was
     * asked for and the digest of its stored arguments (G-360). Read from the
     * thread only: a plan binds to these ids and to no other.
     *
     * @internal
     *
     * @return list<array{id: string, name: string, digest: string}>
     */
    public function pendingApprovalCalls(): array
    {
        $calls = [];
        foreach ($this->pendingApprovalIds() as $id) {
            $call = $this->approvalCall($id);
            if ($call === null) {
                continue;
            }
            $arguments = $call['arguments'];
            $calls[] = ['id' => $id, 'name' => $call['name'],
                'digest' => CanonicalJson::digest(is_string($arguments) ? (json_decode($arguments, true) ?? []) : $arguments)];
        }

        return $calls;
    }

    /**
     * The decision that STANDS for every decided approval, by approval id:
     * true when it is an approval (G-360; G-401; round 16). Read by the ONE
     * rule ({@see StepRows::standing()}) over the rows of the step that first
     * asked for each: a decision recorded after the call started, ran or was
     * answered never counts. An approval no step asked for reads as before: a
     * denial over an approval.
     *
     * @internal
     *
     * @return array<string, bool>
     */
    public function approvalDecisions(): array
    {
        return self::standingOf($this->view());
    }

    /**
     * {@see self::approvalDecisions()} over a view.
     *
     * @return array<string, bool>
     */
    private static function standingOf(ThreadView $view): array
    {
        $decided = [];
        foreach ($view->steps as $index) {
            foreach (self::pairedStep($view->step($index), $view->ownedPayloads($index))['standing'] as $id => $response) {
                if ($response !== null && $view->askerOf($id) === $index) {
                    $decided[$id] = ($response['approved'] ?? false) === true;
                }
            }
        }
        foreach ($view->rows as $row) {
            if ($row['type'] !== MessageMapper::TYPE_TOOL_RESULT) {
                continue;
            }
            foreach (is_array($row['payload']['tool_approval_responses'] ?? null) ? $row['payload']['tool_approval_responses'] : [] as $response) {
                if (is_array($response) && is_string($response['approval_id'] ?? null) && $view->askerOf($response['approval_id']) === null) {
                    $id = $response['approval_id'];
                    $decided[$id] = ($decided[$id] ?? true) && ($response['approved'] ?? false) === true;
                }
            }
        }

        return $decided;
    }

    /**
     * Whether each call of the step at `$position` of `$rows` has an outcome
     * (G-387 round 16, the reader rule: a result answers the first call of
     * its step that has none). Pure: the thread-rows corpus pins it.
     *
     * @internal
     *
     * @param  list<array{position: int, type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}>  $rows
     * @return list<bool>
     */
    public static function callOutcomesIn(array $rows, int $position): array
    {
        $view = ThreadView::of($rows);
        $index = $view->at[$position] ?? null;
        if ($index === null || ! StepRows::isStep($view->rows[$index]['type'], $view->rows[$index]['payload'])) {
            return [];
        }

        return self::pairedStep($view->step($index), $view->ownedPayloads($index))['answered'];
    }

    /**
     * What each approval reads over `$rows` (G-387 round 16): whether it is
     * pending, and the decision that stands for it (null for none). Pure:
     * the thread-rows corpus pins it.
     *
     * @internal
     *
     * @param  list<array{position: int, type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}>  $rows
     * @param  list<string>  $approvalIds
     * @return list<array{approval_id: string, pending: bool, decision: bool|null}>
     */
    public static function approvalReadsIn(array $rows, array $approvalIds): array
    {
        $view = ThreadView::of($rows);
        $pending = self::pendingOf($view);
        $decided = self::standingOf($view);

        return array_map(static fn (string $id): array => ['approval_id' => $id, 'pending' => in_array($id, $pending, true),
            'decision' => $decided[$id] ?? null], $approvalIds);
    }

    private function afterRecording(): self
    {
        $this->supersededRows = null;
        // The relation may already be loaded; without this a caller that reads
        // messages() after record() in the same request sees the stale set.
        $this->unsetRelation('storedMessages');

        return $this;
    }
}
