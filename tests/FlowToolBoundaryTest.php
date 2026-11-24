<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\Nodes\Ai\AgentExecutor;
use FancyFlow\Nodes\Support\ExecutorDeps;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunResult;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Events\ToolDenied;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\ToolNotAvailable;
use Prism\Harness\Exceptions\UnboundFlowTools;
use Prism\Harness\Flow\HarnessAgentExecutor;
use Prism\Harness\Flow\HarnessToolInvoker;
use Prism\Harness\Flow\PrismLlmClient;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolPolicy;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

/*
 * Workflow tools through the session's tool boundary, driven by the REAL
 * fancy-flow FlowRunner, the real adapters and Prism's real OpenAI handler over
 * a faked transport.
 *
 * WHAT A RUN WITHOUT THE ENGINE DOES NOT PROVE: the tests marked `engine` skip
 * by name unless tools/engine-fixture/fancy-flow is installed, which needs PHP
 * 8.4. The "Flow engine" CI job installs it and sets HARNESS_REQUIRE_FLOW_ENGINE,
 * so there a missing engine FAILS. The PHP 8.2 and 8.3 jobs prove the adapters
 * only, and no job proves a fancy-flow release other than the pinned one.
 */

const FLOW_ENGINE_SKIP = 'Needs the real fancy-flow engine: install tools/engine-fixture/fancy-flow (PHP 8.4).';

const FLOW_REFUSAL_EVENT = 'Prism\Harness\Events\ToolDenied';

function flowEngineMissing(): bool
{
    return ! class_exists(FlowRunner::class);
}

function flowReply(array $output): array
{
    return [
        'id' => 'resp_flow', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
        'output' => $output,
        'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
    ];
}

function flowCall(string $name, string $arguments = '{"target":"prod"}', int $call = 1): array
{
    return flowReply([['id' => "fc_{$call}", 'type' => 'function_call', 'status' => 'completed', 'arguments' => $arguments, 'call_id' => "call_{$call}", 'name' => $name]]);
}

function flowText(): array
{
    return flowReply([['id' => 'msg_1', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'done', 'annotations' => []]]]]);
}

function flowSession(string $key = 'flow'): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session($key);
}

/** @return array{0: PrismLlmClient, 1: HarnessToolInvoker} */
function flowAdapters(Session $session, ?array $names = null): array
{
    $toolset = $session->flowTools($names);

    return [$toolset->llmClient('openai', 'gpt-4o'), $toolset->toolInvoker()];
}

function runFlowShape(string $shape, string $tool, Session $session, ?array $nodeTools = null): RunResult
{
    [$llm, $invoker] = flowAdapters($session);
    $deps = new ExecutorDeps(llm: $llm, tools: $invoker);
    $registry = Builtin::executors($deps);
    $registry->bind('agent', new AgentExecutor($deps->llm, $deps->tools));
    $nodeTools ??= [$tool];

    $llmNode = new FlowNode(id: 'l', type: 'llm_call', config: ['prompt' => 'go', 'provider' => 'openai', 'model' => 'gpt-4o', 'tools' => $nodeTools]);

    $graph = match ($shape) {
        'agent' => new FlowGraph([new FlowNode(id: 'a', type: 'agent', config: ['prompt' => 'go', 'model' => 'gpt-4o', 'tools' => $nodeTools, 'max_steps' => 3])], []),
        'llm_call then tool_use' => new FlowGraph([
            $llmNode,
            new FlowNode(id: 't', type: 'tool_use', config: ['tool' => $tool, 'args' => '{{ $json.tool_calls.0.arguments }}']),
        ], [new FlowEdge('e', 'l', 't')]),
        'llm_call alone' => new FlowGraph([$llmNode], []),
        'tool_use alone' => new FlowGraph([new FlowNode(id: 't', type: 'tool_use', config: ['tool' => $tool, 'args' => ['target' => 'prod']])], []),
    };

    return (new FlowRunner)->run($graph, $registry);
}

function recordedApprovals(Session $session): int
{
    $count = 0;
    foreach ($session->thread()->storedMessages()->get() as $message) {
        $count += count($message->payload['tool_approval_responses'] ?? []);
    }

    return $count;
}

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'not-a-key');
    config()->set('prism.providers.openai.url', 'https://flow-boundary.invalid/v1');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.default', 'flow');
    config()->set('prism-harness.agent.modes', [
        'flow' => [
            'tools' => ['plain', 'gated_bool', 'gated_closure', 'mode_gated', 'optional_args', 'offer_refused', 'call_refused', 'throwing_gate'],
            'requires_approval' => ['mode_gated'],
            'max_steps' => 3,
        ],
        'narrow' => ['tools' => ['gated_bool'], 'max_steps' => 3],
    ]);

    $this->ran = [];
    $this->replies = [];
    $this->hosts = [];
    $ran = &$this->ran;
    $replies = &$this->replies;
    $hosts = &$this->hosts;

    // Before the first request can exist.
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$replies, &$hosts) {
        $hosts[] = (string) parse_url($request->url(), PHP_URL_HOST);

        return Http::response(array_shift($replies) ?? throw new RuntimeException('No scripted reply left.'), 200);
    });

    $tool = function (string $name) use (&$ran): Tool {
        $ran[$name] = 0;

        return (new Tool)->as($name)->for('Probe.')->withStringParameter('target', 'Target')
            ->using(function (string $target = 'default') use (&$ran, $name): string {
                $ran[$name]++;

                return 'ran';
            });
    };

    app(ToolRegistry::class)->registerMany([
        $tool('plain'),
        $tool('gated_bool')->requiresApproval(true),
        $tool('gated_closure')->requiresApproval(fn (array $arguments): bool => ($arguments['target'] ?? null) === 'prod'),
        $tool('mode_gated'),
        $tool('optional_args')->requiresApproval(fn (array $arguments): bool => ($arguments['target'] ?? 'prod') === 'prod'),
        $tool('offer_refused'),
        $tool('call_refused'),
        $tool('throwing_gate')->requiresApproval(function (array $arguments): bool {
            throw new RuntimeException('approval store offline');
        }),
        $tool('outside_mode'),
    ]);
});

