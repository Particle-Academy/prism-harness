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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Context\NoCompaction;
use Prism\Harness\Context\ToolPairGuard;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\Support\MessageMapper;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Thread as ThreadContract;
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
    public static function forParticipant(Model $participant, string $scope): self
    {
        $address = [
            'participant_type' => $participant->getMorphClass(),
            'participant_id' => $participant->getKey(),
            'scope' => $scope,
        ];

        // Not `firstOrCreate`, because the lookup and the creation no longer
        // use the same set of attributes: `retired_at` filters the read and
        // must not be written. Written as firstOrCreate with the null in the
        // attributes, a fresh thread would be created with `retired_at` set to
        // null explicitly — harmless here, and wrong the moment the column
        // gains a default.
        $live = static::query()
            ->where($address)
            ->whereNull('retired_at')
            ->orderByDesc('id')
            ->first();

        /** @var self */
        return $live ?? static::query()->create($address);
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
    public function scopeAddressedTo(Builder $query, Model $participant, ?string $scope = null): Builder
    {
        $query->where('participant_type', $participant->getMorphClass())
            ->where('participant_id', $participant->getKey());

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
     * @return Generator<int, Message>
     */
    #[\Override]
    public function messages(): Generator
    {
        $strategy = $this->compactionStrategy();

        if ($strategy instanceof NoCompaction) {
            // The lazy path, kept intact for the default. Compaction has to
            // materialise the conversation — a strategy that counts, or looks
            // at the end, cannot work from a generator — and paying that on
            // every thread to support a strategy nobody selected would make the
            // common case worse to serve the uncommon one.
            foreach ($this->storedMessages()->lazy() as $stored) {
                yield $stored->toPrismMessage();
            }

            return;
        }

        $messages = [];

        foreach ($this->storedMessages()->lazy() as $stored) {
            $messages[] = $stored->toPrismMessage();
        }

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
     *
     * @param  iterable<Message>  $messages
     */
    public function record(iterable $messages, ?string $runId = null): self
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
        return DB::transaction(function () use ($messages, $runId): self {
            // Locks the thread ROW so a concurrent recorder waits instead of
            // reading the same max. A no-op on SQLite, which is why the suite
            // cannot exercise this and why the guarantee lives in the database.
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            $position = (int) $this->storedMessages()->max('position');

            foreach ($messages as $message) {
                $this->storedMessages()->create([
                    'position' => ++$position,
                    'type' => MessageMapper::typeOf($message),
                    'run_id' => $runId,
                    'payload' => MessageMapper::toArray($message),
                ]);
            }

            return $this->afterRecording();
        });
    }

    private function afterRecording(): self
    {

        // The relation may already be loaded; without this a caller that reads
        // messages() after record() in the same request sees the stale set.
        $this->unsetRelation('storedMessages');

        return $this;
    }
}
