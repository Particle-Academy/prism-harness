<?php

declare(strict_types=1);

namespace Prism\Harness\Sessions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Models\Thread;

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

    public function __construct(
        protected readonly Model $participant,
        protected readonly string $scope,
        protected readonly SessionStore $ephemeral,
        protected readonly SessionStore $durable,
        protected readonly ?int $ttlSeconds = null,
    ) {}

    public function scope(): string
    {
        return $this->scope;
    }

    public function participant(): Model
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
        return sprintf(
            'session:%s:%s:%s',
            substr(sha1($this->participant->getMorphClass()), 0, 12),
            (string) $this->participant->getKey(),
            $this->scope,
        );
    }

    public function mode(): ?string
    {
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

    /**
     * The stored conversation this session is bound to.
     *
     * Durable by construction — an Eloquent thread, not session state — so it
     * is the one thing here that a flushed Redis cannot take away.
     */
    public function thread(): Thread
    {
        return Thread::forParticipant($this->participant, $this->scope);
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
        $this->ephemeral->forget($this->key());
        $this->cachedState = null;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->cachedState ??= $this->ephemeral->get($this->key()) ?? [];
    }

    protected function write(string $key, mixed $value): self
    {
        $state = $this->state();
        $state[$key] = $value;

        $this->ephemeral->put($this->key(), $state, $this->ttlSeconds);
        $this->cachedState = $state;

        return $this;
    }
}
