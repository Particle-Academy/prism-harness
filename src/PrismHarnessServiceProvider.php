<?php

declare(strict_types=1);

namespace Prism\Harness;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\ServiceProvider;
use Prism\Harness\Console\HarnessDoctorCommand;
use Prism\Harness\Context\DiscardEvicted;
use Prism\Harness\Context\KeepRecentTurns;
use Prism\Harness\Context\NoCompaction;
use Prism\Harness\Context\ToolPairGuard;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\ContextRecall;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Skills\SkillRegistry;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ContextRecallTool;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
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

        // Compaction, and the two halves of it that must be chosen together.
        //
        // Both default to doing nothing, so installing this version changes no
        // behaviour: the harness replays the whole conversation exactly as it
        // always has until an application says otherwise. A package that began
        // silently dropping turns on upgrade would be changing what the model
        // answers with no line in anyone's diff.
        //
        // `singleton` rather than `bind` because a strategy holds config, not
        // per-request state, and rebuilding it for every thread on a long
        // conversation is work for nothing.
        $this->app->singleton(CompactionStrategy::class, function ($app): CompactionStrategy {
            $context = config('prism-harness.context', []);
            $keep = is_array($context) ? ($context['keep_recent'] ?? null) : null;

            // A count turns it on. There is no separate `enabled` flag, because
            // two settings that can disagree are two settings somebody will set
            // inconsistently and then debug.
            return is_numeric($keep) && (int) $keep > 0
                ? new KeepRecentTurns((int) $keep)
                : new NoCompaction;
        });

        $this->app->singleton(EvictionSink::class, fn ($app): EvictionSink => new DiscardEvicted);

        $this->app->singleton(ToolPairGuard::class, fn ($app): ToolPairGuard => new ToolPairGuard);

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
        // Offered ONLY when something can answer it.
        //
        // Registered in boot() rather than register() so an application binding
        // its own ContextRecall in its own provider is seen whichever order the
        // providers happen to load.
        //
        // The guard is not tidiness. A recall tool with nothing behind it
        // answers everything with "nothing found", and an agent reads that as
        // THE DETAIL DOES NOT EXIST rather than as I CANNOT CHECK -- a stronger
        // and more wrong conclusion than not having the tool. An absent
        // capability is honest; one that silently always fails is not.
        if ($this->app->bound(ContextRecall::class)) {
            $this->app->make(ToolRegistry::class)->registerFactory(
                'recall_context',
                fn (Session $session): Tool => (new ContextRecallTool(
                    $this->app->make(ContextRecall::class),
                    (int) (config('prism-harness.context.recall_budget') ?? 1000),
                ))->forSession($session),
            );
        }

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
