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
use RuntimeException;

class PrismHarnessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The config key matches the package name, as every other Prism
        // satellite's does. This CHANGED in the release that added subagents:
        // `config/harness.php` and the `harness.*` key are gone, not aliased.
        // An alias would have let an application keep a stale published config
        // that silently stopped being read — the failure this package refuses
        // everywhere else. Gate ABILITY names are unchanged: `harness.tool` is
        // an identifier in a different namespace, and moving it would break
        // policies already written against it.
        $this->mergeConfigFrom(__DIR__.'/../config/prism-harness.php', 'prism-harness');

        $this->app->singleton(SessionStoreManager::class, fn ($app): SessionStoreManager => new SessionStoreManager(
            container: $app,
            config: $app['config']->get('prism-harness', []),
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
            $app['config']->get('prism-harness.agent', []),
        ));
        $this->app->singleton(ToolAuthorizer::class, fn ($app): ToolAuthorizer => new ToolAuthorizer(
            $app->make(Gate::class),
            (bool) $app['config']->get('prism-harness.agent.authorize_tools', false),
        ));
        $this->app->singleton(AgentRuntime::class, fn ($app): AgentRuntime => new AgentRuntime(
            $app->make(ModeRegistry::class),
            $app->make(ToolRegistry::class),
            $app->make(ToolAuthorizer::class),
            $app->make(SkillRegistry::class),
            $app['config']->get('prism-harness.agent', []),
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
            config: $app['config']->get('prism-harness', []),
        ));
    }

    public function boot(): void
    {
        // A config published under the OLD name is refused rather than ignored.
        //
        // This is the whole reason the rename is safe to make. Without it, an
        // application that had published `config/harness.php` would keep that
        // file, keep editing it, and quietly run on package defaults — every
        // mode, every approval gate and every budget it had configured silently
        // not applied, with nothing anywhere reporting a problem. That is worse
        // than a break, because a break is visible.
        if (file_exists(config_path('harness.php')) && ! file_exists(config_path('prism-harness.php'))) {
            throw new RuntimeException(
                'Found a published [config/harness.php], which this version no longer reads. The config key '
                .'now matches the package name, as every other Prism satellite does. Rename the file to '
                ."[config/prism-harness.php] — its contents are unchanged — or delete it to fall back to the \n"
                .'package defaults. Gate ability names (`harness.tool`, `harness.tool.call`) are NOT affected '
                .'and should stay as they are.'
            );
        }

        // Loaded rather than only publishable, so threads work on install with
        // no setup step. Publish them when you need to change the schema.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([HarnessDoctorCommand::class]);

            $this->publishes([
                __DIR__.'/../config/prism-harness.php' => config_path('prism-harness.php'),
            ], 'prism-harness-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'prism-harness-migrations');
        }
    }
}
