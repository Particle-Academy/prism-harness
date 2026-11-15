<?php

declare(strict_types=1);

use Closure as BaseClosure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\FencedSessionStore;
use Prism\Harness\Enums\Durability;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\SessionLockLost;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Stores\DatabaseSessionStore;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

// Every streaming entry holds the session's fenced lock for its whole
// iteration, as send() holds it for its call. Before this, none did: two
// requests could run one session at once (a double submit, or a decision
// racing a new turn), and a stream started inside another holder's lock made
// a provider call where send() refused with SessionLocked.

function lockedSession(string $scope = 'locked'): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Locker']))->session($scope);
}

function fakeAnswers(int $count = 4): PrismFake
{
    return Prism::fake(array_map(fn (int $i) => TextResponseFake::make()->withText('answer '.$i), range(1, $count)));
}

/** Start one of the three streaming surfaces and consume it. */
function runSurface(Session $session, string $surface, ?RunContext $context): void
{
    $stream = match ($surface) {
        'stream' => $session->stream('go', context: $context),
        'streamSteps' => $session->streamSteps('go', context: $context),
        'streamDecision' => $session->streamDecision([new ToolApprovalResponse('apr_probe', true)], $context),
    };
    foreach ($stream as $item) {
        // consume
    }
}

/** @return iterable<string, array{string, bool}> */
function lockSurfaces(): iterable
{
    foreach (['stream', 'streamSteps', 'streamDecision'] as $surface) {
        foreach ([false, true] as $metadata) {
            yield $surface.($metadata ? ' with RunContext::metadata' : '') => [$surface, $metadata];
        }
    }
}

it('refuses to stream a session another request holds, with zero provider calls', function (string $surface, bool $metadata): void {
    $fake = fakeAnswers();
    $holder = lockedSession();
    $caught = null;

    $holder->lock(function () use ($surface, $metadata, &$caught): void {
        try {
            runSurface(lockedSession(), $surface, $metadata ? RunContext::metadata('turn-1', 'user:1') : null);
        } catch (Throwable $failure) {
            $caught = $failure;
        }
    }, waitSeconds: 0);

    expect($caught)->toBeInstanceOf(SessionLocked::class)
        ->and(lockedSession()->run())->toBeNull();
    $fake->assertCallCount(0);
    if ($surface === 'streamDecision') {
        // Recorded inside the lock, so a held lock means NOT recorded.
        expect(Thread::query()->where('scope', 'locked')->exists()
            ? Thread::query()->where('scope', 'locked')->firstOrFail()->storedMessages()->count() : 0)->toBe(0);
    }
})->with(lockSurfaces());

it('frees the lock when the stream completes', function (string $surface): void {
    fakeAnswers();
    $session = lockedSession();

    runSurface($session, $surface, null);

    // Took the lock, and gave it back: a held lock would refuse this.
    expect(lockedSession()->send('next')->text())->toBe('answer 2')
        ->and(lockedSession()->lock(fn (): string => 'free', waitSeconds: 0))->toBe('free');
})->with(['stream', 'streamSteps', 'streamDecision']);

it('frees the lock, and closes the run, when the stream is abandoned after one step', function (string $surface): void {
    fakeAnswers();
    $session = lockedSession();
    $stream = $surface === 'stream' ? $session->stream('go') : $session->streamSteps('go');

    $stream->current();
    expect(fn () => lockedSession()->lock(fn () => null, waitSeconds: 0))->toThrow(SessionLocked::class);
    unset($stream);

    expect(lockedSession()->run()['status'])->not->toBe('running')
        ->and(lockedSession()->send('next')->text())->toBe('answer 2');
})->with(['stream', 'streamSteps']);

it('runs two streams of one session one after the other', function (): void {
    fakeAnswers();

    $first = iterator_to_array(lockedSession()->streamSteps('one'), false);
    $second = iterator_to_array(lockedSession()->stream('two'), false);

    expect($first)->not->toBeEmpty()
        ->and($second)->not->toBeEmpty()
        ->and(lockedSession()->run()['status'])->toBe('completed');
});

it('refuses to stream on a store that cannot hold its lock across yields', function (string $surface, bool $indexed): void {
    $fake = fakeAnswers();
    $inner = new DatabaseSessionStore(DB::connection());
    // Fenced, so send() accepts it, but not HeldSessionLocks.
    $store = new class($inner) implements FencedSessionStore
    {
        public function __construct(private readonly DatabaseSessionStore $inner) {}

        public function get(string $key): ?array
        {
            return $this->inner->get($key);
        }

        public function put(string $key, array $payload, ?int $ttlSeconds = null): void
        {
            $this->inner->put($key, $payload, $ttlSeconds);
        }

        public function forget(string $key): void
        {
            $this->inner->forget($key);
        }

        public function withLock(string $key, BaseClosure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
        {
            return $this->inner->withLock($key, $callback, $ttlSeconds, $waitSeconds);
        }

        public function durability(): Durability
        {
            return $this->inner->durability();
        }

        public function withFencedLock(string $key, BaseClosure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
        {
            return $this->inner->withFencedLock($key, $callback, $ttlSeconds, $waitSeconds);
        }

        public function putIfOwned(string $sessionKey, string $token, array $payload, ?int $ttlSeconds = null): bool
        {
            return $this->inner->putIfOwned($sessionKey, $token, $payload, $ttlSeconds);
        }
    };
    $session = new Session(Participant::create(['name' => 'Custom store']), 'custom-held', $store, $inner,
        runtime: app(AgentRuntime::class), agent: $indexed ? 'custom-agent' : null);

    expect(fn () => runSurface($session, $surface, null))
        ->toThrow(LogicException::class, 'HeldSessionLocks');
    $fake->assertCallCount(0);
    expect($session->run())->toBeNull();
    if (! $indexed) {
        // The synchronous path still works on the same store.
        expect($session->send('sync is fine')->text())->toBe('answer 1');
    }
})->with(['stream', 'streamSteps', 'streamDecision'])->with(['plain' => false, 'indexed' => true]);

it('rejects the write, rather than writing unfenced, when the lease lapses mid-stream', function (): void {
    config()->set('prism-harness.agent.lock_ttl', 1);
    config()->set('prism.providers.anthropic.api_key', 'synthetic-offline');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'budget-model');
    config()->set('prism-harness.agent.modes.chat.tools', ['tick']);
    app(ToolRegistry::class)->register((new Tool)->as('tick')->for('Count')->using(function (): string {
        usleep(2_200_000);

        return 'ticked';
    }));
    Http::fakeSequence()
        ->push(budgetSseReply(1), 200, ['Content-Type' => 'text/event-stream'])
        ->push(budgetSseReply(2, true), 200, ['Content-Type' => 'text/event-stream']);
    $session = lockedSession('lapsing');

    $caught = null;
    try {
        iterator_to_array($session->stream('go'), false);
    } catch (Throwable $failure) {
        $caught = $failure;
    }

    expect($caught)->toBeInstanceOf(SessionLockLost::class)
        // The terminal write was refused, so the stale holder never closed the run.
        ->and(lockedSession('lapsing')->run()['status'])->toBe('running');
});
