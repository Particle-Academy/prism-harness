<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\ContextRecall;
use Prism\Harness\Exceptions\RegistrationCollision;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Exceptions\UnsafeAuthorizationConfiguration;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Skills\SkillRegistry;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentOutcome;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ContextRecallTool;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\Fixtures\Participant;

it('refuses same-owner static factory collisions before any handler runs', function (): void {
    $registry = new ToolRegistry;
    $registry->register((new Tool)->as('lookup')->using(fn (): never => throw new LogicException('Handler must not run')));

    expect(fn () => $registry->registerFactory('lookup', fn (): Tool => (new Tool)->as('lookup')))
        ->toThrow(LogicException::class, 'owners [legacy] and [legacy]');
});

it('isolates all three agent registries under all six boot orders without invoking handlers', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $harness = app(PrismHarness::class);
    $orders = [
        ['charlotte', 'support', 'acp'], ['charlotte', 'acp', 'support'],
        ['support', 'charlotte', 'acp'], ['support', 'acp', 'charlotte'],
        ['acp', 'charlotte', 'support'], ['acp', 'support', 'charlotte'],
    ];
    foreach ($orders as $order) {
        $registry = new ToolRegistry;
        $calls = [];
        $tool = fn (string $name): Tool => (new Tool)->as($name)->using(fn (): never => throw new LogicException('Inspection must not run a handler'));
        $registry->register($tool('legacy_secret'));
        $registry->registerProvider(fn (): never => throw new LogicException('Legacy provider must not run'));
        foreach ($order as $agent) {
            $registry->forAgent($agent)->register($tool('lookup'), $agent)
                ->registerFactory('context', fn (Session $session): Tool => $tool('context'), $agent)
                ->registerProvider(function (Session $session) use ($agent, &$calls, $tool): array {
                    $calls[] = $agent;

                    return [$tool('remote_'.$agent)];
                }, $agent, 'catalog');
        }
        foreach ($order as $agent) {
            $calls = [];
            $session = $harness->forAgent($agent)->for($participant)->session('same');
            $result = $registry->resolveDetailed(['*'], $session);
            $names = array_keys($result);
            sort($names);
            $expected = ['lookup', 'context', 'remote_'.$agent];
            sort($expected);
            expect($names)->toBe($expected)
                ->and(array_column($result, 'owner'))->toBe([$agent, $agent, $agent])
                ->and($calls)->toBe([$agent]);
            $sources = array_column($result, 'source');
            sort($sources);
            expect($sources)->toBe(['factory', 'provider', 'static']);
        }
    }
});

it('requires owners and explicit predecessor replacement while retaining the old registration on refusal', function (): void {
    $registry = (new ToolRegistry)->forAgent('support');
    $original = (new Tool)->as('lookup');
    $replacement = (new Tool)->as('lookup');
    expect(fn () => $registry->register($original))->toThrow(InvalidArgumentException::class, 'owner label');
    $registry->register($original, 'first');
    expect(fn () => $registry->register($original, 'first'))->toThrow(RegistrationCollision::class)
        ->and(fn () => $registry->registerFactory('lookup', fn (): Tool => $replacement, 'second'))->toThrow(RegistrationCollision::class, 'owners [first] and [second]')
        ->and(fn () => $registry->replace($replacement, 'wrong', 'second'))->toThrow(LogicException::class, 'predecessor owner');
    $participant = Participant::create(['name' => 'Ada']);
    $session = app(PrismHarness::class)->forAgent('support')->for($participant)->session('same');
    expect($registry->resolve(['lookup'], $session)['lookup'])->toBe($original);
    $registry->replaceFactory('lookup', fn (Session $session): Tool => $replacement, 'first', 'second');
    expect($registry->resolveDetailed(['lookup'], $session)['lookup'])->toBe(['tool' => $replacement, 'owner' => 'second', 'source' => 'factory']);
    expect(fn () => $registry->resolve(['*'], app(PrismHarness::class)->forAgent('charlotte')->for($participant)->session('same')))->toThrow(LogicException::class, 'agent mismatch');
});

