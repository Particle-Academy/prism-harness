<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\PrismHarnessServiceProvider;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismServiceProvider;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;

final class LegacyDefaultParticipant extends Model
{
    protected $table = 'participants';

    protected $guarded = [];
}

/** The SAME test file runs against the untouched Packagist 0.18.1 dist. */
final class LegacyDefaultCompatibilityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PrismServiceProvider::class, PrismHarnessServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'legacy0181_owned');
        $app['config']->set('database.connections.legacy0181_owned', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('prism-harness.stores', ['ephemeral' => 'database', 'durable' => 'database']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', config('database.connections.legacy0181_owned.database'));
        $provider = (new ReflectionClass(PrismHarnessServiceProvider::class))->getFileName();
        self::assertIsString($provider);
        $migrations = dirname($provider, 2).'/database/migrations';
        // Only the migrations shipped in 0.18.1. Never run the new migration.
        foreach ([
            '0001_01_01_000000_create_harness_thread_tables.php',
            '0001_01_01_000001_create_harness_session_state_table.php',
            '0001_01_01_000002_add_harness_subagent_linkage.php',
            '0001_01_01_000004_add_thread_retirement.php',
            '0001_01_01_000005_add_unique_live_thread_key.php',
        ] as $file) {
            (require $migrations.'/'.$file)->up();
        }
        Schema::create('participants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        self::assertFalse(Schema::hasTable('harness_addresses'));
        self::assertFalse(Schema::hasColumn('harness_threads', 'agent_id'));
        Carbon::setTestNow('2026-01-01T00:00:00Z');
        DB::connection()->enableQueryLog();
    }

    protected function tearDown(): void
    {
        try {
            foreach (DB::connection()->getQueryLog() as $query) {
                self::assertDoesNotMatchRegularExpression('/harness_address|agent_id|participant_ref_|address_key/i', $query['query']);
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public static function registrations(): array
    {
        return [
            'static overwrites static' => [['static', 'static'], 'static:1'],
            'factory overwrites factory' => [['factory', 'factory'], 'factory:1'],
            'factory shadows earlier static' => [['static', 'factory'], 'factory:1'],
            'factory shadows later static' => [['factory', 'static'], 'factory:0'],
            'last provider output wins' => [['provider', 'provider'], 'provider:1'],
            'provider shadows static' => [['static', 'provider'], 'provider:1'],
            'provider shadows later static' => [['provider', 'static'], 'provider:0'],
            'factory shadows provider' => [['provider', 'factory'], 'factory:1'],
            'factory shadows later provider' => [['factory', 'provider'], 'factory:0'],
        ];
    }

    #[DataProvider('registrations')]
    public function test_published_duplicate_precedence(array $sources, string $expected): void
    {
        $registry = new ToolRegistry;
        foreach ($sources as $index => $source) {
            $tool = (new Tool)->as('lookup')->using(fn (): string => $source.':'.$index);
            if ($source === 'static') {
                $registry->register($tool);
            }
            if ($source === 'factory') {
                $registry->registerFactory('lookup', fn (): Tool => $tool);
            }
            if ($source === 'provider') {
                $registry->registerProvider(fn (): array => [$tool]);
            }
        }
        $session = app(PrismHarness::class)->for(LegacyDefaultParticipant::create(['name' => 'Ada']))->session('legacy');
        $resolved = $registry->resolve(['*'], $session);
        self::assertSame(['lookup'], array_keys($resolved));
        self::assertSame($expected, $resolved['lookup']->handle());
        $this->record('registry:'.implode('/', $sources), ['names' => array_keys($resolved), 'result' => $resolved['lookup']->handle()]);
    }

    public function test_same_provider_duplicate_outputs_and_unselected_collisions(): void
    {
        $registry = new ToolRegistry;
        $registry->register((new Tool)->as('ignored')->using(fn (): string => 'static'));
        $registry->register((new Tool)->as('selected')->using(fn (): string => 'selected'));
        $registry->registerProvider(fn (): array => [
            (new Tool)->as('ignored')->using(fn (): string => 'first'),
            (new Tool)->as('ignored')->using(fn (): string => 'second'),
        ]);
        $session = app(PrismHarness::class)->for(LegacyDefaultParticipant::create(['name' => 'Ada']))->session('legacy');
        self::assertSame('selected', $registry->resolve(['selected'], $session)['selected']->handle());
        self::assertSame('second', $registry->resolve(['ignored'], $session)['ignored']->handle());
        $this->record('provider-duplicates-unselected', ['selected' => 'selected', 'ignored' => 'second']);
    }

    public function test_legacy_state_tasks_history_and_editable_thread_on_old_schema(): void
    {
        $participant = LegacyDefaultParticipant::create(['name' => 'Ada']);
        $harness = app(PrismHarness::class);
        $session = $harness->for($participant)->session('original');
        $session->usingMode('chat')->usingProvider('anthropic')->usingModel('synthetic-model');
        $session->usingCapability('fixture', ['enabled' => true]);
        $session->tasks()->add('Session task', 'session-task');
        $harness->tasks()->add('Global task', 'global-task');
        Prism::fake([TextResponseFake::make()->withText('done')]);
        $session->send('Synthetic request');
        $thread = $session->thread();
        $id = $thread->getKey();
        $thread->scope = 'renamed';
        $thread->save();
        self::assertSame($id, Thread::forParticipant($participant, 'renamed')->getKey());
        self::assertNull(Thread::query()->where('participant_type', $participant->getMorphClass())
            ->where('participant_id', $participant->getKey())->where('scope', 'original')->whereNull('retired_at')->first());
        self::assertSame(1, Thread::addressedTo($participant, 'renamed')->count());
        $other = LegacyDefaultParticipant::create(['name' => 'Other']);
        $thread->participant_id = $other->getKey();
        $thread->save();
        self::assertSame($id, Thread::forParticipant($other, 'renamed')->getKey());
        $thread->retire();
        self::assertNotSame($id, Thread::forParticipant($other, 'renamed')->getKey());
        $this->record('legacy-old-schema', [
            'session_key' => $session->key(), 'mode' => $session->mode(),
            'capability' => $session->capability('fixture'),
            'threads' => DB::table('harness_threads')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'state_keys' => DB::table('harness_session_state')->orderBy('key')->pluck('key')->all(),
        ]);
    }

    private function record(string $id, array $result): void
    {
        if ($file = getenv('PRISM_LEGACY_DEFAULT_OUTPUT')) {
            file_put_contents($file, json_encode(['id' => $id, 'result' => $result], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
    }
}
