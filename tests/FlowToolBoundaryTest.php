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

function flowCall(string $name, string $arguments = '{"target":"prod"}'): array
{
    return flowReply([['id' => 'fc_1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => $arguments, 'call_id' => 'call_1', 'name' => $name]]);
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

    $invoker->invoke('refused', ['target' => 'x']);

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

    expect($runs)->toBe(1)
        ->and($outcome)->toContain('needs approval');
});

it('offers the model no subagent through the workflow boundary', function (): void {
    config()->set('prism-harness.agent.modes.flow.subagents', [
        'helper' => ['description' => 'Helps.', 'mode' => 'narrow'],
    ]);

    $names = flowSession()->flowTools()->names();

    expect($names)->toContain('plain')
        ->and($names)->not->toContain('helper');
});