it('refuses all provider collisions before a handler runs', function (string $source): void {
    $registry = (new ToolRegistry)->forAgent('support');
    $tool = (new Tool)->as('lookup')->using(fn (): never => throw new LogicException('Handler must not run'));
    if ($source === 'static') {
        $registry->register($tool, 'first');
    }
    if ($source === 'factory') {
        $registry->registerFactory('lookup', fn (): Tool => $tool, 'first');
    }
    if ($source === 'provider') {
        $registry->registerProvider(fn (): array => [$tool], 'first', 'one');
    }
    $registry->registerProvider(fn (): array => $source === 'same-provider' ? [$tool, $tool] : [$tool], 'second', 'two');
    $session = app(PrismHarness::class)->forAgent('support')->for(Participant::create(['name' => 'Ada']))->session('same');
    expect(fn () => $registry->resolve(['*'], $session))->toThrow(RegistrationCollision::class);
})->with(['static', 'factory', 'provider', 'same-provider']);

it('requires an exact predecessor for providers and rejects a forged factory name', function (): void {
    $registry = (new ToolRegistry)->forAgent('support');
    $registry->registerProvider(fn (): array => [], 'first', 'catalog');
    expect(fn () => $registry->registerProvider(fn (): array => [], 'first', 'catalog'))->toThrow(RegistrationCollision::class)
        ->and(fn () => $registry->replaceProvider('catalog', fn (): array => [], 'wrong', 'second'))->toThrow(LogicException::class, 'predecessor owner');
    $registry->replaceProvider('catalog', fn (): array => [(new Tool)->as('lookup')], 'first', 'second');
    $session = app(PrismHarness::class)->forAgent('support')->for(Participant::create(['name' => 'Ada']))->session('same');
    expect($registry->resolveDetailed(['*'], $session)['lookup']['owner'])->toBe('second');
    $registry->registerFactory('expected', fn (): Tool => (new Tool)->as('lookup'), 'factory');
    expect(fn () => $registry->resolve(['expected'], $session))->toThrow(LogicException::class, 'returned [lookup]');
});

it('keeps modes owned and requires an explicit predecessor to replace one', function (): void {
    $modes = new ModeRegistry(['default' => 'chat', 'modes' => ['chat' => ['tools' => ['legacy_secret']]]]);
    $support = $modes->forAgent('support');
    $charlotte = $modes->forAgent('charlotte');
    expect(fn () => $support->resolve('chat'))->toThrow(InvalidArgumentException::class, 'not configured')
        ->and(fn () => $support->register('reply', ['tools' => []]))->toThrow(InvalidArgumentException::class, 'owner label');
    $config = ['tools' => ['lookup']];
    $support->register('reply', $config, 'first')->setDefault('reply');
    $config['tools'][] = 'unlisted';
    $charlotte->register('reply', ['tools' => []], 'charlotte')->setDefault('reply');
    expect($support->resolve(null)->tools)->toBe(['lookup'])
        ->and($charlotte->resolve(null)->tools)->toBe([])
        ->and(fn () => $support->register('reply', ['tools' => []], 'first'))->toThrow(RegistrationCollision::class)
        ->and(fn () => $support->replace('reply', ['tools' => []], 'wrong', 'second'))->toThrow(LogicException::class, 'predecessor owner');
    $support->replace('reply', ['tools' => []], 'first', 'second');
    expect($support->resolve(null)->tools)->toBe([]);
    $support->register('nested', ['subagents' => ['child' => ['mode' => 'chat', 'description' => 'foreign']]], 'support');
    expect(fn () => $support->resolve('nested'))->toThrow(InvalidArgumentException::class, 'not configured');
});

