<?php

declare(strict_types=1);

namespace Prism\Harness\Models;

use Closure;
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
use Prism\Harness\Context\NoCompaction;
use Prism\Harness\Context\ToolPairGuard;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\Contracts\FencedSessionStore;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\Sessions\DatabaseAddressIndex;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Support\MessageMapper;
use Prism\Harness\Support\ToolResultRuns;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Thread as ThreadContract;
use Prism\Prism\Providers\Anthropic\Support\ContentBlocks;
use Prism\Prism\Providers\OpenAI\Support\OutputItems;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
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
                throw new \LogicException('A resolved harness thread address is immutable; import into a new address explicitly.');
            }
            $address = new SessionAddress(
                $thread->participant_ref_type ?? $thread->getAttribute('participant_type'),
                $thread->participant_ref_id ?? ($thread->getAttribute('participant_id') === null ? null : (string) $thread->getAttribute('participant_id')),
                $thread->scope,
                $thread->agent_id,
                $thread->participant_ref_type !== null,
            );
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
            // The lazy path, kept intact for the default. Compaction has to
            // materialise the conversation — a strategy that counts, or looks
            // at the end, cannot work from a generator — and paying that on
            // every thread to support a strategy nobody selected would make the
            // common case worse to serve the uncommon one.
            yield from $this->replayed();

            return;
        }

        $messages = iterator_to_array($this->replayed(), false);

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
     * The stored rows folded for replay, each result row the harness wrote
     * for a step placed with that step (G-407): its `harness_answers_step`
     * mark names the step's position. The mark itself is not replayed.
     *
     * @return Generator<int, Message>
     */
    private function replayed(): Generator
    {
        $messages = [];
        $positions = [];
        $marks = [];
        $interrupted = $this->interruptedCalls();
        foreach ($this->storedMessages()->lazy() as $stored) {
            $position = (int) $stored->position;
            if (($stored->type ?? null) === MessageMapper::TYPE_TOOL_RESULT && self::startedMark($stored) !== null) {
                // A started row is never replayed (G-410). One the harness
                // wrote whose call has no result of its own is replayed as
                // that call's one result, interrupted, with the step.
                if (! isset($interrupted[$position])) {
                    continue;
                }
                $messages[] = new ToolResultMessage([$interrupted[$position]['result']]);
                $marks[count($messages) - 1] = $interrupted[$position]['step'];

                continue;
            }
            $messages[] = self::withOwnProviderContent($stored->toPrismMessage());
            $positions[$position] = count($messages) - 1;
            $mark = $stored->payload[self::ANSWERS_STEP]['position'] ?? null;
            if (is_int($mark)) {
                $marks[count($messages) - 1] = $mark;
            }
        }
        $answers = [];
        foreach ($marks as $index => $position) {
            if (isset($positions[$position])) {
                $answers[$index] = $positions[$position];
            }
        }

        yield from ToolResultRuns::fold($messages, $answers);
    }

    /**
     * An assistant row, replayed with its provider content (OpenAI
     * `output_items`, Anthropic `content_blocks`) only when that content is
     * the row's own (G-415): its tool items are exactly the row's tool calls
     * (ids and names, as a multiset) and its text is the row's text. Core
     * sends the provider content in place of the row's text and calls, so a
     * host note or an edited copy carrying another step's content would send
     * that step's calls, unanswered, in every later request, and never send
     * the row's own text. Otherwise the provider content is dropped, and the
     * row is sent from its own text and tool calls. A row the harness records
     * from a provider response matches, and is replayed unchanged. A row a
     * host recorded (its {@see self::HOST_ROW} mark) that carries reasoning
     * is sent without any provider content and without reasoning.
     */
    private static function withOwnProviderContent(Message $message): Message
    {
        if (! $message instanceof AssistantMessage) {
            return $message;
        }
        $calls = array_map(static fn (ToolCall $call): string => strlen($call->id).':'.$call->id.'|'.$call->name, $message->toolCalls);
        sort($calls);
        $content = $message->additionalContent;
        $toolCalls = $message->toolCalls;
        $changed = false;
        $host = false;
        if (array_key_exists(self::HOST_ROW, $content)) {
            $host = $content[self::HOST_ROW] === true;
            unset($content[self::HOST_ROW]);
            $changed = true;
            if ($host && self::carriesReasoning($content, $toolCalls)) {
                // Reasoning cannot be checked against the row (a signature,
                // an encrypted item): a host row's is another response's, and
                // would be sent twice. Its provider content goes entirely, and
                // its calls are sent without reasoning.
                foreach (['output_items', 'content_blocks', 'thinking', 'thinking_signature', 'thinking_blocks'] as $key) {
                    unset($content[$key]);
                }
                $toolCalls = array_map(static fn (ToolCall $call): ToolCall => new ToolCall($call->id, $call->name, $call->arguments(), $call->resultId), $toolCalls);
            }
        }

        $items = $content['output_items'] ?? null;
        if (is_array($items) && ! self::outputItemsAreOwn(array_values($items), $calls, $message->content)) {
            unset($content['output_items']);
            $changed = true;
        }
        $blocks = $content['content_blocks'] ?? null;
        if (is_array($blocks) && ! self::contentBlocksAreOwn(array_values($blocks), $calls, $message->content)) {
            unset($content['content_blocks']);
            $changed = true;
        }
        if ($host && $toolCalls !== [] && $message->content !== '' && ! isset($content['output_items']) && ! isset($content['content_blocks'])) {
            // A host row with no provider content: core sends it on OpenAI
            // as its calls, then its text, which parts each call from its
            // output. Its own items instead: its text, then its calls. A row
            // the harness recorded is never rewritten.
            $content['output_items'] = [
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $message->content]]],
                ...array_map(static fn (ToolCall $call): array => ['type' => 'function_call', 'id' => $call->id, 'call_id' => $call->resultId,
                    'name' => $call->name, 'arguments' => $call->argumentsAsJson()], array_values($toolCalls)),
            ];
            $changed = true;
        }

        return $changed ? new AssistantMessage($message->content, $toolCalls, $content, $message->toolApprovalRequests) : $message;
    }

    /**
     * Whether provider content carries reasoning that cannot be checked
     * against the row: OpenAI reasoning items, Anthropic thinking blocks or
     * thinking fields, or calls naming a reasoning item.
     *
     * @param  array<string, mixed>  $content
     * @param  array<array-key, ToolCall>  $toolCalls
     */
    private static function carriesReasoning(array $content, array $toolCalls): bool
    {
        foreach (['thinking', 'thinking_signature', 'thinking_blocks'] as $key) {
            if (! empty($content[$key])) {
                return true;
            }
        }
        foreach (is_array($content['output_items'] ?? null) ? $content['output_items'] : [] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'reasoning') {
                return true;
            }
        }
        foreach (is_array($content['content_blocks'] ?? null) ? $content['content_blocks'] : [] as $block) {
            if (is_array($block) && in_array($block['type'] ?? null, ContentBlocks::THINKING_TYPES, true)) {
                return true;
            }
        }
        foreach ($toolCalls as $call) {
            if ($call->reasoningId !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether OpenAI output items are the row's own: their function calls
     * are the row's calls, no item answers a call, and their text is the
     * row's text as core reads it (the final answer, every message, or the
     * last message).
     *
     * @param  list<mixed>  $items
     * @param  list<string>  $calls  the row's calls, sorted
     */
    private static function outputItemsAreOwn(array $items, array $calls, string $text): bool
    {
        $tools = [];
        $texts = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                return false;
            }
            $type = $item['type'] ?? null;
            if ($type === 'function_call_output') {
                return false;
            }
            if ($type === 'function_call') {
                $id = (string) ($item['id'] ?? '');
                $tools[] = strlen($id).':'.$id.'|'.(string) ($item['name'] ?? '');
            }
            if ($type === 'message') {
                $part = '';
                foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $piece) {
                    if (is_array($piece) && ($piece['type'] ?? null) === 'output_text') {
                        $part .= (string) ($piece['text'] ?? '');
                    }
                }
                $texts[] = $part;
            }
        }
        sort($tools);
        if ($tools !== $calls) {
            return false;
        }
        $candidates = [implode('', $texts), OutputItems::text($items), OutputItems::finalMessageText($items)];

        return in_array($text, $candidates, true);
    }

    /**
     * Whether Anthropic content blocks are the row's own: the tool_use
     * blocks of the model that served the response are the row's calls, no
     * block answers a call, and the text blocks are the row's text.
     *
     * @param  list<mixed>  $blocks
     * @param  list<string>  $calls  the row's calls, sorted
     */
    private static function contentBlocksAreOwn(array $blocks, array $calls, string $text): bool
    {
        $texts = '';
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) === 'tool_result') {
                return false;
            }
            if (($block['type'] ?? null) === 'text') {
                $texts .= (string) ($block['text'] ?? '');
            }
        }
        $tools = [];
        /** @var array<int, array<string, mixed>> $serving */
        $serving = $blocks;
        foreach (ContentBlocks::servingBlocks($serving) as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $id = (string) ($block['id'] ?? '');
                $tools[] = strlen($id).':'.$id.'|'.(string) ($block['name'] ?? '');
            }
        }
        sort($tools);

        return $tools === $calls && $texts === $text;
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
     * A text or user row written HERE is written outside the run that owns
     * a paused step, whatever `$runId` says, and so supersedes that step
     * (G-392/G-397). The harness records its own runs' rows through
     * recordForRun().
     *
     * @param  iterable<Message>  $messages
     * @param  list<string>|null  $toolNames  the actual offer, stored only beside pending approvals
     */
    public function record(iterable $messages, ?string $runId = null, ?array $toolNames = null): self
    {
        return $this->recordRows(self::markedAsHost($messages), $runId, $toolNames, outside: true);
    }

    /**
     * `$messages`, each assistant row carrying the host mark beside its
     * provider content, so its replay can tell it from a row the harness
     * recorded from a provider response (G-415).
     *
     * @param  iterable<Message>  $messages
     * @return list<Message>
     */
    private static function markedAsHost(iterable $messages): array
    {
        return array_map(
            static fn (Message $message): Message => $message instanceof AssistantMessage
                ? new AssistantMessage($message->content, $message->toolCalls, [...$message->additionalContent, self::HOST_ROW => true], $message->toolApprovalRequests)
                : $message,
            is_array($messages) ? array_values($messages) : iterator_to_array($messages, false),
        );
    }

    /** The additional-content key marking an assistant row a host recorded through record() (G-415). Never sent. */
    public const HOST_ROW = 'harness_host_row';

    /**
     * Append the rows a harness run wrote, attributed to `$runId`. Its text
     * and user rows never supersede a paused step: the run that resolves a
     * step writes them (G-392/G-397). A later tool-call step always does.
     *
     * @internal The harness's own turns only.
     *
     * @param  iterable<Message>  $messages
     * @param  list<string>|null  $toolNames
     */
    public function recordForRun(iterable $messages, string $runId, ?array $toolNames = null, ?int $answers = null): self
    {
        // Only the run that holds this thread's session writes as a run: from
        // anywhere else this is an ordinary record(), with its lock and its
        // replaced denials.
        $key = $this->sessionKeyOrNull();
        if ($key === null) {
            // A shared thread has no session, so no run holds it: the
            // harness's own runs write it here, unmarked.
            return $this->recordRows($messages, $runId, $toolNames, outside: true);
        }
        if (! Session::holdsLock($key)) {
            // Not the run that holds the session: a host's write, marked as
            // record() marks it (G-415).
            return $this->recordRows(self::markedAsHost($messages), $runId, $toolNames, outside: true);
        }

        return $this->recordRows($messages, $runId, $toolNames, outside: false, answers: $answers);
    }

    /**
     * @param  iterable<Message>  $messages
     * @param  list<string>|null  $toolNames
     */
    private function recordRows(iterable $messages, ?string $runId, ?array $toolNames, bool $outside, ?int $answers = null): self
    {
        $messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        // A shared thread (no participant) has no session, so no run holds it.
        $key = $this->sessionKeyOrNull();
        if ($key === null || ! $outside || ! $this->wouldSupersedeOpenStep($messages)) {
            return $this->writeRows($messages, $runId, $toolNames, $outside, $answers);
        }
        if (Session::holdsLock($key)) {
            // Written from inside the run that holds the session (a host tool
            // or listener): waiting for the lock would wait on itself, and the
            // host is never refused. When that run is answering the paused
            // step, its own result rows name that step (G-407), so nothing is
            // ended here; otherwise the step is superseded as usual (G-404).
            $step = $this->resolvableStep();
            $answering = $step !== null && $step['position'] === Session::answering($key);

            return $this->writeRows($messages, $runId, $toolNames, true, null, ! $answering);
        }
        // From outside the run (another worker or process): SERIALISED with
        // the run that may be acting on the step (G-404). It takes the
        // session's own lock and waits as a turn does, or throws SessionLocked
        // having written nothing. A run that has begun and not finished is
        // still acting on the step even if its lock lease lapsed (a call that
        // outlives lock_ttl): its calls are then left to it, never denied.
        $store = app(SessionStoreManager::class)->ephemeral();
        if (! $store instanceof FencedSessionStore) {
            return $this->writeRows($messages, $runId, $toolNames, true);
        }

        return $store->withFencedLock($key, function (Closure $owned) use ($messages, $runId, $toolNames, $key, $store): self {
            $owned();

            return $this->writeRows($messages, $runId, $toolNames, true, null, ! self::runInFlight($store, $key));
        }, max(1, (int) config('prism-harness.agent.lock_ttl', 300)), max(0, (int) config('prism-harness.agent.lock_wait', 0)));
    }

    /** Whether the session at `$key` has a run that began and has not finished. */
    private static function runInFlight(FencedSessionStore $store, string $key): bool
    {
        $state = $store->get($key.':ephemeral') ?? $store->get($key);

        return is_array($state) && is_array($state['run'] ?? null) && ($state['run']['status'] ?? null) === 'running';
    }

    /** This thread's session key, or null for a shared thread (no participant). */
    private function sessionKeyOrNull(): ?string
    {
        $address = $this->sessionAddress();

        return $address->participantType === null ? null : $address->sessionKey();
    }

    /**
     * Whether recording `$messages` outside a run would supersede the
     * paused step while it still has a call with no result.
     *
     * @param  list<Message>  $messages
     */
    private function wouldSupersedeOpenStep(array $messages): bool
    {
        $supersedes = array_filter($messages, static fn (Message $message): bool => $message instanceof AssistantMessage || $message instanceof UserMessage);
        if ($supersedes === []) {
            return false;
        }
        $step = $this->resolvableStep();

        return $step !== null && $step['assistant']->toolApprovalRequests !== []
            && self::callsWithoutResult($step['assistant']->toolCalls, $step['results']) !== [];
    }

    /** The session address this thread belongs to, as its saving hook builds it. */
    private function sessionAddress(): SessionAddress
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
     * @param  list<Message>  $messages
     * @param  list<string>|null  $toolNames
     */
    private function writeRows(array $messages, ?string $runId, ?array $toolNames, bool $outside, ?int $answers = null, bool $endSteps = true): self
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
        return DB::transaction(function () use ($messages, $runId, $toolNames, $outside, $answers, $endSteps): self {
            // Locks the thread ROW so a concurrent recorder waits instead of
            // reading the same max. A no-op on SQLite, which is why the suite
            // cannot exercise this and why the guarantee lives in the database.
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            $position = (int) $this->storedMessages()->max('position');

            [$messages, $marks, $replacedStep] = $endSteps ? $this->withReplacedStepsEnded($messages, $outside) : [$messages, new \WeakMap, null];
            $deferred = null;
            if (! $endSteps && $outside) {
                $paused = $this->resolvableStep();
                $deferred = $paused === null ? null : array_values(array_unique(array_map(
                    static fn (ToolApprovalRequest $request): string => $request->approvalId,
                    $paused['assistant']->toolApprovalRequests,
                )));
            }
            // The step each result row the HARNESS writes answers (G-407): the
            // step before it in this write, else the step this run answers.
            // Rows a host writes carry no mark.
            $own = $outside ? null : $this->newestStepOf($runId);
            $target = $outside ? null : ($answers === null ? $own : max($answers, $own ?? 0));
            foreach ($messages as $message) {
                // Re-read: a write nested inside this one (a host listener)
                // may have taken the next position.
                $position = max($position, (int) $this->storedMessages()->max('position'));
                if ($message instanceof AssistantMessage && $message->toolCalls !== [] && ! $outside) {
                    $target = $position + 1;
                }
                $payload = MessageMapper::toArray($message);
                if ($message instanceof ToolResultMessage && isset($marks[$message])) {
                    // Harness metadata, beside the results: these calls were
                    // ended because their step was superseded, under these
                    // approvals. MessageMapper does not send it back.
                    $payload[self::REPLACED_MARK] = ['approval_ids' => $marks[$message]];
                }
                if ($message instanceof AssistantMessage && $message->toolApprovalRequests !== [] && $toolNames !== null) {
                    // Harness metadata on the same durable approval record.
                    // MessageMapper does not send it back to the provider.
                    $payload['harness_tool_names'] = array_values(array_unique($toolNames));
                }
                if ($deferred !== null && $deferred !== [] && ($message instanceof UserMessage || $message instanceof AssistantMessage)) {
                    // Superseded while a run answers it: the row names the
                    // step's approvals, so no later decision for it runs.
                    $payload[self::REPLACED_MARK] = ['approval_ids' => $deferred];
                    $deferred = null;
                }
                $answered = $message instanceof ToolResultMessage && isset($marks[$message]) ? $replacedStep : $target;
                if ($message instanceof ToolResultMessage && $message->toolResults !== [] && ! $outside && $answered !== null) {
                    // Harness metadata, beside the results: the step these
                    // results answer, wherever the row lands (G-407).
                    $payload[self::ANSWERS_STEP] = ['position' => $answered];
                }
                $this->storedMessages()->create([
                    'position' => ++$position,
                    'type' => MessageMapper::typeOf($message),
                    'run_id' => $runId,
                    'payload' => $payload,
                ]);
            }

            return $this->afterRecording();
        });
    }

    /**
     * The decision standing for every approval a decision has been recorded
     * for, in any row (G-401): false when any of them is a denial, true when
     * all of them are approvals.
     *
     * @internal Read when decisions are recorded.
     *
     * @return array<string, bool>
     */
    public function recordedDecisions(): array
    {
        $standing = [];
        foreach ($this->storedMessages()->where('type', MessageMapper::TYPE_TOOL_RESULT)->reorder('position')->cursor() as $stored) {
            $payload = $stored->payload ?? null;
            foreach (is_array($payload) && is_array($payload['tool_approval_responses'] ?? null) ? $payload['tool_approval_responses'] : [] as $response) {
                if (is_array($response) && is_string($response['approval_id'] ?? null)) {
                    $id = $response['approval_id'];
                    $standing[$id] = ($standing[$id] ?? true) && ($response['approved'] ?? false) === true;
                }
            }
        }

        return $standing;
    }

    /** The result a call of a step superseded before it was resolved is recorded with (G-392/G-397). */
    public const REPLACED_REASON = 'Tool call was not run because a later step replaced it';

    /**
     * Whether the paused step repeats a call id, among its tool calls or
     * among its approval requests. A decision reaches a call through the
     * call id its request names, so a repeated id cannot say which call a
     * decision belongs to.
     *
     * The paused step is chosen by CORE's rule, the one that decides what
     * runs: the newest assistant row that carries tool calls, wherever it
     * sits. Rows recorded after it (an assistant note, a user message, a
     * system or tool result row) do not move it (G-392/G-397).
     *
     * @internal Read when decisions are recorded.
     */
    public function pausedStepRepeatsCallIds(): bool
    {
        $step = $this->resolvableStep();

        return $step !== null && self::stepRepeatsCallIds($step['assistant']);
    }

    /**
     * The approval ids of every step in this thread whose call ids or
     * request call ids repeat, wherever the step sits. A decision naming one
     * of them is recorded as a denial, so no transcript shape can make it
     * run a call (G-392/G-397).
     *
     * @internal Read when decisions are recorded.
     *
     * @return list<string>
     */
    public function repeatedIdApprovalIds(): array
    {
        $ids = [];
        foreach ($this->storedMessages()->where('type', MessageMapper::TYPE_ASSISTANT)->reorder('position')->cursor() as $stored) {
            $message = self::storedMessage($stored);
            if ($message instanceof AssistantMessage && $message->toolCalls !== [] && self::stepRepeatsCallIds($message)) {
                foreach ($message->toolApprovalRequests as $request) {
                    $ids[] = $request->approvalId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Give every call still without a result, in the paused step when its
     * call ids repeat, the denial `$reason` as its result, recorded as one
     * tool result row. Returns whether a row was written.
     *
     * A result names its call only by id, so in such a step a result of one
     * call reads as the result of every call sharing its id: an ungated call
     * that ran would make a gated call with the same id look done, and that
     * call would end with no outcome at all. Results are matched to calls BY
     * POSITION here instead (each result is used once, by the first call it
     * fits), so every gated call of the step gets exactly one outcome, and
     * none of them runs.
     *
     * The step is the one core resolves (see pausedStepRepeatsCallIds()),
     * and only a step that asked for approval is touched; calling it again
     * writes nothing.
     *
     * @internal Called by every turn before it reads the conversation.
     */
    public function endRepeatedIdStep(string $reason, ?string $runId = null): bool
    {
        $step = $this->resolvableStep();
        if ($step === null || ! self::stepRepeatsCallIds($step['assistant'])) {
            return false;
        }
        $assistant = $step['assistant'];
        $results = $step['results'];
        if ($assistant->toolApprovalRequests === []) {
            return false;
        }

        $open = self::callsWithoutResult($assistant->toolCalls, $results);
        if ($open === []) {
            return false;
        }

        $this->writeRows([new ToolResultMessage(array_map(
            static fn (ToolCall $call): ToolResult => new ToolResult(
                toolCallId: $call->id,
                toolName: $call->name,
                args: $call->argumentsForResult(),
                result: $reason,
                toolCallResultId: $call->resultId,
            ),
            $open,
        ))], $runId, null, false, $step['position'], false);

        return true;
    }

    /** The payload key of a write-ahead started row (G-410). */
    public const STARTED = 'harness_started';

    /** What a call that was started and never got a result reads (G-410). */
    public const INTERRUPTED_RESULT = 'Tool call was interrupted, it may have run';

    /**
     * The stored payload of a started row (G-410): an empty result row, the
     * call about to run and the approval it runs under, then the step it
     * answers as the LAST key. Pure.
     *
     * @return array<string, mixed>
     */
    public static function startedPayload(string $callId, string $toolName, string $approvalId, int $position): array
    {
        return [
            'tool_results' => [],
            'tool_approval_responses' => [],
            self::STARTED => ['tool_call_id' => $callId, 'tool_name' => $toolName, 'approval_id' => $approvalId],
            self::ANSWERS_STEP => ['position' => $position],
        ];
    }

    /**
     * Record the write-ahead STARTED row (G-410) for an approved call of the
     * step at `$position`, immediately before the call runs, so a call whose
     * result is never recorded is never run again. Written only while the
     * call is open in the step this run resolves (no result and no started
     * row), under its own approval request, with no denial of that approval
     * recorded after the run read the conversation (a position above
     * `$readUpTo`). Returns whether it was written: when it was not, the call
     * must not run.
     *
     * @internal The harness runtime only, while its run holds the session.
     */
    public function recordStarted(string $runId, int $position, ToolCall $call, ?int $readUpTo = null): bool
    {
        $key = $this->sessionKeyOrNull();
        if ($runId === '' || $key === null || ! Session::holdsLock($key)) {
            throw new \LogicException('Only the harness runtime records a started row, inside its run.');
        }

        return DB::transaction(function () use ($runId, $position, $call, $readUpTo): bool {
            static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            $step = $this->resolvableStep();
            if ($step === null || $step['position'] !== $position) {
                return false;
            }
            $open = false;
            foreach (self::callsWithoutResult($step['assistant']->toolCalls, $step['results']) as $candidate) {
                if ($candidate->id === $call->id && $candidate->name === $call->name) {
                    $open = true;

                    break;
                }
            }
            $approvalId = null;
            foreach ($step['assistant']->toolApprovalRequests as $request) {
                if ($request->toolCallId === $call->id) {
                    $approvalId = $request->approvalId;

                    break;
                }
            }
            if (! $open || $approvalId === null) {
                return false;
            }
            $after = $this->storedMessages()->where('type', MessageMapper::TYPE_TOOL_RESULT)->where('position', '>', max($position, $readUpTo ?? 0));
            foreach ($after->cursor() as $stored) {
                foreach ($stored->payload['tool_approval_responses'] ?? [] as $response) {
                    if (is_array($response) && ($response['approval_id'] ?? null) === $approvalId && ($response['approved'] ?? null) !== true) {
                        return false;
                    }
                }
            }

            $this->storedMessages()->create([
                'position' => (int) $this->storedMessages()->max('position') + 1,
                'type' => MessageMapper::TYPE_TOOL_RESULT,
                'run_id' => $runId,
                'payload' => self::startedPayload($call->id, $call->name, $approvalId, $position),
            ]);
            $this->afterRecording();

            return true;
        });
    }

    /**
     * Whether the call this approval was asked for was started and has no
     * result of its own: it was interrupted, and it may have run (G-410).
     */
    public function interrupted(string $approvalId): bool
    {
        foreach ($this->interruptedCalls() as $call) {
            if ($call['approval_id'] === $approvalId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The started rows the harness wrote whose call has no result of its
     * own, other than the running run's, by the started row's position: the step it answers, its approval,
     * and the interrupted result the call is replayed with (G-410).
     *
     * @return array<int, array{step: int, approval_id: string, result: ToolResult}>
     */
    private function interruptedCalls(): array
    {
        // The run that started a call still reads it as awaiting its result
        // while it runs: what it records for the call is that result.
        $key = $this->sessionKeyOrNull();
        $running = $key === null ? null : Session::heldRun($key);
        $rows = [];
        foreach ($this->storedMessages()->reorder('position')->cursor() as $stored) {
            $rows[(int) $stored->position] = $stored;
        }
        $interrupted = [];
        foreach ($rows as $at => $stored) {
            $start = ($stored->type ?? null) === MessageMapper::TYPE_TOOL_RESULT ? self::startedMark($stored) : null;
            if ($start === null || $start['step'] >= $at || ! isset($rows[$start['step']]) || ($running !== null && $stored->run_id === $running)) {
                continue;
            }
            $step = self::storedMessage($rows[$start['step']]);
            if (! $step instanceof AssistantMessage || $step->toolCalls === []) {
                continue;
            }
            $call = null;
            foreach ($step->toolCalls as $candidate) {
                if ($candidate->id === $start['tool_call_id'] && $candidate->name === $start['tool_name']) {
                    $call = $candidate;

                    break;
                }
            }
            if ($call === null || $this->hasOwnResult($rows, $start['step'], $call)) {
                continue;
            }
            $interrupted[$at] = ['step' => $start['step'], 'approval_id' => $start['approval_id'], 'result' => self::interruptedResult($call)];
        }

        return $interrupted;
    }

    /**
     * Whether `$call` of the step at `$position` has a result of its own: a
     * result row after the step whose valid mark names the step, or one with
     * no mark in the step's own segment.
     *
     * @param  array<int, mixed>  $rows
     */
    private function hasOwnResult(array $rows, int $position, ToolCall $call): bool
    {
        $inSegment = true;
        foreach ($rows as $at => $stored) {
            if ($at <= $position) {
                continue;
            }
            $message = self::storedMessage($stored);
            if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
                $inSegment = false;

                continue;
            }
            if (! $message instanceof ToolResultMessage || self::startedMark($stored) !== null) {
                continue;
            }
            $mark = $stored->payload[self::ANSWERS_STEP]['position'] ?? null;
            $mine = is_int($mark) ? $mark === $position && $this->answersStep($message, $mark, $at) : $inSegment;
            if (! $mine) {
                continue;
            }
            foreach ($message->toolResults as $result) {
                if ($result->toolCallId === $call->id && $result->toolName === $call->name) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The started mark of a row the harness wrote, or null. Honoured only on
     * a row with a run id (no public record() path writes the key) whose
     * payload is exactly the started shape; any other row carrying the key
     * is read as if it were absent (G-410).
     *
     * @return array{tool_call_id: string, tool_name: string, approval_id: string, step: int}|null
     */
    private static function startedMark(mixed $stored): ?array
    {
        $payload = is_object($stored) ? ($stored->payload ?? null) : null;
        $runId = is_object($stored) ? ($stored->run_id ?? null) : null;
        if (! is_string($runId) || $runId === '' || ! is_array($payload)) {
            return null;
        }
        $start = $payload[self::STARTED] ?? null;
        $step = $payload[self::ANSWERS_STEP]['position'] ?? null;
        if (! is_array($start) || ! is_int($step) || ($payload['tool_results'] ?? null) !== [] || ($payload['tool_approval_responses'] ?? null) !== []
            || ! is_string($start['tool_call_id'] ?? null) || ! is_string($start['tool_name'] ?? null) || ! is_string($start['approval_id'] ?? null)) {
            return null;
        }

        return ['tool_call_id' => $start['tool_call_id'], 'tool_name' => $start['tool_name'], 'approval_id' => $start['approval_id'], 'step' => $step];
    }

    private static function interruptedResult(ToolCall $call): ToolResult
    {
        return new ToolResult(
            toolCallId: $call->id,
            toolName: $call->name,
            args: $call->argumentsForResult(),
            result: self::INTERRUPTED_RESULT,
            toolCallResultId: $call->resultId,
        );
    }

    /**
     * The step core resolves approvals for: the newest assistant row with
     * tool calls, not stopping at user, system or text-only assistant rows,
     * and every tool result recorded after it, in order.
     *
     * @return array{assistant: AssistantMessage, results: list<ToolResult>, position: int}|null
     */
    private function resolvableStep(): ?array
    {
        $rows = [];
        $started = [];
        foreach ($this->storedMessages()->reorder('position', 'desc')->cursor() as $stored) {
            $message = self::storedMessage($stored);
            if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
                $position = (int) $stored->position;
                $results = [];
                foreach (array_reverse($rows) as [$row, $mark, $at]) {
                    // A harness row naming another step answers that step,
                    // not this one (G-407), when its mark is valid.
                    if ($mark !== null && $mark !== $position && $this->answersStep($row, $mark, $at)) {
                        continue;
                    }
                    $results = [...$results, ...$row->toolResults];
                }
                // A call this step's run started (G-410) is answered: in
                // progress, or interrupted when it has no result of its own.
                foreach (array_reverse($started) as $start) {
                    if ($start['step'] !== $position) {
                        continue;
                    }
                    foreach (self::callsWithoutResult($message->toolCalls, $results) as $call) {
                        if ($call->id === $start['tool_call_id'] && $call->name === $start['tool_name']) {
                            $results[] = self::interruptedResult($call);

                            break;
                        }
                    }
                }

                return ['assistant' => $message, 'results' => $results, 'position' => $position];
            }
            if (($stored->type ?? null) === MessageMapper::TYPE_TOOL_RESULT && ($start = self::startedMark($stored)) !== null) {
                // A started row the harness wrote carries no result. On any
                // other row the key is read as if it were absent (G-410).
                $started[] = $start;

                continue;
            }
            if ($message instanceof ToolResultMessage) {
                $mark = is_array($stored->payload ?? null) ? ($stored->payload[self::ANSWERS_STEP]['position'] ?? null) : null;
                $rows[] = [$message, is_int($mark) ? $mark : null, (int) $stored->position];
            }
        }

        return null;
    }

    /**
     * The approval ids of the paused step whose call already has an outcome:
     * a result, or a started row. Each request is paired with its own call
     * as the unresolved reader pairs them (the first call with the request's
     * tool call id not taken by an earlier request), and an answered call's
     * approval is no longer pending, so no decision for it is recorded.
     *
     * @internal Read when decisions are recorded.
     *
     * @return list<string>
     */
    public function answeredApprovalIds(): array
    {
        $step = $this->resolvableStep();
        if ($step === null || $step['assistant']->toolApprovalRequests === []) {
            return [];
        }
        $open = self::callsWithoutResult($step['assistant']->toolCalls, $step['results']);
        $taken = [];
        $answered = [];
        foreach ($step['assistant']->toolApprovalRequests as $request) {
            foreach ($step['assistant']->toolCalls as $index => $call) {
                if (isset($taken[$index]) || $call->id !== $request->toolCallId) {
                    continue;
                }
                $taken[$index] = true;
                if (! in_array($call, $open, true)) {
                    $answered[] = $request->approvalId;
                }

                break;
            }
        }

        return array_values(array_unique($answered));
    }

    /**
     * Whether core, resolving the paused step, would append its results
     * after a row that is not a tool result (G-412): the step has a call
     * with no result, and a user, assistant or system row follows it, or the
     * turn adds a new message (`$withPrompt`). Core puts the resolution at
     * the end of the request, so then the harness resolves the step itself
     * and places the results directly after it.
     *
     * @internal Read before a run hands its history to core.
     */
    public function resolutionWouldTrail(bool $withPrompt): bool
    {
        $step = $this->resolvableStep();
        if ($step === null || self::callsWithoutResult($step['assistant']->toolCalls, $step['results']) === []) {
            return false;
        }

        return $withPrompt || $this->storedMessages()->where('position', '>', $step['position'])
            ->where('type', '!=', MessageMapper::TYPE_TOOL_RESULT)->exists();
    }

    /**
     * Give each call of the paused step that no result answers and that no
     * approval request of the step names `$reason` as its stored result, so
     * it is never run (G-412). Core resolves only the calls a request names:
     * any other call of the step (an ungated call whose result was never
     * stored, or one a host recorded) would stay unanswered in every later
     * request, which a provider rejects. Returns whether a row was written.
     *
     * @internal Called before a run reads the conversation.
     */
    public function endUnrequestedOpenCalls(string $reason, ?string $runId = null): bool
    {
        $step = $this->resolvableStep();
        if ($step === null) {
            return false;
        }
        $requested = array_fill_keys(array_map(static fn (ToolApprovalRequest $request): string => $request->toolCallId, $step['assistant']->toolApprovalRequests), true);
        $open = array_values(array_filter(
            self::callsWithoutResult($step['assistant']->toolCalls, $step['results']),
            static fn (ToolCall $call): bool => ! isset($requested[$call->id]),
        ));
        if ($open === []) {
            return false;
        }

        $this->writeRows([new ToolResultMessage(array_map(
            static fn (ToolCall $call): ToolResult => new ToolResult(
                toolCallId: $call->id,
                toolName: $call->name,
                args: $call->argumentsForResult(),
                result: $reason,
                toolCallResultId: $call->resultId,
            ),
            $open,
        ))], $runId, null, false, $step['position'], false);

        return true;
    }

    /** The position of the step a run would resolve, or null. @internal */
    public function pausedStepPosition(): ?int
    {
        return $this->resolvableStep()['position'] ?? null;
    }

    /** The payload key naming the step a harness result row answers (G-407). */
    public const ANSWERS_STEP = 'harness_answers_step';

    /**
     * Whether `$row`, stored at `$at`, validly answers the step at `$position`:
     * an earlier tool-call step with a call of each result's id and tool name.
     */
    private function answersStep(ToolResultMessage $row, int $position, int $at): bool
    {
        if ($position >= $at) {
            return false;
        }
        $stored = $this->storedMessages()->where('position', $position)->first();
        $step = $stored === null ? null : self::storedMessage($stored);
        if (! $step instanceof AssistantMessage || $step->toolCalls === []) {
            return false;
        }
        $calls = array_map(static fn (ToolCall $call): string => $call->id."\0".$call->name, $step->toolCalls);
        foreach ($row->toolResults as $result) {
            if (! in_array($result->toolCallId."\0".$result->toolName, $calls, true)) {
                return false;
            }
        }

        return true;
    }

    /** The position of the newest tool-call step `$runId` recorded, or null. */
    private function newestStepOf(?string $runId): ?int
    {
        if ($runId === null) {
            return null;
        }
        foreach ($this->storedMessages()->where('type', MessageMapper::TYPE_ASSISTANT)->where('run_id', $runId)->reorder('position', 'desc')->cursor() as $stored) {
            $message = self::storedMessage($stored);
            if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
                return (int) $stored->position;
            }
        }

        return null;
    }

    /** A stored row as a Prism message, or null when the row cannot be read. */
    private static function storedMessage(mixed $stored): ?Message
    {
        $type = is_object($stored) ? ($stored->type ?? null) : null;
        $payload = is_object($stored) ? ($stored->payload ?? null) : null;

        return is_string($type) && is_array($payload) ? MessageMapper::fromArray($type, $payload) : null;
    }

    /**
     * Whether a step's tool call ids, its requests' tool call ids, or its
     * requests' approval ids repeat. An empty id is an id.
     */
    private static function stepRepeatsCallIds(AssistantMessage $step): bool
    {
        $callIds = array_map(static fn (ToolCall $call): string => $call->id, $step->toolCalls);
        $requestCallIds = array_map(static fn (ToolApprovalRequest $request): string => $request->toolCallId, $step->toolApprovalRequests);
        $approvalIds = array_map(static fn (ToolApprovalRequest $request): string => $request->approvalId, $step->toolApprovalRequests);

        return count($callIds) !== count(array_unique($callIds, SORT_STRING))
            || count($requestCallIds) !== count(array_unique($requestCallIds, SORT_STRING))
            || count($approvalIds) !== count(array_unique($approvalIds, SORT_STRING));
    }

    /**
     * The approval ids of every step that asked for approval and was
     * SUPERSEDED: a later assistant row with tool calls was recorded after
     * it, or a text or user row recorded outside its run ended it (the
     * {@see self::REPLACED_MARK} on the row that did). A decision naming one
     * of them is recorded as a denial, so it runs nothing (G-392/G-397).
     *
     * @internal Read when decisions are recorded.
     *
     * @return list<string>
     */
    public function replacedApprovalIds(): array
    {
        $ids = [];
        $steps = [];
        foreach ($this->storedMessages()->reorder('position')->cursor() as $stored) {
            $payload = $stored->payload ?? null;
            $mark = is_array($payload) ? ($payload[self::REPLACED_MARK]['approval_ids'] ?? null) : null;
            if (is_array($mark)) {
                $ids = [...$ids, ...array_filter($mark, 'is_string')];
            }
            $message = ($stored->type ?? null) === MessageMapper::TYPE_ASSISTANT ? self::storedMessage($stored) : null;
            if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
                $steps[] = $message;
            }
        }
        array_pop($steps);
        foreach ($steps as $step) {
            foreach ($step->toolApprovalRequests as $request) {
                $ids[] = $request->approvalId;
            }
        }

        return array_values(array_unique($ids));
    }

    /** The payload key marking a row that ended a superseded step's calls (G-392/G-397). */
    public const REPLACED_MARK = 'harness_replaced';

    /**
     * `$messages`, with a result row ending the calls of a step that is
     * superseded before it was resolved, and those rows' approval ids
     * (G-392/G-397).
     *
     * A later assistant row WITH TOOL CALLS always supersedes the step that
     * asked for approval: core resolves only the newest. A later assistant
     * text row or user row supersedes it when written outside the run that
     * owns it (`$outside`): a host's note, or the user's follow-up. A call of
     * that step with no result of its own gets the denial
     * {@see self::REPLACED_REASON}, recorded immediately before the row that
     * supersedes it, and so exactly once; a result for it later in the same
     * write counts as its result. Results are matched to calls by position,
     * as in endRepeatedIdStep(). System and tool result rows supersede
     * nothing.
     *
     * @param  iterable<Message>  $messages
     * @return array{0: list<Message>, 1: \WeakMap<ToolResultMessage, list<string>>, 2: ?int}
     */
    private function withReplacedStepsEnded(iterable $messages, bool $outside): array
    {
        $messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        /** @var \WeakMap<ToolResultMessage, list<string>> $marks */
        $marks = new \WeakMap;
        $supersedes = static fn (Message $message): bool => ($message instanceof AssistantMessage && $message->toolCalls !== [])
            || ($outside && ($message instanceof AssistantMessage || $message instanceof UserMessage));
        if (array_filter($messages, $supersedes) === []) {
            return [$messages, $marks, null];
        }

        $step = $this->resolvableStep();
        $current = $step['assistant'] ?? null;
        $results = $step['results'] ?? [];
        $replacedStep = $step['position'] ?? null;
        $out = [];
        foreach ($messages as $index => $message) {
            if ($supersedes($message) && $current instanceof AssistantMessage && $current->toolApprovalRequests !== []) {
                // Results this write records for the step, after this row.
                $ahead = [];
                for ($i = $index + 1, $n = count($messages); $i < $n; $i++) {
                    if ($messages[$i] instanceof AssistantMessage && $messages[$i]->toolCalls !== []) {
                        break;
                    }
                    if ($messages[$i] instanceof ToolResultMessage) {
                        $ahead = [...$ahead, ...$messages[$i]->toolResults];
                    }
                }
                $open = self::callsWithoutResult($current->toolCalls, [...$results, ...$ahead]);
                if ($open !== []) {
                    $ended = array_map(
                        static fn (ToolCall $call): ToolResult => new ToolResult(
                            toolCallId: $call->id,
                            toolName: $call->name,
                            args: $call->argumentsForResult(),
                            result: self::REPLACED_REASON,
                            toolCallResultId: $call->resultId,
                        ),
                        $open,
                    );
                    $row = new ToolResultMessage($ended);
                    $marks[$row] = array_values(array_unique(array_map(
                        static fn (ToolApprovalRequest $request): string => $request->approvalId,
                        $current->toolApprovalRequests,
                    )));
                    $out[] = $row;
                    $results = [...$results, ...$ended];
                }
            }
            if ($message instanceof AssistantMessage && $message->toolCalls !== []) {
                $current = $message;
                $results = [];
            } elseif ($message instanceof ToolResultMessage) {
                $results = [...$results, ...$message->toolResults];
            }
            $out[] = $message;
        }

        return [$out, $marks, $replacedStep];
    }

    /**
     * The calls no result answers, results matched to calls by position: a
     * result is used once, by the first call with its id, name and arguments,
     * then by the first with its id and name.
     *
     * @param  list<ToolCall>  $calls
     * @param  list<ToolResult>  $results
     * @return list<ToolCall>
     */
    private static function callsWithoutResult(array $calls, array $results): array
    {
        $answered = [];
        foreach ([true, false] as $exact) {
            foreach ($calls as $index => $call) {
                if (isset($answered[$index])) {
                    continue;
                }
                foreach ($results as $position => $result) {
                    if ($result->toolCallId === $call->id && $result->toolName === $call->name
                        && (! $exact || $result->args == $call->argumentsForResult())) {
                        $answered[$index] = true;
                        unset($results[$position]);

                        break;
                    }
                }
            }
        }

        return array_values(array_filter($calls, static fn (int $index): bool => ! isset($answered[$index]), ARRAY_FILTER_USE_KEY));
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
        $decisions = [];
        foreach ($this->storedMessages()->reorder('position', 'desc')->cursor() as $stored) {
            $payload = $stored->payload;
            if ($stored->type === MessageMapper::TYPE_TOOL_RESULT) {
                foreach ($payload['tool_approval_responses'] ?? [] as $response) {
                    if (is_array($response) && is_string($response['approval_id'] ?? null)) {
                        $decisions[$response['approval_id']] ??= ($response['approved'] ?? false) === true;
                    }
                }

                continue;
            }
            if ($stored->type === MessageMapper::TYPE_USER) {
                return null;
            }
            if ($stored->type !== MessageMapper::TYPE_ASSISTANT) {
                continue;
            }
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
            foreach ($requests as $request) {
                if (is_array($request) && is_string($request['approval_id'] ?? null)
                    && ($decisions[$request['approval_id']] ?? false)
                    && is_string($request['tool_call_id'] ?? null)) {
                    foreach ($payload['tool_calls'] ?? [] as $call) {
                        if (is_array($call) && ($call['id'] ?? null) === $request['tool_call_id']
                            && is_string($call['name'] ?? null) && $call['name'] !== '' && $call['name'] !== '*') {
                            $names[] = $call['name'];
                        }
                    }
                }
            }

            return array_values(array_unique($names));
        }

        return null;
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
     * The model steps the turn at the transcript tail has taken: one
     * assistant row per step since the turn's user row, read from the raw
     * stored rows like {@see self::approvalRunSteps()}.
     *
     * An empty prompt records no user row, so it continues this turn whether
     * or not an approval is waiting, and its step count must too.
     */
    public function turnSteps(): int
    {
        $steps = 0;
        foreach ($this->storedMessages()->reorder('position', 'desc')->cursor() as $stored) {
            if ($stored->type === MessageMapper::TYPE_USER) {
                break;
            }
            $steps += $stored->type === MessageMapper::TYPE_ASSISTANT ? 1 : 0;
        }

        return $steps;
    }

    private function afterRecording(): self
    {

        // The relation may already be loaded; without this a caller that reads
        // messages() after record() in the same request sees the stale set.
        $this->unsetRelation('storedMessages');

        return $this;
    }
}
