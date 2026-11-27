<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Harness\PrismHarnessServiceProvider;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /** Where every proxy variable points in a test: a closed local port. */
    public const BLOCKED_PROXY = 'http://127.0.0.1:1';

    /**
     * NO TEST REACHES THE NETWORK. Every request through Laravel's HTTP
     * client must match a fake (the provider handlers all use it), and any
     * client that bypasses it, a raw Guzzle client or curl, is sent to a
     * closed local proxy and fails to connect. A test file that forgets its
     * own guard fails instead of calling a real provider.
     */
    protected function setUp(): void
    {
        self::blockRealTransfers();

        parent::setUp();

        Http::preventStrayRequests();
    }

    /** Point every proxy variable at {@see self::BLOCKED_PROXY}, with no exemptions. */
    public static function blockRealTransfers(): void
    {
        foreach (['HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'all_proxy'] as $name) {
            putenv($name.'='.self::BLOCKED_PROXY);
            $_SERVER[$name] = $_ENV[$name] = self::BLOCKED_PROXY;
        }
        foreach (['NO_PROXY', 'no_proxy'] as $name) {
            putenv($name.'=');
            unset($_SERVER[$name], $_ENV[$name]);
        }
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PrismServiceProvider::class,
            PrismHarnessServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // SQLite ignores foreign keys unless asked. Laravel's own sqlite
            // config turns them on, so leaving them off here would test a
            // database no real app runs — and would quietly pass a schema
            // whose cascade never fires.
            'foreign_key_constraints' => true,
        ]);

        // Both slots on the database driver: there is no Redis in CI, and the
        // database store is the one that must work everywhere anyway. Tests
        // that care about Redis specifically construct that store directly.
        $app['config']->set('prism-harness.stores', [
            'ephemeral' => 'database',
            'durable' => 'database',
        ]);
    }

    /**
     * A stand-in for the host application's User model — the harness must work
     * against whatever the app calls its participant, not a type it ships.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('participants', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        // Two stand-ins for a CONSUMER'S OWN task table. The package ships no
        // task model, no schema and no migration, so these live here rather
        // than in database/migrations — and they are deliberately different
        // shapes: `chores` uses the conventional column names, `errands` uses
        // none of them and relies on the trait's per-method overrides.
        Schema::create('chores', function (Blueprint $table): void {
            $table->id();
            $table->string('instruction');
            $table->string('state')->default('todo');
            $table->string('claimed_by')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->timestamps();
        });

        Schema::create('errands', function (Blueprint $table): void {
            $table->string('ref')->primary();
            $table->string('body');
            $table->string('status')->default('todo');
            $table->string('holder')->nullable();
            // An integer column rather than a timestamp, because both shapes
            // turn up in real applications and the trait has to read both.
            $table->integer('lease_ends_at')->nullable();
            $table->timestamps();
        });
    }
}