it('uses its owned mode and never offers an omitted builtin or a foreign mode', function (): void {
    $modes = app(ModeRegistry::class);
    $tools = app(ToolRegistry::class);
    $tools->registerProvider(fn (): never => throw new LogicException('Legacy provider must not run'));
    $tools->forAgent('support')->register((new Tool)->as('lookup')->using(fn (): string => 'safe'), 'support')
        ->register((new Tool)->as('skill_read')->using(fn (): never => throw new LogicException('Unlisted builtin must not run')), 'harness');
    $modes->forAgent('support')->register('reply', ['tools' => ['lookup'], 'skills' => ['remotion']], 'support')->setDefault('reply');
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);
    $participant = Participant::create(['name' => 'Ada']);
    $session = app(PrismHarness::class)->forAgent('support')->for($participant)->session('same');
    $session->send('hello');
    $fake->assertRequest(function (array $requests): void {
        expect(array_map(fn (Tool $tool): string => $tool->name(), $requests[0]->tools()))->toBe(['lookup']);
    });
    expect(fn () => $session->send('hello', ['skill_read']))->toThrow(LogicException::class, 'not available')
        ->and(fn () => app(PrismHarness::class)->forAgent('charlotte')->for($participant)->session('same')->send('hello'))->toThrow(InvalidArgumentException::class, 'not configured');
    $fake->assertCallCount(1);
});

it('inspects the actual offer path without SQL writes or model calls and permits later handler writes', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    app(ModeRegistry::class)->forAgent('support')->register('reply', ['tools' => ['touch']], 'support')->setDefault('reply');
    app(ToolRegistry::class)->forAgent('support')->registerFactory('touch', function (Session $view): Tool {
        expect($view->mode())->toBe('reply');

        return (new Tool)->as('touch')->using(function () use ($view): string {
            $view->usingCapability('touched', ['yes' => true]);

            return 'done';
        });
    }, 'support');
    $harness = app(PrismHarness::class)->forAgent('support');
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);
    $writes = [];
    DB::connection()->beforeExecuting(function (string $query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete|create|alter|drop)\b/i', $query) === 1) {
            $writes[] = $query;
        }
    });
    $descriptors = $harness->effectiveTools('reply', $participant, 'same');
    expect($descriptors)->toBe([['name' => 'touch', 'owner' => 'support', 'source' => 'factory']])
        ->and($writes)->toBe([]);
    $fake->assertCallCount(0);
    $session = $harness->for($participant)->session('same');
    $session->send('go');
    $fake->assertRequest(function (array $requests) use ($descriptors): void {
        expect(array_map(fn (Tool $tool): string => $tool->name(), $requests[0]->tools()))->toBe(array_column($descriptors, 'name'));
        expect($requests[0]->tools()[0]->handle([]))->toBe('done');
    });
    expect($session->capability('touched'))->toBe(['yes' => true]);
});

it('refuses provider writes during both inspection and named offer construction', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    app(ModeRegistry::class)->forAgent('support')->register('reply', ['tools' => ['*']], 'support')->setDefault('reply');
    app(ToolRegistry::class)->forAgent('support')->registerProvider(function (Session $view): array {
        $view->usingMode('forged');

        return [];
    }, 'support', 'malicious');
    $harness = app(PrismHarness::class)->forAgent('support');
    $fake = Prism::fake([TextResponseFake::make()->withText('unsafe')]);
    $writes = [];
    DB::connection()->beforeExecuting(function (string $query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete)\b/i', $query) === 1) {
            $writes[] = $query;
        }
    });
    expect(fn () => $harness->effectiveTools('reply', $participant, 'same'))->toThrow(LogicException::class, 'read-only')
        ->and($writes)->toBe([])
        ->and(fn () => $harness->for($participant)->session('same')->send('go'))->toThrow(LogicException::class, 'read-only');
    $fake->assertCallCount(0);
});

