<?php

declare(strict_types=1);

namespace Prism\Harness\Sessions;

use Closure;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Prism\Harness\AgentResponse;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Models\Thread;
use Prism\Prism\Streaming\Events\StreamEvent;
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

    public function __construct(
        protected readonly Model $participant,
        protected readonly string $scope,
        protected readonly SessionStore $ephemeral,
        protected readonly SessionStore $durable,
        protected readonly ?int $ttlSeconds = null,
        protected readonly ?AgentRuntime $runtime = null,
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
        $durable = $this->durable->get($this->durableKey()) ?? [];
        $capabilities = is_array($durable['capabilities'] ?? null) ? $durable['capabilities'] : [];
        $capabilities[$name] = $state;
        $durable['capabilities'] = $capabilities;
        $this->durable->put($this->durableKey(), $durable);

        return $this;
    }

    public function forgetCapability(string $name): self
    {
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
        return Thread::forParticipant($this->participant, $this->scope);
    }

    /** @param list<string>|null $toolNames */
    public function send(string $prompt, ?array $toolNames = null): AgentResponse
    {
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        return $this->runtime->send($this, $prompt, $toolNames);
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
     * @return Generator<int, StreamEvent>
     */
    public function stream(string $prompt, ?array $toolNames = null): Generator
    {
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        yield from $this->runtime->stream($this, $prompt, $toolNames);
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
    public function approve(ToolApprovalRequest|string $approval, bool $approved = true, ?string $reason = null): AgentResponse
    {
        if (! $this->runtime instanceof AgentRuntime) {
            throw new \LogicException('This Harness session has no agent runtime.');
        }

        $id = $approval instanceof ToolApprovalRequest ? $approval->approvalId : $approval;

        $this->thread()->record([
            new ToolResultMessage(toolApprovalResponses: [
                new ToolApprovalResponse($id, $approved, $reason),
            ]),
        ], $this->run()['id'] ?? null);

        // Resumed with an EMPTY prompt: the conversation already contains the
        // request, the decision, and everything before them. A new prompt here
        // would be a second instruction competing with the one the tool call
        // came from.
        return $this->runtime->send($this, '');
    }

    /**
     * Reject a pending approval. The tool does not run.
     */
    public function deny(ToolApprovalRequest|string $approval, ?string $reason = null): AgentResponse
    {
        return $this->approve($approval, false, $reason);
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

    protected function write(string $key, mixed $value): self
    {
        $state = $this->state();
        $state[$key] = $value;

        $this->ephemeral->put($this->ephemeralKey(), $state, $this->ttlSeconds);
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

        return $this->write('run', [
            ...$run, ...$details, 'status' => $status, 'finished_at' => now()->toIso8601String(),
        ]);
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