afterEach(function (): void {
    // Every request this file makes reached the fake and nothing else.
    expect(array_unique($this->hosts ?: ['flow-boundary.invalid']))->toBe(['flow-boundary.invalid']);
});

it('has the real engine wherever CI says it must', function (): void {
    expect(getenv('HARNESS_REQUIRE_FLOW_ENGINE') === '1' && flowEngineMissing())->toBeFalse();
});

it('holds a mode-gated tool through the session agent executor', function (): void {
    $this->replies = [flowCall('mode_gated'), flowText()];
    $session = flowSession();

    $registry = Builtin::executors(new ExecutorDeps);
    $registry->bind('agent', new HarnessAgentExecutor(fn (string $scope): Session => $session));

    $result = (new FlowRunner)->run(new FlowGraph([
        new FlowNode(id: 'a', type: 'agent', config: ['prompt' => 'go', 'harness_scope' => 'flow', 'tools' => ['mode_gated']]),
    ], []), $registry);

    expect($result->ok)->toBeTrue()
        ->and($this->ran['mode_gated'])->toBe(0)
        ->and($this->hosts)->toHaveCount(1);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('never runs a tool that needs approval', function (string $tool, string $shape): void {
    $this->replies = [flowCall($tool), flowText()];
    $session = flowSession();

    $result = runFlowShape($shape, $tool, $session);

    expect($this->ran[$tool])->toBe(0)
        ->and(recordedApprovals($session))->toBe(0)
        ->and($this->hosts)->not->toBeEmpty();

    if ($shape !== 'llm_call alone') {
        expect($result->ok)->toBeFalse()
            ->and((string) $result->error)->toContain('needs approval');
    }
})->with(['gated_bool', 'gated_closure', 'mode_gated'])
    ->with(['agent', 'llm_call then tool_use', 'llm_call alone'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('runs an ungated tool at most once', function (string $shape, int $expected): void {
    $this->replies = [flowCall('plain'), flowText()];

    $result = runFlowShape($shape, 'plain', flowSession());

    expect($result->ok)->toBeTrue()
        ->and($this->ran['plain'])->toBe($expected);
})->with([
    ['agent', 1],
    ['llm_call then tool_use', 1],
    ['llm_call alone', 0],
])->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('does not run a tool the node did not offer', function (): void {
    $this->replies = [flowCall('offer_refused'), flowText()];

    $result = runFlowShape('agent', 'offer_refused', flowSession(), ['plain']);

    expect($result->ok)->toBeFalse()
        ->and($this->ran['offer_refused'])->toBe(0);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('does not run a tool outside the mode', function (string $shape): void {
    $this->replies = [flowCall('outside_mode'), flowText()];

    $result = runFlowShape($shape, 'outside_mode', flowSession());

    expect($result->ok)->toBeFalse()
        ->and($this->ran['outside_mode'])->toBe(0);
})->with(['agent', 'tool_use alone'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('refuses a gated tool named directly by a tool_use node', function (string $tool): void {
    $result = runFlowShape('tool_use alone', $tool, flowSession());

    expect($result->ok)->toBeFalse()
        ->and($this->ran[$tool])->toBe(0)
        ->and($this->hosts)->toBeEmpty();
})->with(['gated_bool', 'gated_closure', 'mode_gated'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('does not run a call whose arguments cannot be read', function (string $shape): void {
    $this->replies = [flowCall('optional_args', '{not json'), flowText()];

    runFlowShape($shape, 'optional_args', flowSession());

    expect($this->ran['optional_args'])->toBe(0);
})->with(['agent', 'llm_call then tool_use'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('keeps a tool the authorizer did not offer out of the workflow', function (string $shape): void {
    config()->set('prism-harness.agent.authorize_tools', true);
    app()->forgetInstance(ToolAuthorizer::class);
    Gate::define(ToolAuthorizer::ABILITY, fn ($user, Session $session, Tool $tool): bool => $tool->name() !== 'offer_refused');
    $this->replies = [flowCall('offer_refused'), flowText()];

    $result = runFlowShape($shape, 'offer_refused', flowSession());

    expect($result->ok)->toBeFalse()
        ->and($this->ran['offer_refused'])->toBe(0);
})->with(['agent', 'tool_use alone'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('honours a per-call refusal from the authorizer', function (string $shape): void {
    config()->set('prism-harness.agent.authorize_tools', true);
    app()->forgetInstance(ToolAuthorizer::class);
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY, fn ($user, Session $session, Tool $tool): bool => $tool->name() !== 'call_refused');
    $this->replies = [flowCall('call_refused'), flowText()];

    runFlowShape($shape, 'call_refused', flowSession());

    expect($this->ran['call_refused'])->toBe(0);
})->with(['agent', 'llm_call then tool_use', 'tool_use alone'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('refuses raw tools at both adapter constructors, naming the factory', function (): void {
    $raw = ['plain' => (new Tool)->as('plain')->for('x')->using(fn (): string => 'ran')];

    expect(fn () => new HarnessToolInvoker($raw))->toThrow(UnboundFlowTools::class, 'flowTools()')
        ->and(fn () => new PrismLlmClient('openai', 'gpt-4o', null, $raw))->toThrow(UnboundFlowTools::class, 'flowTools()');
});

it('keeps the adapters constructible with no tools at all', function (): void {
    expect(fn (): mixed => (new HarnessToolInvoker)->invoke('anything'))->toThrow(ToolNotAvailable::class)
        ->and(new PrismLlmClient('openai', 'gpt-4o'))->toBeInstanceOf(PrismLlmClient::class);
});

it('refuses a toolset paired with another session', function (): void {
    $toolset = flowSession('one')->flowTools();

    expect(fn () => new PrismLlmClient('openai', 'gpt-4o', flowSession('two'), $toolset))
        ->toThrow(UnboundFlowTools::class);
});

it('cannot be copied or restored into a second toolset', function (): void {
    $toolset = flowSession()->flowTools();

    expect(function () use ($toolset): void {
        clone $toolset;
    })->toThrow(Error::class)
        ->and(fn () => serialize($toolset))->toThrow(LogicException::class);
});

it('re-reads the session on every call, so a narrowed mode takes effect', function (): void {
    $session = flowSession();
    $invoker = $session->flowTools()->toolInvoker();

    expect($invoker->invoke('plain', ['target' => 'x']))->toBe('ran');

    $session->usingMode('narrow');

    expect(fn () => $invoker->invoke('plain', ['target' => 'x']))->toThrow(ToolNotAvailable::class)
        ->and($this->ran['plain'])->toBe(1);
});

it('never widens past the names the toolset was built with', function (): void {
    $invoker = flowSession()->flowTools(['plain'])->toolInvoker();

    expect(fn () => $invoker->invoke('offer_refused', ['target' => 'x']))->toThrow(ToolNotAvailable::class)
        ->and($this->ran['offer_refused'])->toBe(0);
});

it('fails closed when an approval check throws', function (): void {
    $invoker = flowSession()->flowTools()->toolInvoker();

    expect(fn () => $invoker->invoke('throwing_gate', ['target' => 'x']))->toThrow(ToolNotAvailable::class)
        ->and($this->ran['throwing_gate'])->toBe(0);
});

it('matches tool names exactly', function (string $name): void {
    $invoker = flowSession()->flowTools()->toolInvoker();

    expect(fn () => $invoker->invoke($name, ['target' => 'x']))->toThrow(ToolNotAvailable::class)
        ->and($this->ran['plain'])->toBe(0);
})->with([
    'trailing space' => 'plain ',
    'leading space' => ' plain',
    'no-break space' => "plain\u{00A0}",
    'zero-width space' => "plain\u{200B}",
    'cyrillic homoglyph' => "\u{0440}lain",
    'upper case' => 'PLAIN',
]);

it('applies a named agent\'s own mode, approval marks and authorization', function (): void {
    config()->set('prism-harness.agents.support', ['authorize_tools' => true, 'tool_ability' => 'support.offer', 'tool_call_ability' => 'support.call']);
    Gate::define('support.offer', fn ($user, Session $session, Tool $tool): bool => $tool->name() !== 'hidden');
    Gate::define('support.call', fn ($user, Session $session, Tool $tool): bool => $tool->name() !== 'refused');

    $runs = ['open' => 0, 'held' => 0, 'hidden' => 0, 'refused' => 0];
    foreach (array_keys($runs) as $name) {
        app(ToolRegistry::class)->forAgent('support')->register(
            (new Tool)->as($name)->for('Probe.')->withStringParameter('target', 'Target')
                ->using(function (string $target) use (&$runs, $name): string {
                    $runs[$name]++;

                    return 'ran';
                }),
            'support',
        );
    }
    $modes = app(ModeRegistry::class)->forAgent('support');
    $modes->register('child', ['tools' => []], 'support');
    $modes->register('reply', [
        'tools' => ['open', 'held', 'hidden', 'refused', 'worker'],
        'requires_approval' => ['held'],
        'subagents' => ['worker' => ['description' => 'Worker', 'mode' => 'child', 'max_steps' => 1]],
    ], 'support')->setDefault('reply');

    $session = app(PrismHarness::class)->forAgent('support')
        ->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('named');
    $toolset = $session->flowTools();
    $invoker = $toolset->toolInvoker();

    expect($toolset->names())->toBe(['open', 'held', 'refused'])
        ->and($invoker->invoke('open', ['target' => 'x']))->toBe('ran')
        ->and(fn () => $invoker->invoke('held', ['target' => 'x']))->toThrow(ToolNotAvailable::class, 'needs approval')
        ->and(fn () => $invoker->invoke('hidden', ['target' => 'x']))->toThrow(ToolNotAvailable::class)
        ->and(fn () => $invoker->invoke('worker', ['target' => 'x']))->toThrow(ToolNotAvailable::class);

    expect(fn () => $invoker->invoke('refused', ['target' => 'x']))->toThrow(ToolNotAvailable::class, 'call policy');

    expect($runs)->toBe(['open' => 1, 'held' => 0, 'hidden' => 0, 'refused' => 0]);
});

it('runs a mode-gated tool once when the tool policy allows it', function (string $shape): void {
    app(AgentRuntime::class)->setToolPolicy(ToolPolicy::fromArray(['revision' => 'r1', 'allow' => [['tool' => 'mode_gated']], 'honor' => true]));
    $this->replies = [flowCall('mode_gated'), flowText()];

    $result = runFlowShape($shape, 'mode_gated', flowSession());

    expect($result->ok)->toBeTrue()
        ->and($this->ran['mode_gated'])->toBe(1);
})->with(['agent', 'llm_call then tool_use'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('refuses a mode-gated tool the tool policy does not list, and records it', function (string $shape): void {
    app(AgentRuntime::class)->setToolPolicy(ToolPolicy::fromArray(['revision' => 'r2', 'allow' => [['tool' => 'plain']], 'honor' => true]));
    $denied = [];
    Event::listen(ToolDenied::class, function (ToolDenied $event) use (&$denied): void {
        $denied[] = $event->tool;
    });
    $this->replies = [flowCall('mode_gated'), flowText()];

    $result = runFlowShape($shape, 'mode_gated', flowSession());

    expect($result->ok)->toBeFalse()
        ->and($this->ran['mode_gated'])->toBe(0)
        ->and($denied)->toBe(['mode_gated']);
})->with(['agent', 'llm_call then tool_use'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('keeps a tool\'s own approval setting when the tool policy lists it', function (): void {
    config()->set('prism-harness.agent.modes.flow.requires_approval', ['mode_gated', 'gated_closure']);
    app(AgentRuntime::class)->setToolPolicy(ToolPolicy::fromArray(['revision' => 'r3', 'allow' => [['tool' => 'gated_closure']], 'honor' => true]));
    $invoker = flowSession()->flowTools()->toolInvoker();

    expect(fn () => $invoker->invoke('gated_closure', ['target' => 'prod']))->toThrow(ToolNotAvailable::class, 'needs approval')
        ->and($this->ran['gated_closure'])->toBe(0);
});

it('applies a tool policy revoked after the toolset was built', function (): void {
    $runtime = app(AgentRuntime::class);
    $runtime->setToolPolicy(ToolPolicy::fromArray(['revision' => 'r4', 'allow' => [['tool' => 'mode_gated']], 'honor' => true]));
    $invoker = flowSession()->flowTools()->toolInvoker();

    expect($invoker->invoke('mode_gated', ['target' => 'x']))->toBe('ran');

    $runtime->setToolPolicy(ToolPolicy::fromArray(['revision' => 'r5', 'allow' => [], 'honor' => true]));

    expect(fn () => $invoker->invoke('mode_gated', ['target' => 'x']))->toThrow(ToolNotAvailable::class, 'needs approval')
        ->and($this->ran['mode_gated'])->toBe(1);
});

it('does not treat a person\'s recorded approval as permission for a workflow node', function (): void {
    // The handler runs inside approve(), the one moment the thread holds an
    // approval with no result yet, and tries the same call through the workflow.
    config()->set('prism-harness.agent.modes.flow.tools', ['approved_call']);
    config()->set('prism-harness.agent.modes.flow.requires_approval', ['approved_call']);
    $session = flowSession();
    $invoker = $session->flowTools()->toolInvoker();
    $runs = 0;
    $outcome = null;
    app(ToolRegistry::class)->register(
        (new Tool)->as('approved_call')->for('Probe.')->withStringParameter('target', 'Target')
            ->using(function (string $target) use (&$runs, &$outcome, $invoker): string {
                $runs++;
                if ($runs === 1) {
                    try {
                        $invoker->invoke('approved_call', ['target' => $target]);
                        $outcome = 'ran';
                    } catch (ToolNotAvailable $refused) {
                        $outcome = $refused->getMessage();
                    }
                }

                return 'ran';
            }),
    );
    $this->replies = [flowCall('approved_call'), flowText()];

    $pending = $session->send('go')->pendingApprovals();
    expect($pending)->toHaveCount(1);

    $session->approve($pending[0]);

    // approve() holds the session lock while the handler runs, so the
    // workflow call is refused before its approval is even read.
    expect($runs)->toBe(1)
        ->and($outcome)->toContain('another turn is using this session');
});

it('does not treat a recorded approval as permission once the session lock is free', function (): void {
    config()->set('prism-harness.agent.modes.flow.tools', ['mode_gated']);
    $session = flowPausedApproved('mode_gated');
    $invoker = $session->flowTools()->toolInvoker();

    expect(fn () => $invoker->invoke('mode_gated', ['target' => 'prod']))->toThrow(ToolNotAvailable::class, 'needs approval')
        ->and($this->ran['mode_gated'])->toBe(0);
});

it('offers the model no subagent through the workflow boundary', function (): void {
    config()->set('prism-harness.agent.modes.flow.subagents', [
        'helper' => ['description' => 'Helps.', 'mode' => 'narrow'],
    ]);

    $names = flowSession()->flowTools()->names();

    expect($names)->toContain('plain')
        ->and($names)->not->toContain('helper');
});

final class FlowInvokableTool extends Tool
{
    public function __construct(string $name, private Closure $hit)
    {
        $this->as($name)->for('Probe.')->withStringParameter('target', 'Target');
    }

    public function __invoke(string $target): string
    {
        ($this->hit)();

        return 'ran';
    }
}

/** A real turn paused on a tool, with the decision recorded and no result yet. */
function flowPausedApproved(string $tool, string $key = 'paused'): Session
{
    config()->set('prism-harness.agent.lock_wait', 0);
    test()->replies = [flowCall($tool)];
    $session = flowSession($key);
    $pending = $session->send('go')->pendingApprovals();
    expect($pending)->toHaveCount(1);

    flowSession($key)->lock(function () use ($session, $pending): void {
        try {
            $session->approve($pending[0]);
        } catch (SessionLocked) {
            // The decision is written before the resume asks for the lock.
        }
    }, 10, 0);

    return $session;
}

function flowStep(array ...$calls): array
{
    return flowReply(array_map(
        fn (array $call): array => ['id' => 'fc_'.$call[1], 'type' => 'function_call', 'status' => 'completed', 'arguments' => $call[2] ?? '{"target":"prod"}', 'call_id' => $call[1], 'name' => $call[0]],
        $calls,
    ));
}

it('leaves a waiting turn to the session', function (string $policy): void {
    $runs = 0;
    app(ToolRegistry::class)->register(new FlowInvokableTool('class_gated', function () use (&$runs): void {
        $runs++;
    }));
    config()->set('prism-harness.agent.modes.flow.tools', ['class_gated']);
    config()->set('prism-harness.agent.modes.flow.requires_approval', ['class_gated']);
    if ($policy === 'refused by the call policy') {
        config()->set('prism-harness.agent.authorize_tools', true);
        app()->forgetInstance(ToolAuthorizer::class);
        Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
        Gate::define(ToolAuthorizer::CALL_ABILITY, fn (): bool => false);
    }
    $session = flowPausedApproved('class_gated');
    $client = $session->flowTools()->llmClient('openai', 'gpt-4o');

    foreach ([1, 2, 3] as $attempt) {
        $this->replies = [flowText()];
        try {
            $client->complete('go', ['tools' => ['class_gated']]);
        } catch (ToolNotAvailable) {
            // Refusing is the expected outcome; running is not.
        }
    }

    expect($runs)->toBe(0);
})->with(['approved', 'refused by the call policy']);

it('refuses a model call while the session is waiting on an approval', function (): void {
    config()->set('prism-harness.agent.modes.flow.tools', ['mode_gated']);
    $session = flowPausedApproved('mode_gated');
    $this->replies = [flowText()];
    $before = count($this->hosts);

    expect(fn () => $session->flowTools()->llmClient('openai', 'gpt-4o')->complete('go', ['tools' => ['mode_gated']]))
        ->toThrow(ToolNotAvailable::class, 'waiting on an approval')
        ->and(count($this->hosts))->toBe($before);
});

it('accepts named arguments only', function (array $arguments): void {
    $invoker = flowSession()->flowTools()->toolInvoker();

    expect(fn () => $invoker->invoke('gated_closure', $arguments))->toThrow(ToolNotAvailable::class, 'named arguments')
        ->and(fn () => $invoker->invoke('plain', $arguments))->toThrow(ToolNotAvailable::class, 'named arguments')
        ->and($this->ran['gated_closure'])->toBe(0)
        ->and($this->ran['plain'])->toBe(0);
})->with([
    'list' => [['prod']],
    'string zero' => [['0' => 'prod']],
    'mixed' => [['target' => 'safe', 1 => 'prod']],
    'negative' => [['-1' => 'prod']],
    'padded digits' => [['01' => 'prod']],
    'signed' => [['+1' => 'prod']],
]);

it('accepts named arguments only across a model step', function (string $arguments): void {
    $this->replies = [flowStep(['plain', 'c1'], ['gated_closure', 'c2', $arguments]), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession(), ['plain', 'gated_closure']);

    expect($result->ok)->toBeFalse()
        ->and($this->ran['plain'])->toBe(0)
        ->and($this->ran['gated_closure'])->toBe(0);
})->with(['{"0":"prod"}', '["prod"]'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('reads a mode changed through another session object', function (): void {
    $key = 'stale-mode';
    $held = flowSession($key);
    $held->mode();
    $invoker = $held->flowTools()->toolInvoker();
    expect($invoker->invoke('plain', ['target' => 'x']))->toBe('ran');

    flowSession($key)->usingMode('narrow');

    expect(fn () => $invoker->invoke('plain', ['target' => 'x']))->toThrow(ToolNotAvailable::class)
        ->and($this->ran['plain'])->toBe(1);
});

it('runs nothing from a step that holds a call needing approval', function (string $gated): void {
    $this->replies = [flowStep(['plain', 'c1'], [$gated, 'c2']), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession(), ['plain', $gated]);

    expect($result->ok)->toBeFalse()
        ->and($this->ran['plain'])->toBe(0)
        ->and($this->ran[$gated])->toBe(0);
})->with(['gated_bool', 'gated_closure', 'mode_gated'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('runs nothing from a step the call policy refuses part of', function (): void {
    config()->set('prism-harness.agent.authorize_tools', true);
    app()->forgetInstance(ToolAuthorizer::class);
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY, fn ($user, Session $session, Tool $tool): bool => $tool->name() !== 'call_refused');
    $this->replies = [flowStep(['plain', 'c1'], ['call_refused', 'c2']), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession(), ['plain', 'call_refused']);

    expect($result->ok)->toBeFalse()
        ->and($this->ran['plain'])->toBe(0)
        ->and($this->ran['call_refused'])->toBe(0);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('runs nothing from a step that repeats a call id', function (): void {
    $this->replies = [flowStep(['plain', 'dup'], ['plain', 'dup']), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession());

    expect($result->ok)->toBeFalse()
        ->and($this->ran['plain'])->toBe(0);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('runs nothing from a step with an unreadable call beside a valid one', function (): void {
    $this->replies = [flowStep(['plain', 'c1'], ['plain', 'c2', '{not json']), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession());

    expect($result->ok)->toBeFalse()
        ->and($this->ran['plain'])->toBe(0);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('fails the node when the call policy refuses, so nothing downstream runs', function (): void {
    config()->set('prism-harness.agent.authorize_tools', true);
    app()->forgetInstance(ToolAuthorizer::class);
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY, fn ($user, Session $session, Tool $tool): bool => $tool->name() !== 'call_refused');
    [$llm, $invoker] = flowAdapters(flowSession());
    $registry = Builtin::executors(new ExecutorDeps(llm: $llm, tools: $invoker));

    $result = (new FlowRunner)->run(new FlowGraph([
        new FlowNode(id: 't', type: 'tool_use', config: ['tool' => 'call_refused', 'args' => ['target' => 'prod']]),
        new FlowNode(id: 'n', type: 'tool_use', config: ['tool' => 'plain', 'args' => ['target' => 'after']]),
    ], [new FlowEdge('e', 't', 'n')]), $registry);

    expect($result->ok)->toBeFalse()
        ->and($this->ran['call_refused'])->toBe(0)
        ->and($this->ran['plain'])->toBe(0);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('offers skill_read only when the narrowing list names it', function (): void {
    config()->set('prism-harness.agent.modes.flow.skills', ['guide']);
    $session = flowSession();

    expect($session->flowTools([])->names())->toBe([])
        ->and($session->flowTools(['plain'])->names())->toBe(['plain'])
        ->and($session->flowTools()->names())->toContain('skill_read')
        ->and($session->flowTools(['skill_read'])->names())->toBe(['skill_read']);
});

it('holds an agent loop to the session step limit', function (): void {
    $session = flowSession();
    $session->usingMaxSteps(1);
    $this->replies = [flowCall('plain'), flowCall('plain'), flowCall('plain'), flowText()];

    $result = runFlowShape('agent', 'plain', $session);

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('step limit')
        ->and($this->ran['plain'])->toBe(1)
        ->and($this->hosts)->toHaveCount(1);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('lets a loop finish inside the session step limit, and starts a new count afterwards', function (): void {
    $session = flowSession();
    $session->usingMaxSteps(2);
    [$llm] = flowAdapters($session);

    foreach ([1, 2] as $loop) {
        $this->replies = [flowCall('plain'), flowText()];
        expect($llm->complete('go', ['tools' => ['plain']]))->toHaveKey('tool_calls');
        expect($llm->complete('Tool results: []', ['tools' => ['plain']]))->not->toHaveKey('tool_calls');
    }
});

/** @return array{0: list<FlowNode>, 1: list<FlowEdge>} */
function sequentialToolNodes(int $pairs): array
{
    $nodes = [];
    $edges = [];

    for ($i = 1; $i <= $pairs; $i++) {
        $nodes[] = new FlowNode(id: "l{$i}", type: 'llm_call', config: ['prompt' => 'go', 'provider' => 'openai', 'model' => 'gpt-4o', 'tools' => ['plain']]);
        $nodes[] = new FlowNode(id: "t{$i}", type: 'tool_use', config: ['tool' => 'plain', 'args' => '{{ $json.tool_calls.0.arguments }}']);
        $edges[] = new FlowEdge("m{$i}", "l{$i}", "t{$i}");

        if ($i > 1) {
            $previous = $i - 1;
            $edges[] = new FlowEdge("n{$i}", "t{$previous}", "l{$i}");
        }
    }

    return [$nodes, $edges];
}

function runToolNodes(Session $session, array $nodes, array $edges): RunResult
{
    [$llm, $invoker] = flowAdapters($session);
    $deps = new ExecutorDeps(llm: $llm, tools: $invoker);
    $registry = Builtin::executors($deps);
    $registry->bind('agent', new AgentExecutor($deps->llm, $deps->tools));

    return (new FlowRunner)->run(new FlowGraph($nodes, $edges), $registry);
}

it('runs separate model and tool nodes past the session step limit', function (): void {
    $session = flowSession();
    $session->usingMaxSteps(2);
    $this->replies = [flowCall('plain', call: 1), flowCall('plain', call: 2), flowCall('plain', call: 3)];
    [$nodes, $edges] = sequentialToolNodes(3);

    $result = runToolNodes($session, $nodes, $edges);

    expect($result->ok)->toBeTrue()
        ->and($this->ran['plain'])->toBe(3)
        ->and($this->hosts)->toHaveCount(3);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('still holds an agent loop to the limit after separate nodes', function (): void {
    $session = flowSession();
    $session->usingMaxSteps(2);
    $this->replies = array_map(fn (int $call): array => flowCall('plain', call: $call), range(1, 5));
    [$nodes, $edges] = sequentialToolNodes(2);
    $nodes[] = new FlowNode(id: 'a', type: 'agent', config: ['prompt' => 'go', 'model' => 'gpt-4o', 'tools' => ['plain'], 'max_steps' => 5]);
    $edges[] = new FlowEdge('to-agent', 't2', 'a');

    $result = runToolNodes($session, $nodes, $edges);

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('step limit')
        ->and($this->ran['plain'])->toBe(4)
        ->and($this->hosts)->toHaveCount(4);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('does not let a separate model call restart an agent loop count', function (): void {
    $session = flowSession();
    $session->usingMaxSteps(2);
    [$llm] = flowAdapters($session);
    $this->replies = [flowCall('plain'), flowCall('plain'), flowCall('plain')];

    $llm->complete('go', ['tools' => ['plain']]);
    $llm->complete('separate', ['provider' => 'openai', 'tools' => ['plain']]);
    $llm->complete('Tool results: []', ['tools' => ['plain']]);

    expect(fn () => $llm->complete('Tool results: []', ['tools' => ['plain']]))
        ->toThrow(ToolNotAvailable::class, 'step limit');
});

it('counts a tool-result continuation as the loop even when it names a provider', function (): void {
    $session = flowSession();
    $session->usingMaxSteps(1);
    [$llm] = flowAdapters($session);
    $this->replies = [flowCall('plain')];

    $llm->complete('go', ['tools' => ['plain']]);

    expect(fn () => $llm->complete('Tool results: []', ['provider' => 'openai', 'tools' => ['plain']]))
        ->toThrow(ToolNotAvailable::class, 'step limit');
});

it('refuses a call id that already ran in an earlier step of the loop', function (): void {
    $this->replies = [flowCall('plain', call: 7), flowCall('plain', call: 7), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession());

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('repeated a call id')
        ->and($this->ran['plain'])->toBe(1)
        ->and($this->hosts)->toHaveCount(2);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('refuses a call id that already ran in an earlier node of the run', function (): void {
    $this->replies = [flowCall('plain', call: 7), flowCall('plain', call: 7)];
    [$nodes, $edges] = sequentialToolNodes(2);

    $result = runToolNodes(flowSession(), $nodes, $edges);

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('repeated a call id')
        ->and($this->ran['plain'])->toBe(1)
        ->and($this->hosts)->toHaveCount(2);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('refuses a repeated result id under a new item id', function (): void {
    $first = flowCall('plain', call: 7);
    $second = flowCall('plain', call: 7);
    $second['output'][0]['id'] = 'fc_other';
    $this->replies = [$first, $second, flowText()];

    $result = runFlowShape('agent', 'plain', flowSession());

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('repeated a call id')
        ->and($this->ran['plain'])->toBe(1);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('runs every step of a loop whose call ids differ', function (): void {
    $this->replies = [flowCall('plain', call: 1), flowCall('plain', call: 2), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession());

    expect($result->ok)->toBeTrue()
        ->and($this->ran['plain'])->toBe(2)
        ->and($this->hosts)->toHaveCount(3);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('lets a separate run use a call id an earlier run used', function (): void {
    $session = flowSession();

    $this->replies = [flowCall('plain', call: 7), flowText()];
    expect(runFlowShape('agent', 'plain', $session)->ok)->toBeTrue();

    $this->replies = [flowCall('plain', call: 7), flowText()];
    expect(runFlowShape('agent', 'plain', $session)->ok)->toBeTrue()
        ->and($this->ran['plain'])->toBe(2);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('does not read a call as repeated when it was never run', function (): void {
    $session = flowSession();
    [$llm, $invoker] = flowAdapters($session);
    $this->replies = [flowCall('plain', call: 7), flowCall('plain', call: 7)];

    $llm->complete('go', ['tools' => ['plain']]);
    $llm->complete('go', ['tools' => ['plain']]);
    $invoker->invoke('plain', ['target' => 'prod']);

    expect($this->ran['plain'])->toBe(1);
});

it('runs repeated calls on a provider that sends no call ids', function (): void {
    config()->set('prism.providers.gemini.api_key', 'not-a-key');
    config()->set('prism.providers.gemini.url', 'https://flow-boundary.invalid/v1');
    $session = flowSession();
    $session->usingProvider('gemini');
    $call = ['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => 'plain', 'args' => ['target' => 'prod']]]]]]], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1], 'modelVersion' => 'm'];
    $text = ['candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => 'done']]]]], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1], 'modelVersion' => 'm'];
    $this->replies = [$call, $call, $text];

    $result = runFlowShape('agent', 'plain', $session);

    expect($result->ok)->toBeTrue()
        ->and($this->ran['plain'])->toBe(2)
        ->and($this->hosts)->toHaveCount(3);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

/** A reply holding these calls, in the shape of a provider that issues no call ids. */
function idlessCalls(string $provider, string $tool, array $argumentSets): array
{
    if ($provider === 'ollama') {
        return [
            'model' => 'm', 'created_at' => '2026-01-01T00:00:00Z', 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 1, 'eval_count' => 1,
            'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => array_map(
                fn (array $arguments): array => ['function' => ['name' => $tool, 'arguments' => $arguments]],
                $argumentSets,
            )],
        ];
    }

    return [
        'modelVersion' => 'm', 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        'candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => array_map(
            fn (array $arguments): array => ['functionCall' => ['name' => $tool, 'args' => $arguments]],
            $argumentSets,
        )]]],
    ];
}

function idlessText(string $provider): array
{
    return $provider === 'ollama'
        ? ['model' => 'm', 'created_at' => '2026-01-01T00:00:00Z', 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 1, 'eval_count' => 1, 'message' => ['role' => 'assistant', 'content' => 'done']]
        : ['modelVersion' => 'm', 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1], 'candidates' => [['finishReason' => 'STOP', 'content' => ['role' => 'model', 'parts' => [['text' => 'done']]]]]];
}

function idlessSession(string $provider): Session
{
    config()->set('prism.providers.gemini.api_key', 'not-a-key');
    config()->set('prism.providers.gemini.url', 'https://flow-boundary.invalid/v1');
    config()->set('prism.providers.ollama.url', 'https://flow-boundary.invalid');
    config()->set('prism.providers.vertex.project_id', 'p');
    config()->set('prism.providers.vertex.access_token', 'not-a-key');

    $session = flowSession();
    $session->usingProvider($provider);

    return $session;
}

/** Vertex builds its own host, which the fake still answers. */
function forgetVertexHosts(object $test, string $provider): void
{
    if ($provider === 'vertex') {
        expect(array_unique($test->hosts))->toBe(['us-central1-aiplatform.googleapis.com']);
        $test->hosts = [];
    }
}

it('runs parallel calls to one tool on a provider without call ids', function (string $provider, array $argumentSets): void {
    $session = idlessSession($provider);
    $this->replies = [idlessCalls($provider, 'plain', $argumentSets), idlessText($provider)];

    $result = runFlowShape('agent', 'plain', $session);
    $requests = count($this->hosts);
    forgetVertexHosts($this, $provider);

    expect($result->ok)->toBeTrue()
        ->and($this->ran['plain'])->toBe(2)
        ->and($requests)->toBe(2);
})->with(['gemini', 'vertex', 'ollama'])->with([
    'different arguments' => [[['target' => 'a'], ['target' => 'b']]],
    'the same arguments' => [[['target' => 'a'], ['target' => 'a']]],
])->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('still decides every parallel call on a provider without call ids', function (string $provider): void {
    $session = idlessSession($provider);
    $this->replies = [idlessCalls($provider, 'gated_closure', [['target' => 'staging'], ['target' => 'prod']]), idlessText($provider)];

    $result = runFlowShape('agent', 'gated_closure', $session);
    forgetVertexHosts($this, $provider);

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('needs approval')
        ->and($this->ran['gated_closure'])->toBe(0);
})->with(['gemini', 'vertex', 'ollama'])->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('does not treat an id equal to the tool name as an id', function (): void {
    $call = fn (string $target): array => ['id' => 'plain', 'type' => 'function_call', 'status' => 'completed', 'arguments' => json_encode(['target' => $target]), 'call_id' => 'plain', 'name' => 'plain'];
    $this->replies = [flowReply([$call('a'), $call('b')]), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession());

    expect($result->ok)->toBeTrue()
        ->and($this->ran['plain'])->toBe(2);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('still refuses a real id repeated inside one step', function (): void {
    $call = fn (string $target): array => ['id' => 'fc_9', 'type' => 'function_call', 'status' => 'completed', 'arguments' => json_encode(['target' => $target]), 'call_id' => 'call_9', 'name' => 'plain'];
    $this->replies = [flowReply([$call('a'), $call('b')]), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession());

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('repeated a call id')
        ->and($this->ran['plain'])->toBe(0);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('still refuses a real result id repeated inside one step under new item ids', function (): void {
    $call = fn (string $item, string $target): array => ['id' => $item, 'type' => 'function_call', 'status' => 'completed', 'arguments' => json_encode(['target' => $target]), 'call_id' => 'call_9', 'name' => 'plain'];
    $this->replies = [flowReply([$call('fc_a', 'a'), $call('fc_b', 'b')]), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession());

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('repeated a call id')
        ->and($this->ran['plain'])->toBe(0);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('fails the node on a sole call with unreadable arguments', function (): void {
    $this->replies = [flowCall('plain', '{not json'), flowText()];

    $result = runFlowShape('agent', 'plain', flowSession());

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('could not be read')
        ->and($this->ran['plain'])->toBe(0);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('exposes no runnable tool through the public toolset', function (): void {
    $toolset = flowSession()->flowTools();

    expect(method_exists($toolset, 'tools'))->toBeFalse()
        ->and(method_exists($toolset, 'offered'))->toBeTrue();

    foreach ($toolset->offered() as $tool) {
        expect($tool::class)->toBe(Tool::class)
            ->and($tool->isClientExecuted())->toBeTrue()
            ->and($tool->hasApprovalConfigured())->toBeFalse()
            ->and($tool->handle(target: 'x'))->not->toBe('ran');
    }

    expect(array_sum($this->ran))->toBe(0);
});

it('keeps each offered tool\'s schema', function (): void {
    app(ToolRegistry::class)->register(
        (new Tool)->as('schema_tool')->for('Described.')
            ->withStringParameter('a', 'First')
            ->withNumberParameter('b', 'Second', required: false)
            ->withProviderOptions(['strict' => true])
            ->using(fn (string $a): string => $a),
    );
    config()->set('prism-harness.agent.modes.flow.tools', ['schema_tool']);
    $toolset = flowSession()->flowTools();

    expect(method_exists($toolset, 'offered'))->toBeTrue();

    $offered = $toolset->offered()[0];

    expect($offered->description())->toBe('Described.')
        ->and(array_keys($offered->parameters()))->toBe(['a', 'b'])
        ->and($offered->requiredParameters())->toBe(['a'])
        ->and($offered->providerOptions('strict'))->toBeTrue();
});

it('records a refused workflow call once, as a refusal and with no arguments', function (): void {
    $seen = [];
    Event::listen(FLOW_REFUSAL_EVENT, function (object $event) use (&$seen): void {
        $seen[] = $event;
    });
    $asked = 0;
    if (class_exists('Prism\Harness\Events\ToolApprovalAsked')) {
        Event::listen('Prism\Harness\Events\ToolApprovalAsked', function () use (&$asked): void {
            $asked++;
        });
    }
    $invoker = flowSession()->flowTools()->toolInvoker();

    expect(fn () => $invoker->invoke('mode_gated', ['target' => 'TOP-SECRET']))->toThrow(ToolNotAvailable::class);

    expect($seen)->toHaveCount(1)
        ->and($seen[0]->tool)->toBe('mode_gated')
        ->and($seen[0]->runId)->toBe('flow')
        ->and(json_encode($seen[0]))->not->toContain('TOP-SECRET')
        ->and($asked)->toBe(0);
});

it('applies a tool policy revoked while the call policy is deciding', function (): void {
    config()->set('prism-harness.agent.authorize_tools', true);
    app()->forgetInstance(ToolAuthorizer::class);
    app()->forgetInstance(AgentRuntime::class);
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY, function (): bool {
        app(AgentRuntime::class)->setToolPolicy(ToolPolicy::fromArray(['revision' => 'r7', 'allow' => [], 'honor' => true]));

        return true;
    });
    app(AgentRuntime::class)->setToolPolicy(ToolPolicy::fromArray(['revision' => 'r6', 'allow' => [['tool' => 'mode_gated']], 'honor' => true]));
    $invoker = flowSession()->flowTools()->toolInvoker();

    expect(fn () => $invoker->invoke('mode_gated', ['target' => 'x']))->toThrow(ToolNotAvailable::class, 'needs approval')
        ->and($this->ran['mode_gated'])->toBe(0);
});

/*
 * The session lock. A workflow tool call holds the same lock an ordinary turn
 * holds, for as long as the tool runs. While another holder has it the call
 * does not run and the node fails; nothing waits past the lock's own timeout.
 */

/** Hold the session's lock from another session object, as a turn in progress does. */
function flowHoldLock(string $key = 'flow'): Closure
{
    return flowSession($key)->holdFencedLock(300, 0);
}

it('does not run a workflow tool while another turn holds the session lock', function (string $shape): void {
    config()->set('prism-harness.agent.lock_wait', 0);
    $this->replies = [flowCall('plain'), flowText()];
    $release = flowHoldLock();

    try {
        $result = runFlowShape($shape, 'plain', flowSession());
    } finally {
        $release();
    }

    expect($result->ok)->toBeFalse()
        ->and($this->ran['plain'])->toBe(0)
        ->and((string) $result->error)->toContain('[plain]')
        ->and((string) $result->error)->toContain('another turn is using this session');
})->with(['tool_use alone', 'llm_call then tool_use', 'agent'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('runs a workflow tool when the session lock is free, and releases it afterwards', function (string $shape): void {
    config()->set('prism-harness.agent.lock_wait', 0);
    $this->replies = [flowCall('plain'), flowText()];
    flowHoldLock()();

    $result = runFlowShape($shape, 'plain', flowSession());

    expect($result->ok)->toBeTrue()
        ->and($this->ran['plain'])->toBe(1);

    $release = flowHoldLock();
    $release();
})->with(['tool_use alone', 'llm_call then tool_use', 'agent'])
    ->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('holds the session lock while the workflow tool runs', function (): void {
    config()->set('prism-harness.agent.lock_wait', 0);
    $seen = [];
    app(ToolRegistry::class)->register((new Tool)->as('lock_probe')->for('Probe.')->withStringParameter('target', 'Target')
        ->using(function (string $target) use (&$seen): string {
            try {
                flowHoldLock()();
                $seen[] = 'free';
            } catch (SessionLocked) {
                $seen[] = 'held';
            }

            return 'ran';
        }));
    config()->set('prism-harness.agent.modes.flow.tools', ['lock_probe']);

    $result = runFlowShape('tool_use alone', 'lock_probe', flowSession());

    expect($result->ok)->toBeTrue()
        ->and($seen)->toBe(['held']);
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('releases the session lock when a workflow tool throws', function (): void {
    config()->set('prism-harness.agent.lock_wait', 0);
    app(ToolRegistry::class)->register((new Tool)->as('throws')->for('Probe.')->withStringParameter('target', 'Target')
        ->using(fn (string $target): string => throw new RuntimeException('tool broke')));
    config()->set('prism-harness.agent.modes.flow.tools', ['throws']);

    $result = runFlowShape('tool_use alone', 'throws', flowSession());

    expect($result->ok)->toBeFalse()
        ->and((string) $result->error)->toContain('tool broke');

    $release = flowHoldLock();
    $release();
})->skip(fn (): bool => flowEngineMissing(), FLOW_ENGINE_SKIP);

it('refuses a direct invoker call while another turn holds the session lock', function (): void {
    config()->set('prism-harness.agent.lock_wait', 0);
    $invoker = flowSession()->flowTools()->toolInvoker();
    $release = flowHoldLock();

    try {
        expect(fn () => $invoker->invoke('plain', ['target' => 'x']))
            ->toThrow(ToolNotAvailable::class, 'another turn is using this session');
    } finally {
        $release();
    }

    expect($this->ran['plain'])->toBe(0)
        ->and($invoker->invoke('plain', ['target' => 'x']))->toBe('ran')
        ->and($this->ran['plain'])->toBe(1);
});

it('leaves ordinary turns as they were: a turn runs its tool, and is refused while the lock is held', function (): void {
    config()->set('prism-harness.agent.lock_wait', 0);
    $this->replies = [flowCall('plain'), flowText()];

    $response = flowSession()->send('go');

    expect($response->text())->toBe('done')
        ->and($this->ran['plain'])->toBe(1);

    $release = flowHoldLock();
    try {
        expect(fn () => flowSession()->send('again'))->toThrow(SessionLocked::class);
    } finally {
        $release();
    }

    expect($this->ran['plain'])->toBe(1);
});