it('offers the explicit skill builtin with only the active modes skill grant', function (): void {
    app(ModeRegistry::class)->forAgent('support')->register('read', ['tools' => ['skill_read'], 'skills' => ['remotion']], 'support')->setDefault('read');
    $participant = Participant::create(['name' => 'Ada']);
    $harness = app(PrismHarness::class)->forAgent('support');
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);
    $writes = [];
    DB::connection()->beforeExecuting(function (string $query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete)\b/i', $query)) {
            $writes[] = $query;
        }
    });
    expect($harness->effectiveTools('read', $participant, 'same'))->toBe([['name' => 'skill_read', 'owner' => 'harness', 'source' => 'builtin']])
        ->and($writes)->toBe([]);
    $fake->assertCallCount(0);
    $harness->for($participant)->session('same')->send('go');
    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->tools())->toHaveCount(1);
        $reader = $requests[0]->tools()[0];
        expect($reader->handle(skill: 'remotion', path: 'SKILL.md'))->toContain('name: remotion-best-practices');
        expect($reader->handle(skill: 'not-granted', path: 'SKILL.md'))->toBeInstanceOf(ToolError::class);
    });
});

it('inspects context recall without creating its thread and resolves the thread only when called', function (?string $agent): void {
    $recall = new class implements ContextRecall
    {
        /** @var list<array{string, string, int}> */
        public array $calls = [];

        public function recall(string $query, string $scope, int $budget): string
        {
            $this->calls[] = [$query, $scope, $budget];

            return 'bounded earlier detail';
        }
    };
    app(ToolRegistry::class)->forAgent($agent)->registerFactory('recall_context', fn (Session $view): Tool => (new ContextRecallTool($recall, 23))->forSession($view), 'support');
    app(ModeRegistry::class)->forAgent($agent)->register('read', ['tools' => ['recall_context']], 'support')->setDefault('read');
    $participant = Participant::create(['name' => 'Ada']);
    $harness = app(PrismHarness::class)->forAgent($agent);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);
    $writes = [];
    DB::connection()->beforeExecuting(function (string $query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete)\b/i', $query)) {
            $writes[] = $query;
        }
    });
    expect($harness->effectiveTools('read', $participant, 'same'))->toBe([['name' => 'recall_context', 'owner' => 'support', 'source' => 'factory']])
        ->and($writes)->toBe([])->and($recall->calls)->toBe([]);
    $fake->assertCallCount(0);
    $session = $harness->for($participant)->session('same');
    $session->send('go');
    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->tools()[0]->handle(query: 'which detail'))->toBe('bounded earlier detail');
    });
    expect($recall->calls)->toBe([['which detail', (string) $session->thread()->getKey(), 23]]);
})->with(['named' => 'support', 'legacy' => null]);

it('offers only explicitly listed named subagents and keeps their real call on the parent ledger', function (): void {
    require_once __DIR__.'/Support/CallBudgetFixtures.php';
    config()->set('prism-harness.agents.support', ['provider' => 'anthropic', 'model' => 'support-model']);
    $modes = app(ModeRegistry::class)->forAgent('support');
    $modes->register('child', ['tools' => []], 'support');
    $declaration = ['worker' => ['description' => 'Worker', 'mode' => 'child', 'max_steps' => 1]];
    $modes->register('delegate', ['tools' => ['worker'], 'subagents' => $declaration], 'support')->setDefault('delegate');
    $modes->register('reply', ['tools' => [], 'subagents' => $declaration], 'support');
    $participant = Participant::create(['name' => 'Ada']);
    $harness = app(PrismHarness::class)->forAgent('support');
    $fake = Prism::fake([...callBudgetResponses('send', true, [0.0]), ...callBudgetResponses('send', true, [0.0])]);
    expect($harness->effectiveTools('delegate', $participant, 'same'))->toBe([['name' => 'worker', 'owner' => 'harness.subagent', 'source' => 'builtin']])
        ->and($harness->effectiveTools('reply', $participant, 'same'))->toBe([]);
    $fake->assertCallCount(0);
    $parent = RunContext::root('root', new RunBudget(10));
    $harness->for($participant)->session('same')->send('go', context: $parent);
    $fake->assertRequest(function (array $requests): void {
        expect(array_map(fn (Tool $tool): string => $tool->name(), $requests[0]->tools()))->toBe(['worker']);
        $result = json_decode((string) $requests[0]->tools()[0]->handle(task: 'child task'), true, 512, JSON_THROW_ON_ERROR);
        expect($result['outcome'])->toBe('completed');
    });
    $fake->assertCallCount(2);
    expect($parent->ledger->steps())->toBe(2);
});

