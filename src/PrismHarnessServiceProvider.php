<?php

declare(strict_types=1);

namespace Prism\Harness;

use Illuminate\Support\ServiceProvider;

class PrismHarnessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/harness.php', 'harness');
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
