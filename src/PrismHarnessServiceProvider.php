<?php

declare(strict_types=1);

namespace Prism\Harness;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\ServiceProvider;
use Prism\Harness\Console\HarnessDoctorCommand;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Skills\SkillRegistry;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;

class PrismHarnessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/harness.php', 'harness');

        $this->app->singleton(SessionStoreManager::class, fn ($app): SessionStoreManager => new SessionStoreManager(
            container: $app,
            config: $app['config']->get('harness', []),
        ));

        $this->app->singleton(SkillRegistry::class, fn ($app): SkillRegistry => new SkillRegistry(
            __DIR__.'/../resources/skills',
        ));
        $this->app->singleton(ToolRegistry::class, function ($app): ToolRegistry {
            $tools = new ToolRegistry;
            $tools->register($app->make(SkillRegistry::class)->readerTool());

            return $tools;
        });
        $this->app->singleton(ModeRegistry::class, fn ($app): ModeRegistry => new ModeRegistry(
            $app['config']->get('harness.agent', []),
        ));
        $this->app->singleton(ToolAuthorizer::class, fn ($app): ToolAuthorizer => new ToolAuthorizer(
            $app->make(Gate::class),
            (bool) $app['config']->get('harness.agent.authorize_tools', false),
        ));
        $this->app->singleton(AgentRuntime::class, fn ($app): AgentRuntime => new AgentRuntime(
            $app->make(ModeRegistry::class),
            $app->make(ToolRegistry::class),
            $app->make(ToolAuthorizer::class),
            $app->make(SkillRegistry::class),
            $app['config']->get('harness.agent', []),
            // Deferred: SubagentRunner needs PrismHarness, which needs this
            // runtime. Resolved at call time, by which point both exist.
            fn (): SubagentRunner => $app->make(SubagentRunner::class),
        ));

        $this->app->singleton(SubagentRunner::class, fn ($app): SubagentRunner => new SubagentRunner(
            $app->make(PrismHarness::class),
            $app->make(AgentRuntime::class),
        ));

        // The harness is a singleton; the sessions it hands out are not. Each
        // call rebuilds one from the store, because a session held across
        // requests goes stale the moment another worker touches it.
        $this->app->singleton(PrismHarness::class, fn ($app): PrismHarness => new PrismHarness(
            stores: $app->make(SessionStoreManager::class),
            runtime: $app->make(AgentRuntime::class),
            config: $app['config']->get('harness', []),
        ));
    }

    public function boot(): void
    {
        // Loaded rather than only publishable, so threads work on install with
        // no setup step. Publish them when you need to change the schema.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([HarnessDoctorCommand::class]);

            $this->publishes([
                __DIR__.'/../config/harness.php' => config_path('harness.php'),
            ], 'harness-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'harness-migrations');
        }
    }
}
