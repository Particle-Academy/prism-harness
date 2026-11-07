<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Stores\DatabaseSessionStore;
use Tests\Fixtures\InterleavingStore;
use Tests\Fixtures\Participant;
use Tests\TestCase;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

final class FencedSyncPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('prism.providers.anthropic.api_key', 'synthetic-offline');
        config()->set('prism-harness.agent.provider', 'anthropic');
        config()->set('prism-harness.agent.model', 'budget-model');
        config()->set('prism-harness.agent.modes.chat.tools', []);
    }

    public static function paths(): iterable
    {
        foreach ([false, true] as $structured) {
            foreach ([false, true] as $providerFails) {
                foreach ([false, true] as $capped) {
                    foreach ([false, true] as $held) {
                        yield ($structured ? 'structured' : 'text').'-'.($providerFails ? 'failed' : 'completed').'-'.($capped ? 'capped' : 'uncapped').'-replacement-'.($held ? 'held' : 'released') => [$capped, $held, $providerFails, $structured];
                    }
                }
            }
        }
    }

    #[DataProvider('paths')]
    public function test_an_expired_sender_preserves_replacement_state_and_lock(bool $capped, bool $held, bool $providerFails, bool $structured): void
    {
        $participant = Participant::create(['name' => 'Native sync fence']);
        $session = app(PrismHarness::class)->for($participant)->session('native-sync-fence');
        $other = app(PrismHarness::class)->for($participant)->session('native-sync-fence');
        $first = null;
        $oldRun = null;
        $finished = [];
        $failed = [];
        Event::listen(RunStarted::class, static function (RunStarted $event) use (&$oldRun): void {
            $oldRun = $event->runId;
        });
        Event::listen(RunFinished::class, static function (RunFinished $event) use (&$finished): void {
            $finished[] = $event->runId;
        });
        Event::listen(RunFailed::class, static function (RunFailed $event) use (&$failed): void {
            $failed[] = $event->runId;
        });
        DB::connection()->beforeExecuting(static function (string $query, array $bindings) use (&$first): void {
            if (Fiber::getCurrent() !== $first || ! str_starts_with(strtolower($query), 'update "harness_session_state"')) {
                return;
            }
            foreach ($bindings as $value) {
                $payload = is_string($value) ? json_decode($value, true) : null;
                if (is_array($payload) && in_array($payload['run']['status'] ?? null, ['completed', 'failed'], true)) {
                    Fiber::suspend('before-'.$payload['run']['status'].'-update');

                    return;
                }
            }
        });
        if ($providerFails) {
            Http::fake(static fn () => throw new RuntimeException('synthetic-provider-failure'));
        } else {
            Http::fake(['*' => Http::response(budgetHttpReply(1, true))]);
        }
        $first = new Fiber(static fn () => $structured
            ? $session->sendStructured('Native sync fence', callBudgetSchema(), context: $capped ? callBudgetContext(100.0, 1000) : null)
            : $session->send('Native sync fence', context: $capped ? callBudgetContext(100.0, 1000) : null));
        $second = new Fiber(static function () use ($other, $held): void {
            $other->lock(static function (Session $locked) use ($held): void {
                $locked->beginRun('run_replacement', 'chat', 'anthropic', 'budget-model');
                if ($held) {
                    Fiber::suspend('replacement-held');
                }
            }, ttlSeconds: 300, waitSeconds: 0);
        });
        $caught = null;
        try {
            self::assertSame('before-'.($providerFails ? 'failed' : 'completed').'-update', $first->start());
            DB::table('harness_session_state')->where('key', 'lock:'.$session->key())
                ->update(['expires_at' => DB::raw("datetime(CURRENT_TIMESTAMP, '-1 second')")]);
            self::assertSame($held ? 'replacement-held' : null, $second->start());
            $state = (array) DB::table('harness_session_state')->where('key', $session->key().':ephemeral')->first();
            $lock = (array) DB::table('harness_session_state')->where('key', 'lock:'.$session->key())->first();
            self::assertSame('run_replacement', json_decode($state['payload'], true)['run']['id']);
            try {
                $first->resume();
            } catch (Throwable $failure) {
                $caught = $failure;
            }
            $actualState = (array) DB::table('harness_session_state')->where('key', $session->key().':ephemeral')->first();
            $actualLock = (array) DB::table('harness_session_state')->where('key', 'lock:'.$session->key())->first();
            $observation = ['structured' => $structured, 'providerFails' => $providerFails, 'capped' => $capped, 'held' => $held, 'oldRun' => $oldRun,
                'failure' => $caught === null ? null : $caught::class,
                'replacementStatePreserved' => $state === $actualState,
                'replacementLockPreserved' => $lock === $actualLock,
                'finished' => $finished, 'failed' => $failed,
                'replacementRun' => json_decode($state['payload'], true)['run'],
                'actualRun' => json_decode($actualState['payload'], true)['run']];
            if (is_string($output = getenv('G229_SYNC_RACE_OUTPUT')) && $output !== '') {
                file_put_contents($output.($structured ? '-structured' : '-text').($providerFails ? '-failed' : '-completed').($capped ? '-capped' : '-uncapped').($held ? '-held' : '-released').'.json', json_encode($observation, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            }
            self::assertSame($state, $actualState, 'An expired sender must not overwrite a replacement, even after it releases');
            self::assertSame($lock, $actualLock, 'Old release must never delete a replacement UUID');
            self::assertSame([], $finished);
            self::assertSame([], $failed);
            self::assertNotNull($caught);
            self::assertSame('Prism\\Harness\\Exceptions\\SessionLockLost', $caught::class);
            Http::assertSentCount($providerFails ? 0 : 1);
        } finally {
            foreach ([$first, $second] as $fiber) {
                if ($fiber->isSuspended()) {
                    try {
                        $fiber->resume();
                    } catch (Throwable) { /* Only fixture teardown after a recorded assertion failure. */
                    }
                }
            }
        }
    }

    public static function entries(): iterable
    {
        yield 'text' => [false];
        yield 'structured' => [true];
    }

    #[DataProvider('entries')]
    public function test_configuration_failure_keeps_the_existing_one_event_and_zero_state_contract(bool $structured): void
    {
        config()->set('prism-harness.agent.modes', []);
        $session = app(PrismHarness::class)->for(Participant::create(['name' => 'Pre-run config']))->session('invalid-config');
        $failed = [];
        Event::listen(RunFailed::class, static function (RunFailed $event) use (&$failed): void {
            $failed[] = $event;
        });
        $before = DB::table('harness_session_state')->get()->toArray();
        $caught = null;
        try {
            if ($structured) {
                $session->sendStructured('Must fail before beginRun', callBudgetSchema());
            } else {
                $session->send('Must fail before beginRun');
            }
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertNotNull($caught);
        self::assertCount(1, $failed);
        self::assertSame($caught::class, $failed[0]->exception);
        self::assertSame($before, DB::table('harness_session_state')->get()->toArray());
        Http::assertNothingSent();
    }

    #[DataProvider('entries')]
    public function test_owned_provider_failure_keeps_native_identity_and_emits_after_persistence(bool $structured): void
    {
        $session = app(PrismHarness::class)->for(Participant::create(['name' => 'Owned failure']))->session('owned-failure');
        $native = new RuntimeException('synthetic-native-failure');
        $events = [];
        Event::listen(RunFailed::class, static function (RunFailed $event) use ($session, &$events): void {
            self::assertSame('failed', $session->run()['status'], 'Failure event must follow persisted state');
            $events[] = $event;
        });
        Http::fake(static fn () => throw $native);
        $caught = null;
        try {
            if ($structured) {
                $session->sendStructured('Owned failure', callBudgetSchema());
            } else {
                $session->send('Owned failure');
            }
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertSame($native, $caught);
        self::assertCount(1, $events);
        self::assertSame('failed', $session->run()['status']);
        self::assertSame(RuntimeException::class, $events[0]->exception);
    }

    #[DataProvider('entries')]
    public function test_custom_sync_only_store_is_refused_without_runtime_or_index_mutation(bool $indexed): void
    {
        $inner = new DatabaseSessionStore(DB::connection());
        $session = new Session(Participant::create(['name' => 'Unsupported custom']), 'custom-fence',
            new InterleavingStore($inner), $inner,
            runtime: app(AgentRuntime::class), agent: $indexed ? 'custom-agent' : null);
        $before = DB::table('harness_session_state')->get()->toArray();
        $resources = DB::table('harness_address_resources')->get()->toArray();
        $events = [];
        Event::listen(RunStarted::class, static function ($event) use (&$events): void {
            $events[] = $event;
        });
        Event::listen(RunFailed::class, static function ($event) use (&$events): void {
            $events[] = $event;
        });
        $caught = null;
        try {
            $session->send('Must refuse unsafe persistence');
        } catch (Throwable $failure) {
            $caught = $failure;
        }
        self::assertInstanceOf(LogicException::class, $caught);
        self::assertStringContainsString('FencedSessionStore', $caught->getMessage());
        self::assertSame([], $events);
        self::assertEquals($before, DB::table('harness_session_state')->get()->toArray());
        self::assertEquals($resources, DB::table('harness_address_resources')->get()->toArray());
        self::assertSame(0, DB::table('harness_threads')->count());
        Http::assertNothingSent();
        self::assertSame('ordinary sync', $session->lock(static fn (): string => 'ordinary sync', waitSeconds: 0));
    }
}
