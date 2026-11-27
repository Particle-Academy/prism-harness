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
use Prism\Harness\Support\CallPairing;
use Prism\Harness\Support\InRunRecording;
use Prism\Harness\Support\MessageMapper;
use Prism\Harness\Support\PausedStep;
use Prism\Harness\Support\StepRows;
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
     * with each row the harness wrote for a step placed with that step
     * (G-387 round 15, {@see StepRows}):
     *
     *  - a started row is never sent; a started call that never got a result
     *    is answered there with the interrupted outcome, so it never runs again;
     *  - a harness result row whose mark names an earlier step is placed in
     *    that step's own result rows, wherever it landed.
     *
     * @return list<Message>
     */
    private function replayRows(): array
    {
        $rows = [];
        foreach ($this->storedMessages()->reorder('position')->lazy() as $stored) {
            $rows[] = ['position' => (int) $stored->position, 'type' => (string) $stored->type,
                'run_id' => is_string($stored->run_id) ? $stored->run_id : null,
                'payload' => is_array($stored->payload) ? $stored->payload : []];
        }

        return array_map(static fn (array $row): Message => MessageMapper::fromArray($row['type'], $row['payload']), self::placedRows($rows));
    }

    /**
     * The rows in replay order (see {@see self::replayRows()}), started rows
     * out and the interrupted outcome in. Pure: the thread-rows corpus drives
     * it through the fold (G-387 round 15).
     *
     * @internal
     *
     * @param  list<array{position: int, type: string, run_id?: ?string, harness?: bool, payload: array<array-key, mixed>}>  $rows  oldest first
     * @return list<array{type: string, payload: array<array-key, mixed>}>
     */
    public static function placedRows(array $rows): array
    {
        $owners = StepRows::owners($rows);
        $segment = null;
        $segmentOf = [];
        $ends = [];
        $steps = [];
        $last = null;
        $paused = null;
        foreach ($rows as $index => $row) {
            if (StepRows::isStep($row['type'], $row['payload'])) {
                $segment = $index;
                $last = $index;
                $ends[$index] = $index;
                $steps[] = $index;
                $paused = $index;
                // A request repeating an approval id an earlier step issued
                // asks for nothing (round 15, G-408): not sent either.
                $rows[$index]['payload']['tool_approval_requests'] = StepRows::ownRequests($rows, $index);

                continue;
            }
            if ($row['type'] === MessageMapper::TYPE_ASSISTANT) {
                // Only a step asks for anything (G-408): a text row never
                // carries a request.
                $rows[$index]['payload']['tool_approval_requests'] = [];
            }
            $segmentOf[$index] = $segment;
            if ($last !== null && ($row['type'] === MessageMapper::TYPE_TOOL_RESULT || $row['type'] === MessageMapper::TYPE_SYSTEM)) {
                $ends[$last] = $index;
            } else {
                $last = null;
                // Anything else after the newest step: nothing is paused.
                $paused = null;
            }
        }
        // Each step's own rows.
        $moved = [];
        $owned = [];
        foreach ($owners as $index => $owner) {
            if ($owner === null || $owner < 0) {
                continue;
            }
            $owned[$owner][] = $index;
            if ($owner !== ($segmentOf[$index] ?? null) && ! StepRows::isStarted(StepRows::honouredPayload($rows[$index]))) {
                $moved[$owner][] = $index;
            }
        }
        $extras = [];
        foreach ($steps as $step) {
            $payloads = array_map(static fn (int $i): array => StepRows::honouredPayload($rows[$i]), $owned[$step] ?? []);
            $paired = self::pairedStep($rows[$step]['payload'], $payloads);
            // A started call with no result: interrupted, it may have run.
            $answers = [];
            foreach ($paired['interrupted'] as $at => $flag) {
                if ($flag) {
                    $answers[$at] = self::endedResult($paired['calls'][$at], StepRows::INTERRUPTED_RESULT);
                }
            }
            if ($step !== $paused) {
                // A step nothing can decide any more whose asked-for call has
                // no outcome (a thread an earlier version wrote, which replaced
                // it without one): replaced, denied, never run (round 15).
                foreach ($paired['requests'] as ['call' => $at]) {
                    if ($at !== null && ! $paired['answered'][$at]) {
                        $answers[$at] = self::endedResult($paired['calls'][$at], self::SUPERSEDED_RESULT);
                    }
                }
            }
            ksort($answers);
            $extra = array_map(static fn (int $i): array => ['type' => $rows[$i]['type'], 'payload' => $rows[$i]['payload']], $moved[$step] ?? []);
            if ($answers !== []) {
                // As a stored row reads (decoded JSON), so the arguments survive the mapper.
                $extra[] = ['type' => MessageMapper::TYPE_TOOL_RESULT, 'payload' => json_decode(
                    (string) json_encode(MessageMapper::toArray(new ToolResultMessage(array_values($answers))), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)];
            }
            if ($extra !== []) {
                $extras[$ends[$step] ?? $step][] = $extra;
            }
        }
        // The decision that stands for each approval (G-401): a denial, when
        // any recorded for it is one; else the first approval.
        $standing = [];
        foreach ($rows as $row) {
            if ($row['type'] !== MessageMapper::TYPE_TOOL_RESULT) {
                continue;
            }
            foreach (is_array($row['payload']['tool_approval_responses'] ?? null) ? $row['payload']['tool_approval_responses'] : [] as $response) {
                if (is_array($response) && is_string($response['approval_id'] ?? null)) {
                    $current = $standing[$response['approval_id']] ?? null;
                    if ($current === null || (($current['approved'] ?? false) === true && ($response['approved'] ?? false) !== true)) {
                        $standing[$response['approval_id']] = $response;
                    }
                }
            }
        }
        $out = [];
        foreach ($rows as $index => $row) {
            $payload = StepRows::honouredPayload($row);
            $owner = $owners[$index] ?? null;
            $skip = $row['type'] === MessageMapper::TYPE_TOOL_RESULT
                && (StepRows::isStarted($payload) || ($owner !== null && $owner >= 0 && $owner !== ($segmentOf[$index] ?? null)));
            if (! $skip) {
                $out[] = ['type' => $row['type'], 'payload' => self::withStandingDecisions($row, $standing)];
            }
            foreach ($extras[$index] ?? [] as $extra) {
                foreach ($extra as $placed) {
                    $out[] = ['type' => $placed['type'], 'payload' => self::withStandingDecisions($placed, $standing)];
                }
            }
        }

        return $out;
    }

    /**
     * A result row's decisions, each the one that stands for its approval.
     *
     * @param  array{type: string, payload: array<array-key, mixed>}  $row
     * @param  array<string, array<array-key, mixed>>  $standing
     * @return array<array-key, mixed>
     */
    private static function withStandingDecisions(array $row, array $standing): array
    {
        $payload = $row['payload'];
        if ($row['type'] !== MessageMapper::TYPE_TOOL_RESULT || ! is_array($payload['tool_approval_responses'] ?? null)) {
            return $payload;
        }
        $payload['tool_approval_responses'] = array_map(static fn (mixed $response): mixed => is_array($response) && is_string($response['approval_id'] ?? null)
            ? ($standing[$response['approval_id']] ?? $response) : $response, $payload['tool_approval_responses']);

        return $payload;
    }

    /**
     * The result a call that never ran on its own is read as: interrupted
     * (it was started, and may have run) or replaced (it never ran).
     *
     * @param  mixed  $call  the stored call
     */
    private static function endedResult(mixed $call, string $text): ToolResult
    {
        $call = is_array($call) ? $call : [];
        $arguments = $call['arguments'] ?? [];
        $arguments = is_string($arguments) ? (json_decode($arguments, true) ?? []) : $arguments;

        return new ToolResult(
            toolCallId: is_scalar($call['id'] ?? null) ? (string) $call['id'] : '',
            toolName: is_string($call['name'] ?? null) ? $call['name'] : '',
            args: is_array($arguments) ? $arguments : [],
            result: $text,
            toolCallResultId: is_string($call['result_id'] ?? null) ? $call['result_id'] : null,
        );
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

        return $this->recordRows($messages, $runId, $toolNames, $parked, $endedByStart, $inRun, is_int($answers) ? $answers : null);
    }

    /**
     * @param  list<Message>  $messages
     * @param  list<string>|null  $toolNames
     * @param  array{harness_steps_used: int, harness_steps_left: int}|null  $parked
     * @param  list<array{message: Message, approvals: list<string>}>  $endedByStart
     * @param  int|null  $answers  the step the run's result rows answer before it writes a step of its own
     */
    private function recordRows(array $messages, ?string $runId, ?array $toolNames, ?array $parked, array $endedByStart, ?string $inRun, ?int $answers = null): self
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
        return DB::transaction(function () use ($messages, $runId, $toolNames, $parked, $endedByStart, $inRun, $answers): self {
            // Locks the thread ROW so a concurrent recorder waits instead of
            // reading the same max. A no-op on SQLite, which is why the suite
            // cannot exercise this and why the guarantee lives in the database.
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();

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
                $this->storedMessages()->create([
                    'position' => ++$position,
                    'type' => MessageMapper::typeOf($message),
                    'run_id' => $runId,
                    'payload' => $payload,
                ]);
                if ($inRun !== null && $message instanceof AssistantMessage && $message->toolCalls !== []) {
                    $target = $position;
                }
            }

            return $this->afterRecording();
        });
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
        $step = $this->storedMessages()->where('position', $position)->first();
        if ($step === null || ! StepRows::isStep((string) $step->type, $step->payload)) {
            return $message;
        }
        // A result is the same as one the step already holds when its call
        // id, tool name and arguments are (the fold's key), occurrence by
        // occurrence: what any one row of the step holds of a key is already
        // written, and only results beyond that are new.
        $key = static fn (mixed $id, mixed $name, mixed $args): string => json_encode([is_scalar($id) ? (string) $id : '', is_string($name) ? $name : '', StepRows::argsDigest($args)]);
        $held = [];
        foreach ($this->ownedRows($step) as $owned) {
            $payload = StepRows::honouredPayload(self::rowOf($owned));
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
        foreach ($message->toolResults as $result) {
            $k = $key($result->toolCallId, $result->toolName, $result->args);
            $here[$k] = ($here[$k] ?? 0) + 1;
            if ($here[$k] > ($held[$k] ?? 0)) {
                $kept[] = $result;
            }
        }
        if (count($kept) === count($message->toolResults)) {
            return $message;
        }

        return $kept === [] && $message->toolApprovalResponses === [] ? null : new ToolResultMessage($kept, $message->toolApprovalResponses);
    }

    /**
     * Record the write-ahead STARTED row (G-387 round 15) for the call of the
     * step at `$position` that the runtime is about to run, under the
     * runtime's private single-use token. True: recorded, run it. False: the
     * call is no longer open (it has a result, a started row, or the denial
     * of a superseding write), and it must NOT run.
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

        return DB::transaction(function () use ($runId, $position, $callId, $toolName, $arguments): bool {
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();
            $step = $this->storedMessages()->where('position', $position)->first();
            if ($step === null || ! StepRows::isStep((string) $step->type, $step->payload)) {
                return false;
            }
            $paired = self::pairedStep($this->withOwnRequests($step), array_map(
                static fn (object $row): array => StepRows::honouredPayload(self::rowOf($row)), $this->ownedRows($step)));
            $digest = is_array($arguments) ? CanonicalJson::digest($arguments) : null;
            $at = null;
            foreach ([true, false] as $byArguments) {
                foreach ($paired['calls'] as $index => $call) {
                    $stored = is_array($call) ? ($call['arguments'] ?? []) : [];
                    $stored = is_string($stored) ? json_decode($stored, true) : $stored;
                    if ($at === null && ! $paired['answered'][$index] && is_array($call) && ($call['id'] ?? null) === $callId && ($call['name'] ?? null) === $toolName
                        && (! $byArguments || ($digest !== null && is_array($stored) && CanonicalJson::digest($stored) === $digest))) {
                        $at = $index;
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
            if (! is_string($approval)) {
                return false;
            }
            $this->storedMessages()->create([
                'position' => (int) $this->storedMessages()->max('position') + 1,
                'type' => MessageMapper::TYPE_TOOL_RESULT,
                'run_id' => $runId,
                'payload' => self::startedPayload($callId, $toolName, $approval, $position),
            ]);
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
     * The result rows that belong to `$step` ({@see StepRows}), oldest
     * first: the rows of its segment no harness mark gives another step,
     * and any harness row after it whose mark names it.
     *
     * @param  ThreadMessage|\stdClass  $step
     * @return list<ThreadMessage|\stdClass>
     */
    private function ownedRows(object $step): array
    {
        $position = (int) $step->position;
        $segment = [];
        $end = null;
        foreach ($this->storedMessages()->where('position', '>', $position)->reorder('position')->cursor() as $row) {
            if (StepRows::isStep((string) $row->type, $row->payload)) {
                $end = (int) $row->position;

                break;
            }
            if ($row->type === MessageMapper::TYPE_TOOL_RESULT) {
                $segment[] = $row;
            }
        }
        // Marks in the segment that name a step BEFORE this one.
        $named = [];
        foreach ($segment as $row) {
            $mark = StepRows::markedPosition(StepRows::honouredPayload(self::rowOf($row)));
            if ($mark !== null && $mark < $position) {
                $named[] = $mark;
            }
        }
        $earlier = [];
        if ($named !== []) {
            foreach ($this->storedMessages()->whereIn('position', array_values(array_unique($named)))->get() as $row) {
                if (StepRows::isStep((string) $row->type, $row->payload)) {
                    $earlier[(int) $row->position] = $row->payload;
                }
            }
        }
        $window = [self::rowOf($step), ...array_map(static fn (object $row): array => self::rowOf($row), $segment)];
        $owners = StepRows::owners($window, $earlier);
        $owned = [];
        foreach ($segment as $i => $row) {
            if (($owners[$i + 1] ?? null) === 0) {
                $owned[] = $row;
            }
        }
        if ($end !== null) {
            // Harness rows after the segment whose mark names this step.
            $later = $this->storedMessages()->where('position', '>', $end)->where('type', MessageMapper::TYPE_TOOL_RESULT)
                ->where('payload->'.StepRows::ANSWERS_STEP.'->position', $position)->reorder('position')->get();
            foreach ($later as $row) {
                $payload = StepRows::honouredPayload(self::rowOf($row));
                if (StepRows::markedPosition($payload) === $position && StepRows::fits($payload, $step->payload)) {
                    $owned[] = $row;
                }
            }
        }

        return $owned;
    }

    /**
     * A stored row as the pure readers take it.
     *
     * @param  ThreadMessage|\stdClass  $row
     * @return array{position: int, type: string, run_id: ?string, payload: array<array-key, mixed>}
     */
    private static function rowOf(object $row): array
    {
        /** @var ThreadMessage $row */
        $runId = $row->getAttribute('run_id');
        $payload = $row->getAttribute('payload');

        return ['position' => (int) $row->getAttribute('position'), 'type' => (string) $row->getAttribute('type'),
            'run_id' => is_string($runId) ? $runId : null, 'payload' => is_array($payload) ? $payload : []];
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
        $stored = $this->requestRowOf($approvalId);
        if ($stored === null || $this->pausedStepPosition() === (int) $stored->position) {
            return false;
        }
        $step = self::pairedStep($stored->payload, array_map(
            static fn (object $row): array => StepRows::honouredPayload(self::rowOf($row)), $this->ownedRows($stored)));
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
        return $this->pausedStep()['position'] ?? null;
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
        $decisions = self::decisionsIn($paused['results']);
        $requests = $payload['tool_approval_requests'] ?? [];
        if (! is_array($requests) || $requests === []) {
            return null;
        }
        $scope = $payload['harness_tool_names'] ?? null;
        if (is_array($scope) && array_is_list($scope) && array_filter($scope, static fn (mixed $name): bool => ! is_string($name) || $name === '' || $name === '*') === []) {
            /** @var list<string> $scope */
            return array_values(array_unique($scope));
        }

        // Older workers did not persist the offer. Never reconstruct the
        // whole mode: only approved requests on this record are knowable.
        // Constant diagnostic: no arguments, tool names or participant ids.
        Log::warning('Pending approval has no valid stored tool scope; offering approved requests only.');
        $names = [];
        $step = self::pairedStep($payload, $paused['results']);
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            $call = $at === null ? null : ($step['calls'][$at] ?? null);
            if (is_string($request['approval_id'] ?? null) && ($decisions[$request['approval_id']] ?? false)
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
        foreach ($this->storedMessages()->reorder('position', 'desc')->cursor() as $stored) {
            if ($stored->type === MessageMapper::TYPE_USER) {
                break;
            }
            if ($stored->type !== MessageMapper::TYPE_ASSISTANT) {
                continue;
            }
            if ($paused === null) {
                $requests = $stored->payload['tool_approval_requests'] ?? [];
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
     * an approval, `absent` for a row written before the count was kept,
     * `invalid` for a value this runtime could not have written, `valid`
     * with the count otherwise.
     *
     * @internal Read by the runtime once, when a run that resumes it begins.
     *
     * @return array{0: 'none'|'absent'|'invalid'|'valid', 1: ?int}
     */
    public function pausedStepsUsed(): array
    {
        foreach ($this->storedMessages()->reorder('position', 'desc')->cursor() as $stored) {
            if ($stored->type === MessageMapper::TYPE_USER) {
                return ['none', null];
            }
            if ($stored->type !== MessageMapper::TYPE_ASSISTANT) {
                continue;
            }
            $payload = is_array($stored->payload) ? $stored->payload : [];
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
        $answered = self::decisionsIn($paused['results']);
        $step = self::pairedStep($paused['assistant'], $paused['results']);
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
        $decisions = self::decisionsIn($paused['results']);
        $step = self::pairedStep($paused['assistant'], $paused['results']);
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
     * @return array{position: int, run_id: ?string, assistant: array<string, mixed>, results: list<array<string, mixed>>, result_runs: list<string>}|null
     */
    private function pausedStep(?string $exceptStartedBy = null): ?array
    {
        $found = PausedStep::find(
            $this->storedMessages()->reorder('position', 'desc')->cursor(),
            static fn ($stored): string => PausedStep::kindOfRow((string) $stored->type, $stored->payload),
        );
        if ($found === null) {
            return null;
        }
        $results = [];
        $runs = [];
        foreach ($this->ownedRows($found['step']) as $row) {
            $payload = StepRows::honouredPayload(self::rowOf($row));
            if ($exceptStartedBy !== null && StepRows::isStarted($payload) && $row->run_id === $exceptStartedBy) {
                continue;
            }
            $results[] = $payload;
            if (($payload['tool_results'] ?? []) !== [] && is_string($row->run_id) && $row->run_id !== '') {
                $runs[] = $row->run_id;
            }
        }

        return [
            'position' => (int) $found['step']->position,
            'run_id' => is_string($found['step']->run_id) && $found['step']->run_id !== '' ? $found['step']->run_id : null,
            'assistant' => $this->withOwnRequests($found['step']),
            'results' => $results,
            'result_runs' => array_values(array_unique($runs)),
        ];
    }

    /**
     * A step's payload with only the requests that ask for something: never
     * one repeating an approval id an EARLIER step issued (G-408, the rule
     * of prism-harness-ts 0.11.x #92). Such a request is void.
     *
     * @param  ThreadMessage|\stdClass  $step
     * @return array<array-key, mixed>
     */
    private function withOwnRequests(object $step): array
    {
        $payload = $step->payload;
        $requests = StepRows::requestsOf($payload);
        if ($requests === []) {
            return $payload;
        }
        $earlier = [];
        foreach ($this->storedMessages()->where('type', MessageMapper::TYPE_ASSISTANT)->where('position', '<', (int) $step->position)->cursor() as $row) {
            if (StepRows::isStep((string) $row->type, $row->payload)) {
                foreach (StepRows::requestsOf($row->payload) as $request) {
                    $earlier[(string) $request['approval_id']] = true;
                }
            }
        }
        if ($earlier === []) {
            return $payload;
        }
        $payload['tool_approval_requests'] = array_values(array_filter(is_array($payload['tool_approval_requests'] ?? null) ? $payload['tool_approval_requests'] : [],
            static fn (mixed $request): bool => ! (is_array($request) && is_scalar($request['approval_id'] ?? null) && isset($earlier[(string) $request['approval_id']]))));

        return $payload;
    }

    /**
     * The approvals the harness itself answered in result rows (G-387 round
     * 14): named by its replaced-step mark, the 0.26.x line's replaced mark,
     * or a runner start's ended mark. Every reader of what is still pending
     * treats them as answered, whatever row follows.
     *
     * @param  list<array<string, mixed>>  $results  oldest first
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
     * Whether a result row carries one of the harness's own marks.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function isMarkedRow(array $payload): bool
    {
        return array_key_exists(self::SUPERSEDED, $payload) || array_key_exists(self::REPLACED_026, $payload)
            || array_key_exists(self::ENDED_BY_START, $payload) || StepRows::isStarted($payload);
    }

    /**
     * The decisions in result rows, by approval id: the FIRST recorded answer.
     *
     * @param  list<array<string, mixed>>  $results  oldest first
     * @return array<string, bool>
     */
    private static function decisionsIn(array $results): array
    {
        $decisions = [];
        foreach ($results as $payload) {
            foreach ($payload['tool_approval_responses'] ?? [] as $response) {
                if (is_array($response) && is_string($response['approval_id'] ?? null)) {
                    // A DENIAL is final (G-401): it stands over any approval
                    // recorded for the same approval, before or after it.
                    $id = $response['approval_id'];
                    $decisions[$id] = ($decisions[$id] ?? true) && ($response['approved'] ?? false) === true;
                }
            }
        }

        return $decisions;
    }

    /**
     * One stored step paired by position (G-387 round 13, {@see CallPairing}):
     * its calls, whether each has a result of its own among the result rows
     * recorded after it, and the call each approval request was issued for.
     * A request prefers a call no result of the step's own run answered (the
     * rows before its first decision), because a call that ran was never
     * waiting on a person.
     *
     * A started row (round 15) answers its call IN PROGRESS: `answered` is
     * true for it, and `interrupted` is true while no result of its own is
     * among the rows.
     *
     * @param  array<string, mixed>  $assistant  the step's stored payload
     * @param  list<array<string, mixed>>  $results  the tool_result payloads that belong to it, oldest first (honoured)
     * @return array{calls: list<mixed>, answered: list<bool>, interrupted: list<bool>, requests: list<array{request: array<array-key, mixed>, call: int|null}>}
     */
    private static function pairedStep(array $assistant, array $results): array
    {
        $stepCalls = StepRows::objectCalls($assistant['tool_calls'] ?? null) ?? [];
        $calls = CallPairing::ofStored($stepCalls, 'id', 'name');
        $rows = [];
        // The calls that ran at once: results before the first decision, and
        // never a row the harness wrote to END calls (its replaced mark, the
        // 0.26.x replaced mark or a start's ended mark, round 14): the calls
        // such a row answers never ran.
        $ranRows = [];
        $ran = null;
        $started = [];
        foreach ($results as $payload) {
            if (StepRows::isStarted($payload)) {
                $started[] = [$payload[StepRows::STARTED]['tool_call_id'], $payload[StepRows::STARTED]['tool_name']];

                continue;
            }
            $responses = $payload['tool_approval_responses'] ?? [];
            if ($ran === null && is_array($responses) && $responses !== []) {
                $ran = CallPairing::answered($calls, $ranRows);
            }
            $row = CallPairing::ofStored($payload['tool_results'] ?? [], 'tool_call_id', 'tool_name');
            $rows[] = $row;
            if (! self::isMarkedRow($payload)) {
                $ranRows[] = $row;
            }
        }
        $answered = CallPairing::answered($calls, $rows);
        // Each started row names one call (id and tool): the first such call
        // with no outcome. Answered in progress; interrupted while it has no
        // result of its own.
        $interrupted = array_fill(0, count($calls), false);
        foreach ($started as [$id, $name]) {
            foreach ($calls as $i => $call) {
                if (! $answered[$i] && ! $interrupted[$i] && $call[0] === $id && $call[1] === $name) {
                    $interrupted[$i] = true;

                    break;
                }
            }
        }
        $answered = array_map(static fn (bool $a, bool $b): bool => $a || $b, $answered, $interrupted);
        $ran ??= CallPairing::answered($calls, $ranRows);
        $requests = array_values(array_filter(is_array($assistant['tool_approval_requests'] ?? null) ? $assistant['tool_approval_requests'] : [], 'is_array'));
        $paired = CallPairing::requests($calls, array_map(
            static fn (array $request): string => is_scalar($request['tool_call_id'] ?? null) ? (string) $request['tool_call_id'] : '', $requests), $ran);

        return [
            'calls' => $stepCalls,
            'answered' => $answered,
            'interrupted' => $interrupted,
            'requests' => array_map(static fn (array $request, ?int $call): array => ['request' => $request, 'call' => $call], $requests, $paired),
        ];
    }

    /**
     * The stored step that issued `$approvalId` and the result rows that
     * belong to it ({@see StepRows}), or null (G-387 round 13; round 15).
     *
     * @return array{assistant: array<string, mixed>, results: list<array<string, mixed>>}|null
     */
    private function stepOf(string $approvalId): ?array
    {
        $stored = $this->requestRowOf($approvalId);
        if ($stored === null) {
            return null;
        }

        return ['assistant' => $stored->payload, 'results' => array_map(
            static fn (object $row): array => StepRows::honouredPayload(self::rowOf($row)), $this->ownedRows($stored))];
    }

    /**
     * The STEP (G-408) that FIRST asked for `$approvalId`, or null: the approval belongs to it alone.
     *
     * @return \stdClass|null a stored row
     */
    private function requestRowOf(string $approvalId): ?object
    {
        foreach ($this->storedMessages()->where('type', MessageMapper::TYPE_ASSISTANT)->reorder('position')->cursor() as $stored) {
            if (! StepRows::isStep((string) $stored->type, $stored->payload)) {
                continue;
            }
            foreach ($stored->payload['tool_approval_requests'] ?? [] as $request) {
                if (is_array($request) && ($request['approval_id'] ?? null) === $approvalId) {
                    return $stored;
                }
            }
        }

        return null;
    }

    /**
     * Whether a runner start ended the call this approval id was requested
     * for, never run (G-387).
     *
     * Decided ONLY by the harness's own mark, and scoped to the approval's
     * own request: the approval reads ended when ANY result row after the
     * row that asked for it, and before the next model step, carries the
     * mark and the mark names this approval id (round 13). Not only the
     * first result carrying its call id: a provider may give two calls in a
     * step one id, and a sibling's earlier result, which has no mark, said
     * nothing about this call. Only the harness writes the mark, so reading
     * every row of the step is no looser. Never by result text (a tool can
     * return any text) and never by a tool-call id across the thread (a
     * provider may reuse ids: Gemini names them by function).
     *
     * @internal
     */
    public function endedByStart(string $approvalId): bool
    {
        $request = $this->requestRowOf($approvalId);
        if ($request === null) {
            return false;
        }

        return self::endedByStartIn((function () use ($request): Generator {
            yield ['type' => $request->type, ...$request->payload];
            foreach ($this->storedMessages()->where('position', '>', (int) $request->position)->reorder('position')->cursor() as $stored) {
                yield ['type' => $stored->type, ...$stored->payload];
            }
        })(), $approvalId);
    }

    /**
     * {@see self::endedByStart()} over stored rows (each a payload with its
     * `type`), oldest first. Pure: the cross-language thread-rows corpus
     * pins the reading by driving this (G-387 round 13).
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
     * next model step, carries the harness mark `$key` naming it.
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
            if (! $asked || $type !== MessageMapper::TYPE_TOOL_RESULT) {
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
        // run that recorded one of its results (a resume answering it).
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
            $call = $step['calls'][$at];
            $arguments = $call['arguments'] ?? [];
            $arguments = is_string($arguments) ? (json_decode($arguments, true) ?? []) : $arguments;
            $results[] = new ToolResult(
                toolCallId: is_scalar($call['id'] ?? null) ? (string) $call['id'] : '',
                toolName: is_string($call['name'] ?? null) ? $call['name'] : '',
                args: is_array($arguments) ? $arguments : [],
                result: self::SUPERSEDED_RESULT,
                toolCallResultId: is_string($call['result_id'] ?? null) ? $call['result_id'] : null,
            );
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
        $request = $this->requestRowOf($approvalId);
        if ($request === null) {
            return false;
        }

        $rows = function () use ($request): Generator {
            yield ['type' => $request->type, ...$request->payload];
            foreach ($this->storedMessages()->where('position', '>', (int) $request->position)->reorder('position')->cursor() as $stored) {
                yield ['type' => $stored->type, ...$stored->payload];
            }
        };

        // This line's mark, or the one 0.26.x writes on the same row
        // (`harness_replaced: {approval_ids}`): an upgraded thread reads the
        // same. 0.26.x rows written before that mark carry none, and read by
        // the decision recorded, as they did there.
        return self::markedIn($rows(), $approvalId, self::SUPERSEDED)
            || self::markedIn($rows(), $approvalId, self::REPLACED_026);
    }

    /**
     * The calls of the approval paused at the transcript tail that have no
     * recorded decision, oldest first. Empty when nothing is waiting.
     *
     * @return list<array{name: string, arguments: mixed}>
     */
    public function unansweredApprovals(): array
    {
        $paused = $this->pausedStep();
        if ($paused === null) {
            return [];
        }
        $answered = [...self::decisionsIn($paused['results']), ...self::markedApprovals($paused['results'])];
        // Each request's OWN call, by position (round 13): never the last
        // call that happens to share its id.
        $step = self::pairedStep($paused['assistant'], $paused['results']);
        $unanswered = [];
        foreach ($step['requests'] as ['request' => $request, 'call' => $at]) {
            $call = $at === null ? null : ($step['calls'][$at] ?? null);
            if (is_string($request['approval_id'] ?? null) && ! isset($answered[$request['approval_id']])
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
     * nothing before it is pending. An id answered once is no longer pending.
     *
     * @return list<string>
     */
    public function pendingApprovalIds(): array
    {
        $paused = $this->pausedStep();
        if ($paused === null) {
            return [];
        }
        // A decision, or the harness's own mark (round 14): an approval whose
        // call the harness ended or denied as replaced is answered, even with
        // no row after the one that says so.
        $answered = [...self::decisionsIn($paused['results']), ...self::markedApprovals($paused['results'])];
        $pending = [];
        foreach ($paused['assistant']['tool_approval_requests'] ?? [] as $request) {
            if (is_array($request) && is_string($request['approval_id'] ?? null) && $request['approval_id'] !== ''
                && ! array_key_exists($request['approval_id'], $answered)) {
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
        $stored = $this->requestRowOf($approvalId);

        return $stored !== null && is_string($stored->run_id) && $stored->run_id !== '' ? $stored->run_id : null;
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
     * The FIRST recorded answer for an approval id is the answer, as in
     * {@see self::approvalDecisions()} and in the history a provider is sent:
     * a later approval never overrides an earlier denial.
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
        $approved = array_diff_key(array_filter(self::decisionsIn($paused['results'])), self::markedApprovals($paused['results']));
        // Each approved request's OWN call, and a result of its own
        // (round 13): a same-id sibling's result finishes nothing.
        $step = self::pairedStep($paused['assistant'], $paused['results']);
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
     * Every recorded decision, by approval id: true when approved (G-360).
     *
     * @internal
     *
     * @return array<string, bool>
     */
    public function approvalDecisions(): array
    {
        $decided = [];
        foreach ($this->storedMessages()->where('type', MessageMapper::TYPE_TOOL_RESULT)->reorder('position')->cursor() as $stored) {
            foreach ($stored->payload['tool_approval_responses'] ?? [] as $response) {
                if (is_array($response) && is_string($response['approval_id'] ?? null)) {
                    // A denial is final (G-401).
                    $id = $response['approval_id'];
                    $decided[$id] = ($decided[$id] ?? true) && ($response['approved'] ?? false) === true;
                }
            }
        }

        return $decided;
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