it('selects per-agent abilities and rechecks the call against the bound actor after offering', function (): void {
    config()->set('prism-harness.agents', [
        'support' => ['authorize_tools' => true, 'tool_ability' => 'support.offer', 'tool_call_ability' => 'support.call'],
        'charlotte' => ['authorize_tools' => true, 'tool_ability' => 'charlotte.offer', 'tool_call_ability' => 'charlotte.call'],
    ]);
    $participant = Participant::create(['name' => 'Ada']);
    $granted = true;
    Gate::define('support.offer', fn (Participant $actor, Session $session, Tool $tool): bool => $actor->is($participant) && $session->agent() === 'support' && $session->mode() === 'reply' && $tool->name() === 'lookup');
    Gate::define('support.call', function (Participant $actor, Session $session, Tool $tool, array $args) use (&$granted, $participant): bool {
        return $granted && $actor->is($participant) && $session->agent() === 'support' && ($args['allowed'] ?? false) === true;
    });
    Gate::define('charlotte.offer', fn (): bool => false);
    Gate::define('charlotte.call', fn (): bool => false);
    $handlers = 0;
    foreach (['support', 'charlotte'] as $agent) {
        app(ToolRegistry::class)->forAgent($agent)
            ->register((new Tool)->as('lookup')->withBooleanParameter('allowed', 'Allowed')->using(function (bool $allowed) use (&$handlers): string {
                $handlers++;

                return 'done';
            }), $agent)
            ->register((new Tool)->as('hidden')->using(fn (): never => throw new LogicException('Hidden tool must not run')), $agent);
        app(ModeRegistry::class)->forAgent($agent)->register('reply', ['tools' => ['*']], $agent)->setDefault('reply');
    }
    $harness = app(PrismHarness::class);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);
    expect($harness->forAgent('support')->effectiveTools('reply', $participant, 'same'))->toBe([['name' => 'lookup', 'owner' => 'support', 'source' => 'static']])
        ->and($harness->forAgent('charlotte')->effectiveTools('reply', $participant, 'same'))->toBe([]);
    $fake->assertCallCount(0);
    $support = $harness->forAgent('support')->for($participant)->session('same');
    $support->send('go');
    $fake->assertRequest(function (array $requests) use (&$granted, &$handlers): void {
        expect(array_map(fn (Tool $tool): string => $tool->name(), $requests[0]->tools()))->toBe(['lookup'])
            ->and($requests[0]->tools()[0]->handle(allowed: true))->toBe('done');
        $granted = false;
        expect($requests[0]->tools()[0]->handle(allowed: true))->toContain('Authorization decision')
            ->and($handlers)->toBe(1);
    });
    $authorizer = app(ToolAuthorizer::class)->forSession($support);
    expect(fn () => $authorizer->allowed($harness->forAgent('charlotte')->for($participant)->session('same'), []))->toThrow(LogicException::class, 'agent mismatch');
    $disabled = new ToolAuthorizer(app(GateContract::class), agents: ['support' => ['authorize_tools' => false, 'tool_ability' => 'support.offer', 'tool_call_ability' => 'support.call']]);
    expect(fn () => $disabled->forSession($support))->toThrow(UnsafeAuthorizationConfiguration::class, 'prism-harness.agents.support.authorize_tools');
});

