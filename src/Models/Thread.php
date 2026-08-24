<?php

declare(strict_types=1);

namespace Prism\Harness\Models;

use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Prism\Harness\Support\MessageMapper;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Thread as ThreadContract;

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
 * @property string $scope
 * @property string|null $title
 * @property array<string, mixed>|null $metadata
 * @property-read Collection<int, ThreadMessage> $storedMessages
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
     * Resolve the one thread addressed by this participant and scope.
     *
     * Uses `firstOrCreate` because a session is *resolved*, not constructed —
     * a fresh worker asking for the same address must land on the same
     * conversation rather than starting a new one.
     */
    public static function forParticipant(Model $participant, string $scope): self
    {
        /** @var self */
        return static::query()->firstOrCreate([
            'participant_type' => $participant->getMorphClass(),
            'participant_id' => $participant->getKey(),
            'scope' => $scope,
        ]);
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
     * The conversation so far, oldest first.
     *
     * Yields rather than building an array: Prism materialises what it needs
     * for the payload, and this way a long history is paged out of the database
     * in chunks instead of every row being hydrated at once.
     *
     * @return Generator<int, Message>
     */
    #[\Override]
    public function messages(): Generator
    {
        foreach ($this->storedMessages()->lazy() as $stored) {
            yield $stored->toPrismMessage();
        }
    }

    /**
     * Append messages to the end of the conversation.
     *
     * Made for `$response->messages`, which is the full exchange including the
     * tool calls and results from every step — so recording a turn is one call
     * and a conversation interrupted mid-tool-loop resumes where it stopped.
     *
     * @param  iterable<Message>  $messages
     */
    public function record(iterable $messages): self
    {
        $position = (int) $this->storedMessages()->max('position');

        foreach ($messages as $message) {
            $this->storedMessages()->create([
                'position' => ++$position,
                'type' => MessageMapper::typeOf($message),
                'payload' => MessageMapper::toArray($message),
            ]);
        }

        // The relation may already be loaded; without this a caller that reads
        // messages() after record() in the same request sees the stale set.
        $this->unsetRelation('storedMessages');

        return $this;
    }
}
