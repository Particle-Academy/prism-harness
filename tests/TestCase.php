<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Prism\Harness\PrismHarnessServiceProvider;
use Prism\Prism\PrismServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

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