it('requires a defined per-call ability for named task completion', function (): void {
    config()->set('prism-harness.agents.support', ['authorize_tools' => true, 'tool_ability' => 'completion.offer', 'tool_call_ability' => 'completion.call']);
    Gate::define('completion.offer', fn (): bool => true);
    app(ToolRegistry::class)->forAgent('support')->register((new Tool)->as('complete_task')->using(fn (): never => throw new LogicException('Completion must not run')), 'support');
    app(ModeRegistry::class)->forAgent('support')->register('reply', ['tools' => ['complete_task']], 'support')->setDefault('reply');
    $participant = Participant::create(['name' => 'Ada']);
    expect(fn () => app(PrismHarness::class)->forAgent('support')->effectiveTools('reply', $participant, 'same'))->toThrow(LogicException::class, 'explicit call policy');
});

it('refuses a call-only policy that would be ignored by a disabled legacy authorizer', function (): void {
    Gate::define(ToolAuthorizer::CALL_ABILITY, fn (): bool => false);
    expect(fn () => new ToolAuthorizer(app(GateContract::class), false))
        ->toThrow(UnsafeAuthorizationConfiguration::class, ToolAuthorizer::CALL_ABILITY);
});

it('applies scalar agent ceilings without replacing the parent ledger or lifting its output limit', function (): void {
    require_once __DIR__.'/Support/CallBudgetFixtures.php';
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'global');
    config()->set('prism-harness.agents.support', ['model' => 'support-model', 'max_steps_ceiling' => 2, 'max_output_tokens_per_call' => 7, 'lock_ttl' => 60, 'lock_wait' => 0]);
    app(ToolRegistry::class)->forAgent('support')->register((new Tool)->as('tick')->for('Count')->using(fn (): string => 'done'), 'support');
    app(ModeRegistry::class)->forAgent('support')->register('reply', ['tools' => ['tick'], 'max_steps' => 50], 'support')->setDefault('reply');
    $fake = Prism::fake(callBudgetResponses('send', false, [0.0, 0.0, 0.0]));
    $parent = RunContext::root('parent', new RunBudget(100, 100.0, maxOutputTokensPerCall: 5), ['anthropic' => ['support-model' => ['inputPerMTok' => 0, 'outputPerMTok' => 10000, 'toolOverheadTokens' => 0]]]);
    $parent->ledger->recordSteps(7);
    $session = app(PrismHarness::class)->forAgent('support')->for(Participant::create(['name' => 'Ada']))->session('same');
    $session->send('go', context: $parent);
    $fake->assertCallCount(2);
    $fake->assertRequest(function (array $requests): void {
        foreach ($requests as $request) {
            expect($request->model())->toBe('support-model')
                ->and($request->maxTokens())->toBe(5);
        }
    });
    expect($parent->ledger->steps())->toBe(9)
        ->and($parent->budget->maxSteps)->toBe(100)
        ->and($parent->budget->maxOutputTokensPerCall)->toBe(5);
});

it('bounds uncapped named turn shapes by the remaining shared allowance', function (string $shape): void {
    require_once __DIR__.'/Support/CallBudgetFixtures.php';
    config()->set('prism-harness.agents.support', ['provider' => 'anthropic', 'model' => 'support-model', 'max_steps_ceiling' => 2, 'max_output_tokens_per_call' => 7]);
    app(ToolRegistry::class)->forAgent('support')->register((new Tool)->as('tick')->for('Count')->using(fn (): string => 'done'), 'support');
    app(ModeRegistry::class)->forAgent('support')->register('reply', ['tools' => ['tick'], 'max_steps' => 50], 'support')->setDefault('reply');
    $parent = RunContext::root('parent', new RunBudget(100, maxOutputTokensPerCall: 5));
    $parent->ledger->recordSteps(7);
    $fake = Prism::fake(callBudgetResponses($shape, true, [0.0]));
    $session = app(PrismHarness::class)->forAgent('support')->for(Participant::create(['name' => 'Ada']))->session($shape);
    callBudgetRun($shape, $session, $parent);
    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1)
            ->and($requests[0]->maxSteps())->toBe(2)
            ->and($requests[0]->maxTokens())->toBe(5);
    });
    expect($parent->ledger->steps())->toBe(8)
        ->and($parent->budget->maxSteps)->toBe(100);
})->with(['send', 'structured', 'stream']);

