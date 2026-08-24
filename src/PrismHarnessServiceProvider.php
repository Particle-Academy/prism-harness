<?php

declare(strict_types=1);

namespace Prism\Harness;

use Illuminate\Support\ServiceProvider;
use Prism\Harness\Sessions\SessionStoreManager;

class PrismHarnessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/harness.php', 'harness');

        $this->app->singleton(SessionStoreManager::class, fn ($app): SessionStoreManager => new SessionStoreManager(
            container: $app,
            config: $app['config']->get('harness', []),
        ));

        // The harness is a singleton; the sessions it hands out are not. Each
        // call rebuilds one from the store, because a session held across
        // requests goes stale the moment another worker touches it.
        $this->app->singleton(PrismHarness::class, fn ($app): PrismHarness => new PrismHarness(
            stores: $app->make(SessionStoreManager::class),
            config: $app['config']->get('harness', []),
        ));
    }

    public function boot(): void
    {
        // Loaded rather than only publishable, so threads work on install with
        // no setup step. Publish them when you need to change the schema.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/harness.php' => config_path('harness.php'),
            ], 'harness-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'harness-migrations');
        }
    }
}