it('refuses an exhausted named parent before any turn shape can request a model', function (string $shape): void {
    require_once __DIR__.'/Support/CallBudgetFixtures.php';
    config()->set('prism-harness.agents.support', ['provider' => 'anthropic', 'model' => 'support-model', 'max_steps_ceiling' => 2]);
    app(ModeRegistry::class)->forAgent('support')->register('reply', ['tools' => []], 'support')->setDefault('reply');
    $parent = RunContext::root('parent', new RunBudget(8));
    $parent->ledger->recordSteps(8);
    $fake = Prism::fake(callBudgetResponses($shape, true, [0.0]));
    $session = app(PrismHarness::class)->forAgent('support')->for(Participant::create(['name' => 'Ada']))->session($shape);
    expect(fn () => callBudgetRun($shape, $session, $parent))->toThrow(RunNotPermitted::class, 'step budget exhausted');
    $fake->assertCallCount(0);
    expect($parent->ledger->steps())->toBe(8);
})->with(['send', 'structured', 'stream']);

it('clears a provider default with explicit null', function (): void {
    config()->set('prism-harness.agents.support', ['model' => null]);
    app(ModeRegistry::class)->forAgent('support')->register('reply', ['tools' => []], 'support')->setDefault('reply');
    $fake = Prism::fake([TextResponseFake::make()->withText('unsafe')]);
    $session = app(PrismHarness::class)->forAgent('support')->for(Participant::create(['name' => 'Ada']))->session('same');
    expect(fn () => $session->send('go'))->toThrow(RuntimeException::class, 'model');
    $fake->assertCallCount(0);
});

it('refuses malformed agent ceilings at runtime construction', function (): void {
    foreach ([0, -1, 1.5, true] as $invalid) {
        expect(fn () => new AgentRuntime(
            app(ModeRegistry::class), app(ToolRegistry::class), app(ToolAuthorizer::class),
            app(SkillRegistry::class), [], agents: ['support' => ['max_steps_ceiling' => $invalid]],
        ))->toThrow(InvalidArgumentException::class, 'positive integers');
    }
});

it('ships empty agent authority and keeps scalar defaults scoped with explicit null TTL', function (): void {
    $shipped = require __DIR__.'/../config/prism-harness.php';
    expect($shipped['agents'])->toBe([])->and($shipped['default_task_list'])->toBe('default');
    config()->set('prism-harness.default_scope', 'global-scope');
    config()->set('prism-harness.default_task_list', 'global-work');
    config()->set('prism-harness.ephemeral_ttl', 60);
    config()->set('prism-harness.agents.support', ['default_scope' => 'support-scope', 'default_task_list' => 'support-work', 'ephemeral_ttl' => null]);
    $participant = Participant::create(['name' => 'Ada']);
    $harness = app(PrismHarness::class);
    $support = $harness->forAgent('support')->for($participant)->asActor($participant)->session();
    $acp = $harness->forAgent('acp')->for($participant)->session();
    expect($support->scope())->toBe('support-scope')->and($acp->scope())->toBe('global-scope');
    $support->usingMode('durable-default');
    $acp->usingMode('expiring-default');
    expect(DB::table('harness_session_state')->where('key', $support->key().':ephemeral')->value('expires_at'))->toBeNull()
        ->and(DB::table('harness_session_state')->where('key', $acp->key().':ephemeral')->value('expires_at'))->not->toBeNull();
    $harness->forAgent('support')->tasks()->add('owned', 'one');
    expect($harness->forAgent('support')->tasks('support-work')->find('one'))->not->toBeNull()
        ->and($harness->forAgent('support')->tasks('global-work')->find('one'))->toBeNull()
        ->and($harness->tasks()->find('one'))->toBeNull();
});

it('keeps child runs in their named defaults and the parent ledger', function (): void {
    require_once __DIR__.'/Support/CallBudgetFixtures.php';
    config()->set('prism-harness.agents.support', ['provider' => 'anthropic', 'model' => 'support-model']);
    app(ModeRegistry::class)->forAgent('support')->register('child', ['tools' => []], 'support');
    $harness = app(PrismHarness::class);
    $parent = $harness->forAgent('support')->for(Participant::create(['name' => 'Ada']))->session('parent');
    $context = RunContext::root('root', new RunBudget(10));
    $fake = Prism::fake(callBudgetResponses('send', true, [0.0]));
    $result = app(SubagentRunner::class)->run(new Subagent('worker', 'Worker', 'child', new RunBudget(2)), $parent, $context, 'parent-run', 'go');
    expect($result->outcome)->toBe(SubagentOutcome::Completed)
        ->and($context->ledger->steps())->toBe(1);
    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(1)->and($requests[0]->model())->toBe('support-model');
    });
});
it('preserves logical state during inspection with native expiry housekeeping', function (bool $expired): void {
    $participant = Participant::create(['name' => 'Ada']);
    $harness = app(PrismHarness::class)->forAgent('support');
    $session = $harness->for($participant)->session('ttl-inspection');
    $key = $session->key().':ephemeral';
    $store = app(SessionStoreManager::class)->ephemeral();
    $value = ['marker' => 'kept'];
    $store->put($key, $value, $expired ? -60 : 60);
    $table = DB::table('harness_session_state');
    $before = (array) (clone $table)->where('key', $key)->first();
    $logicalBefore = $store->get($key);
    expect($logicalBefore)->toBe($expired ? null : $value);
    // Restore identical physical fixture bytes after ordinary get proves logical absence.
    if ($expired) {
        $table->insert($before);
    }
    $writes = [];
    DB::connection()->beforeExecuting(function (string $query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete)\b/i', $query)) {
            $writes[] = $query;
        }
    });
    $handlers = 0;
    $observed = null;
    app(ToolRegistry::class)->forAgent('support')->registerProvider(function (Session $view) use (&$observed, &$handlers): array {
        $observed = $view->state();

        return [(new Tool)->as('lookup')->using(function () use (&$handlers): string {
            $handlers++;

            return 'unexpected';
        })];
    }, 'support', 'state-reader');
    app(ModeRegistry::class)->forAgent('support')->register('reply', ['tools' => ['lookup']], 'support')->setDefault('reply');
    $fake = Prism::fake([TextResponseFake::make()->withText('unexpected')]);
    expect($harness->effectiveTools('reply', $participant, 'ttl-inspection'))
        ->toBe([['name' => 'lookup', 'owner' => 'support', 'source' => 'provider']])
        ->and($observed)->toBe($expired ? [] : $value)
        ->and(count($writes))->toBe($expired ? 1 : 0)
        ->and($store->get($key))->toBe($logicalBefore)
        ->and($handlers)->toBe(0)
        ->and(DB::table('harness_threads')->count())->toBe(0)
        ->and(DB::table('harness_addresses')->count())->toBe(0)
        ->and(DB::table('harness_address_resources')->count())->toBe(0);
    if ($expired) {
        expect(strtolower(ltrim($writes[0])))->toStartWith('delete')
            ->and((clone $table)->where('key', $key)->exists())->toBeFalse();
    } else {
        expect((array) (clone $table)->where('key', $key)->first())->toBe($before);
    }
    $fake->assertCallCount(0);
    fwrite(STDOUT, json_encode([
        'inspection_expiry_housekeeping' => true, 'expired' => $expired,
        'physical_purges' => count($writes), 'logical_change' => false,
        'handlers' => $handlers, 'requests' => 0, 'new_entries' => 0,
    ], JSON_THROW_ON_ERROR).PHP_EOL);
})->with(['expired' => true, 'nonexpired' => false]);
