<?php

declare(strict_types=1);

use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Nodes\Ai\AgentExecutor;
use FancyFlow\Nodes\Structural\SubflowExecutor;
use FancyFlow\Nodes\Support\ExecutorDeps;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Runtime\RunResult;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Events\WorkflowBudgetExhausted;
use Prism\Harness\Flow\HarnessAgentExecutor;
use Prism\Harness\Flow\HarnessFlowExecutors;
use Prism\Harness\Flow\HarnessToolInvoker;
use Prism\Harness\Flow\PrismLlmClient;
use Prism\Harness\Flow\WorkflowBudgets;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\CostObserver;
use Tests\Fixtures\Participant;

/*
 * G-348: one budget for a whole workflow run.
 *
 * Every test drives the REAL fancy-flow FlowRunner over Prism's real OpenAI
 * handler and a faked transport, so the counts below are requests that would
 * have left the machine. The engine is only present where its fixture is
 * installed (PHP 8.4); the "Flow engine" CI job requires it.
 *
 * The registry helper binds the Harness's workflow executors when they exist
 * and the plain adapters otherwise. On a build without the budget, the same
 * graph runs through the adapters a host wires today, so each test fails on
 * what the workflow actually spent rather than on a missing class.
 */

const WF_ENGINE_SKIP = 'Needs the real fancy-flow engine: install tools/engine-fixture/fancy-flow (PHP 8.4).';

const WF_EXHAUSTED = 'workflow_budget_exhausted';

function wfEngineMissing(): bool
{
    return ! class_exists(FlowRunner::class);
}

function wfReply(array $output, ?array $usage = null): array
{
    return [
        'id' => 'resp_wf', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
        'output' => $output,
        'usage' => $usage ?? ['input_tokens' => 20, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 4, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 24],
    ];
}

function wfText(?array $usage = null): array
{
    return wfReply([['id' => 'msg_1', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'done', 'annotations' => []]]]], $usage);
}

function wfCall(string $name, int $call): array
{
    return wfReply([['id' => "fc_{$call}", 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"target":"t'.$call.'"}', 'call_id' => "call_{$call}", 'name' => $name]]);
}

function wfSession(string $key = 'wf', ?string $agent = null): Session
{
    return app(PrismHarness::class)->forAgent($agent)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session($key);
}

/**
 * The registry a host builds for one workflow run.
 *
 * @param  array<string, mixed>  $limits  host narrowing for this run
 * @param  array<string, mixed>  $workflow  the graph's own `harness_budget`
 */
function wfRegistry(?Session $session = null, ?Closure $sessions = null, array $limits = [], array $workflow = []): ExecutorRegistry
{
    $tools = $session?->flowTools();
    $llm = $tools?->llmClient('openai', 'gpt-4o') ?? new PrismLlmClient('openai', 'gpt-4o');
    $invoker = $tools?->toolInvoker() ?? new HarnessToolInvoker;
    $registry = Builtin::executors(new ExecutorDeps(llm: $llm, tools: $invoker));
    $registry->bind('agent', $sessions !== null ? new HarnessAgentExecutor($sessions) : new AgentExecutor($llm, $invoker));

    if (class_exists(HarnessFlowExecutors::class)) {
        HarnessFlowExecutors::register($registry, tools: $tools, sessions: $sessions,
            provider: 'openai', model: 'gpt-4o', limits: $limits, workflow: $workflow);
    }

    return $registry;
}

function wfRun(FlowGraph $graph, ExecutorRegistry $registry, ?string $runKey = 'wf-run', ?array &$events = null): RunResult
{
    $events = [];

    return (new FlowRunner)->run($graph, $registry, function (RunEvent $event) use (&$events): void {
        $events[] = $event;
    }, new RunOptions(run: $runKey));
}

/** A chain of `$count` llm_call nodes. */
function wfChain(int $count, array $config = []): FlowGraph
{
    $nodes = [];
    $edges = [];
    for ($i = 1; $i <= $count; $i++) {
        $nodes[] = new FlowNode(id: "l{$i}", type: 'llm_call', config: ['prompt' => 'go', 'provider' => 'openai', 'model' => 'gpt-4o', ...$config]);
        if ($i > 1) {
            $edges[] = new FlowEdge('e'.$i, 'l'.($i - 1), "l{$i}");
        }
    }

    return new FlowGraph($nodes, $edges);
}

/** A for_each over `$count` items whose lane is `$lane`. */
function wfForEach(int $count, FlowNode $lane, ?FlowNode $after = null): FlowGraph
{
    $nodes = [new FlowNode(id: 'each', type: 'for_each', config: ['source' => range(1, $count), 'maxItems' => 10000]), $lane];
    $edges = [new FlowEdge('ei', 'each', $lane->id, 'item')];
    if ($after !== null) {
        $nodes[] = $after;
        $edges[] = new FlowEdge('ed', 'each', $after->id, 'done');
    }

    return new FlowGraph($nodes, $edges);
}

function wfPricing(): void
{
    config()->set('prism-harness.pricing', ['openai' => ['gpt-4o' => ['inputPerMTok' => 100, 'outputPerMTok' => 100]]]);
    config()->set('prism-harness.agent.max_output_tokens_per_call', 4);
    app()->forgetInstance(AgentRuntime::class);
    app()->forgetInstance(PrismHarness::class);
}

function wfFailures(RunResult $result): array
{
    $each = $result->outputs['each'] ?? [];

    return array_column($each['value']['failures'] ?? $each['failures'] ?? [], 'error');
}

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'not-a-key');
    config()->set('prism.providers.openai.url', 'https://workflow-budget.invalid/v1');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.default', 'flow');
    config()->set('prism-harness.agent.modes', [
        'flow' => ['tools' => ['probe', 'boom'], 'max_steps' => 3],
        'deep' => ['tools' => ['probe'], 'max_steps' => 8],
    ]);

    $this->requests = 0;
    $this->hosts = [];
    $this->reply = fn (int $n): array => wfText();
    $this->ran = ['probe' => 0, 'boom' => 0];
    $requests = &$this->requests;
    $hosts = &$this->hosts;
    $ran = &$this->ran;
    $test = $this;

    // Before the first request can exist: anything unfaked fails the test.
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$requests, &$hosts, $test) {
        $hosts[] = (string) parse_url($request->url(), PHP_URL_HOST);
        $requests++;

        return Http::response(($test->reply)($requests), 200);
    });

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('probe')->for('Probe.')->withStringParameter('target', 'Target')
            ->using(function (string $target = 'x') use (&$ran): string {
                $ran['probe']++;

                return 'ran';
            }),
        (new Tool)->as('boom')->for('Throws.')->withStringParameter('target', 'Target')
            ->using(function (string $target = 'x') use (&$ran): string {
                $ran['boom']++;

                throw new RuntimeException('handler failed');
            }),
    ]);
});

afterEach(function (): void {
    expect(array_unique($this->hosts ?: ['workflow-budget.invalid']))->toBe(['workflow-budget.invalid']);
});

it('has the real engine wherever CI says it must', function (): void {
    expect(getenv('HARNESS_REQUIRE_FLOW_ENGINE') === '1' && wfEngineMissing())->toBeFalse();
});

// 1
it('holds a for_each of model calls to the workflow step budget', function (): void {
    config()->set('prism-harness.workflow.max_steps', 10);
    Event::fake([WorkflowBudgetExhausted::class]);

    $result = wfRun(wfForEach(1000, new FlowNode(id: 'l', type: 'llm_call', config: ['prompt' => 'go', 'provider' => 'openai', 'model' => 'gpt-4o'])), wfRegistry());

    $failures = wfFailures($result);
    expect($this->requests)->toBe(10)
        ->and($failures)->toHaveCount(990)
        ->and(array_filter($failures, fn (string $e): bool => ! str_contains($e, WF_EXHAUSTED)))->toBe([]);
    Event::assertDispatchedTimes(WorkflowBudgetExhausted::class, 1);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 2
it('holds a for_each of tool_use nodes to the workflow tool-call budget', function (): void {
    config()->set('prism-harness.workflow.max_tool_calls', 7);

    $result = wfRun(wfForEach(1000, new FlowNode(id: 't', type: 'tool_use', config: ['tool' => 'probe', 'args' => ['target' => 'x']])), wfRegistry(wfSession()));

    expect($this->ran['probe'])->toBe(7)
        ->and($this->requests)->toBe(0)
        ->and(wfFailures($result))->toHaveCount(993)
        ->and(wfFailures($result)[0])->toContain(WF_EXHAUSTED);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 3
it('counts llm_call nodes that offer no tools', function (): void {
    config()->set('prism-harness.workflow.max_steps', 10);

    $result = wfRun(wfChain(50), wfRegistry(wfSession()));

    expect($this->requests)->toBe(10)
        ->and($result->ok)->toBeFalse()
        ->and($result->error)->toContain('l11')->toContain(WF_EXHAUSTED);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 4
it('draws recursive subflows and their for_each lanes from one account', function (): void {
    config()->set('prism-harness.workflow.max_steps', 5);
    $graph = new FlowGraph([
        new FlowNode(id: 'each', type: 'for_each', config: ['source' => [1, 2, 3]]),
        new FlowNode(id: 'l', type: 'llm_call', config: ['prompt' => 'go', 'provider' => 'openai', 'model' => 'gpt-4o']),
        new FlowNode(id: 'again', type: 'subflow', config: ['workflow' => 'self', 'maxDepth' => 8]),
    ], [new FlowEdge('ei', 'each', 'l', 'item'), new FlowEdge('ed', 'each', 'again', 'done')]);
    $resolver = new class($graph) implements WorkflowResolver
    {
        public function __construct(private FlowGraph $graph) {}

        public function resolve(string $ref, ?int $version = null): FlowGraph
        {
            return $this->graph;
        }
    };
    $registry = wfRegistry();
    $registry->bind('subflow', new SubflowExecutor(resolver: $resolver));

    wfRun($graph, $registry);

    expect($this->requests)->toBe(5);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 5
it('never starts a graph with a cycle (the engine refuses it before any node)', function (): void {
    $graph = new FlowGraph([
        new FlowNode(id: 'a', type: 'llm_call', config: ['prompt' => 'go', 'provider' => 'openai', 'model' => 'gpt-4o']),
        new FlowNode(id: 'b', type: 'llm_call', config: ['prompt' => 'go', 'provider' => 'openai', 'model' => 'gpt-4o']),
    ], [new FlowEdge('ab', 'a', 'b'), new FlowEdge('ba', 'b', 'a')]);

    $result = wfRun($graph, wfRegistry());

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('Cycle')
        ->and($this->requests)->toBe(0);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 6
it('charges every step of every session agent node to the one workflow budget', function (): void {
    config()->set('prism-harness.workflow.max_steps', 20);
    // Each node needs four steps: three tool calls, then an answer.
    $this->reply = fn (int $n): array => $n % 4 === 0 ? wfText() : wfCall('probe', $n);
    $session = wfSession('deep');
    $session->usingMode('deep');
    $nodes = [];
    $edges = [];
    for ($i = 1; $i <= 12; $i++) {
        $nodes[] = new FlowNode(id: "a{$i}", type: 'agent', config: ['prompt' => 'go', 'harness_scope' => 'deep', 'tools' => ['probe']]);
        if ($i > 1) {
            $edges[] = new FlowEdge("e{$i}", 'a'.($i - 1), "a{$i}");
        }
    }

    $result = wfRun(new FlowGraph($nodes, $edges), wfRegistry(sessions: fn (string $scope): Session => $session));

    expect($this->requests)->toBe(20)
        ->and($this->ran['probe'])->toBe(15)
        ->and(array_keys($result->outputs))->toBe(['a1', 'a2', 'a3', 'a4', 'a5'])
        ->and($result->error)->toContain('a6')->toContain(WF_EXHAUSTED);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 7
it('stops a built-in agent loop at the workflow total or the session limit, whichever is first', function (int $workflowSteps, int $expected): void {
    config()->set('prism-harness.workflow.max_steps', $workflowSteps);
    $this->reply = fn (int $n): array => wfCall('probe', $n);
    $graph = new FlowGraph([new FlowNode(id: 'a', type: 'agent', config: ['prompt' => 'go', 'model' => 'gpt-4o', 'tools' => ['probe'], 'max_steps' => 1000])], []);

    $result = wfRun($graph, wfRegistry(wfSession()));

    expect($result->ok)->toBeFalse()
        ->and($this->requests)->toBe($expected);
})->with([
    'the workflow is smaller' => [2, 2],
    'the session limit is smaller' => [10, 3],
])->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 8
it('does not refill when a run is retried or resumed in a fresh worker', function (): void {
    config()->set('prism-harness.workflow.max_steps', 4);

    wfRun(wfChain(3), wfRegistry(), 'retried');
    $first = $this->requests;

    // A new worker: nothing in memory survives.
    app()->forgetInstance(WorkflowBudgets::class);
    $retry = wfRun(wfChain(3), wfRegistry(), 'retried');
    $second = $this->requests - $first;

    app()->forgetInstance(WorkflowBudgets::class);
    wfRun(wfChain(3), wfRegistry(), 'a-different-run');
    $third = $this->requests - $first - $second;

    expect([$first, $second, $third])->toBe([3, 1, 3])
        ->and($retry->error)->toContain(WF_EXHAUSTED);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 9
it('lets exactly one of two workers take the last step', function (): void {
    config()->set('prism-harness.workflow.max_steps', 1);
    $graph = wfChain(1);
    $second = null;
    $test = $this;
    // While worker A's request is in flight, worker B tries the same run.
    $this->reply = function (int $n) use (&$second, $graph): array {
        if ($n === 1) {
            app()->forgetInstance(WorkflowBudgets::class);
            $second = wfRun($graph, wfRegistry(), 'raced');
        }

        return wfText();
    };

    $first = wfRun($graph, wfRegistry(), 'raced');

    expect($this->requests)->toBe(1)
        ->and($first->ok)->toBeTrue()
        ->and($second?->ok)->toBeFalse()
        ->and($second?->error)->toContain(WF_EXHAUSTED);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 10
it('refuses the priced call whose worst case does not fit, before its request', function (): void {
    wfPricing();
    config()->set('prism-harness.workflow.max_cost_usd', 0.030);
    $observer = new CostObserver;
    app()->instance(CostObserver::class, $observer);
    config()->set('prism-harness.cost_observer', CostObserver::class);

    $result = wfRun(wfChain(20), wfRegistry(), 'priced');

    // Each call's worst case is 0.0244 USD and it settles at 0.0024. The
    // fourth would put 0.0072 spent + 0.0244 held over 0.030, so it is
    // refused before its request, though the spend so far is far below it.
    expect($this->requests)->toBe(3)
        ->and($observer->reservations[0]->worstCaseUsd)->toEqualWithDelta(0.0244, 1e-9)
        ->and($result->error)->toContain('l4')->toContain(WF_EXHAUSTED)->toContain('cost')
        ->and(count($observer->settlements))->toBe($this->requests)
        ->and(array_sum(array_map(fn ($s): float => $s->chargedUsd, $observer->settlements)))->toBeLessThanOrEqual(0.030)
        ->and(app(WorkflowBudgets::class)->open('priced')->context()->ledger->costUsd())->toBeLessThanOrEqual(0.030);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 11
it('refuses an unpriced model under a cost cap before any request', function (): void {
    config()->set('prism-harness.workflow.max_cost_usd', 1.0);
    config()->set('prism-harness.agent.max_output_tokens_per_call', 4);

    $result = wfRun(wfChain(3), wfRegistry(wfSession()), 'unpriced');

    expect($this->requests)->toBe(0)
        ->and($result->error)->toContain('needs finite, non-negative input and output prices');
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 12
it('charges the reserved worst case when the provider reports no usage, and the next call sees it', function (): void {
    wfPricing();
    config()->set('prism-harness.workflow.max_cost_usd', 0.030);
    $observer = new CostObserver;
    app()->instance(CostObserver::class, $observer);
    config()->set('prism-harness.cost_observer', CostObserver::class);
    $this->reply = fn (int $n): array => wfText(['input_tokens' => 0, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 0, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 0]);

    $result = wfRun(wfChain(5), wfRegistry(), 'no-usage');

    expect($this->requests)->toBe(1)
        ->and($result->error)->toContain(WF_EXHAUSTED)
        ->and($observer->settlements[0]->usageKnown)->toBeFalse()
        ->and($observer->settlements[0]->chargedUsd)->toBe($observer->reservations[0]->worstCaseUsd)
        ->and(app(WorkflowBudgets::class)->open('no-usage')->context()->ledger->costUsd())->toBe($observer->reservations[0]->worstCaseUsd);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 13
it("holds a named agent's spend ceiling behind llm_call nodes", function (): void {
    wfPricing();
    config()->set('prism-harness.agents', ['researcher' => [
        'owner' => 'wf-test', 'spend_ceiling' => 0.030,
        'modes' => ['flow' => ['tools' => ['probe'], 'max_steps' => 3]],
    ]]);
    app()->forgetInstance(AgentRuntime::class);
    app()->forgetInstance(PrismHarness::class);
    $session = wfSession('researcher-wf', 'researcher');
    $session->usingMode('flow');

    $result = wfRun(wfChain(20), wfRegistry($session), 'ceilinged');

    // No tree cap at all: the agent's ceiling alone prices and stops it, at
    // the same fourth call as the cost cap above.
    expect($this->requests)->toBe(3)
        ->and($result->error)->toContain('l4')->toContain("would exceed this agent's spend ceiling");
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 14
it('lets a graph lower its budget and never raise it', function (): void {
    $events = [];

    $result = wfRun(wfChain(105), wfRegistry(workflow: ['max_steps' => 100000]), 'greedy', $events);

    $warnings = array_filter($events, fn (RunEvent $e): bool => $e->type === RunEvent::LOG && str_contains((string) ($e->message ?? ''), 'harness_budget'));
    expect($this->requests)->toBe(100)
        ->and($result->error)->toContain('l101')
        ->and($warnings)->not->toBe([]);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 15
it('tells the cost observer about every workflow model call, and a refusal stops the request', function (): void {
    wfPricing();
    config()->set('prism-harness.workflow.max_cost_usd', 1.0);
    $observer = new CostObserver;
    app()->instance(CostObserver::class, $observer);
    config()->set('prism-harness.cost_observer', CostObserver::class);

    wfRun(wfChain(3), wfRegistry(wfSession()), 'observed');
    $seen = $observer->order;

    $observer->before = function (): never {
        throw new RuntimeException('host says no');
    };
    $refused = wfRun(wfChain(1), wfRegistry(wfSession()), 'observed-refused');

    expect($seen)->toBe(['reserving', 'settled', 'reserving', 'settled', 'reserving', 'settled'])
        ->and($this->requests)->toBe(3)
        ->and($refused->error)->toContain('The host refused this model call.');
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// 16
it('charges a tool call whose handler throws', function (): void {
    config()->set('prism-harness.workflow.max_tool_calls', 2);

    wfRun(wfForEach(5, new FlowNode(id: 't', type: 'tool_use', config: ['tool' => 'boom', 'args' => ['target' => 'x']]),
        new FlowNode(id: 'after', type: 'tool_use', config: ['tool' => 'probe', 'args' => ['target' => 'x']])), wfRegistry(wfSession()));

    expect($this->ran)->toBe(['probe' => 0, 'boom' => 2]);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

// Decision 2: a run with no identity keeps one account with its session.
it('keeps a run without an identity on one account saved with the session', function (): void {
    config()->set('prism-harness.workflow.max_steps', 4);

    wfRun(wfChain(3), wfRegistry(wfSession()), null);
    $first = $this->requests;
    // Resumed elsewhere: a fresh service, a fresh session object, a fresh toolset.
    app()->forgetInstance(WorkflowBudgets::class);
    $resumed = wfRun(wfChain(3), wfRegistry(wfSession()), null);

    expect([$first, $this->requests - $first])->toBe([3, 1])
        ->and($resumed->error)->toContain(WF_EXHAUSTED);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

it('is on by default at 100 model calls per run', function (): void {
    $result = wfRun(wfChain(102), wfRegistry(), 'defaults');

    expect($this->requests)->toBe(100)
        ->and($result->error)->toContain('l101')->toContain(WF_EXHAUSTED);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);

it('leaves an ordinary session turn alone', function (): void {
    config()->set('prism-harness.workflow.max_steps', 1);
    $this->reply = fn (int $n): array => $n < 3 ? wfCall('probe', $n) : wfText();

    $response = wfSession()->send('go');

    expect($this->requests)->toBe(3)
        ->and($this->ran['probe'])->toBe(2)
        ->and($response->text())->toBe('done');
});

it('ships the workflow budget on, at 100 model calls and 100 tool calls, in the shared store', function (): void {
    $shipped = (require __DIR__.'/../config/prism-harness.php')['workflow'];

    expect($shipped)->toBe([
        'max_steps' => 100,
        'max_tool_calls' => 100,
        'max_cost_usd' => null,
        'max_seconds' => null,
        'store' => 'database',
    ]);

    config()->set('prism-harness.workflow', $shipped);
    $budget = app(WorkflowBudgets::class)->open('shipped');

    expect($budget->limits->toArray())->toBe(['max_steps' => 100, 'max_tool_calls' => 100, 'max_cost_usd' => null, 'max_seconds' => null])
        ->and($budget->context()->budget->maxToolCalls)->toBe(100);
});

it('refuses an invalid limit before anything runs', function (array $limits): void {
    expect(fn () => app(WorkflowBudgets::class)->open('invalid', $limits))->toThrow(InvalidArgumentException::class);
})->with([
    'negative steps' => [['max_steps' => -1]],
    'fractional tool calls' => [['max_tool_calls' => 1.5]],
    'infinite cost' => [['max_cost_usd' => INF]],
    'unknown key' => [['max_stepz' => 1]],
]);

it('lets a later open lower a running budget and never raise it', function (): void {
    config()->set('prism-harness.workflow.max_steps', 10);
    app(WorkflowBudgets::class)->open('deployed', ['max_steps' => 5]);

    // A resume after a deploy that raised the operator limit.
    config()->set('prism-harness.workflow.max_steps', 50);
    $resumed = app(WorkflowBudgets::class)->open('deployed');
    $lowered = app(WorkflowBudgets::class)->open('deployed', ['max_steps' => 3]);

    expect($resumed->limits->maxSteps)->toBe(5)
        ->and($lowered->limits->maxSteps)->toBe(3)
        ->and(app(WorkflowBudgets::class)->open('deployed')->limits->maxSteps)->toBe(3);
});

// Decision 2, under a race: two workers make the first call of a run with no
// id on the same session at the same moment.
it('gives two workers racing for a keyless session the same key and one budget', function (): void {
    config()->set('prism-harness.workflow.max_steps', 1);
    $graph = wfChain(1);
    $second = null;
    $armed = true;

    // Worker A's first read or write of the stored key has happened, and
    // nothing after it yet. Worker B runs its whole first call right there.
    DB::listen(function (QueryExecuted $query) use (&$armed, &$second, $graph): void {
        if (! $armed || ! preg_match('/harness_session_state|harness_workflow_budgets/', $query->sql)) {
            return;
        }
        $armed = false;
        app()->forgetInstance(WorkflowBudgets::class);
        $second = wfRun($graph, wfRegistry(wfSession()), null);
    });

    $first = wfRun($graph, wfRegistry(wfSession()), null);

    expect($armed)->toBeFalse()
        ->and($this->requests)->toBe(1)
        ->and([$first->ok, $second?->ok])->toContain(false)
        ->and((string) ($first->error ?? $second?->error))->toContain(WF_EXHAUSTED);
})->skip(fn (): bool => wfEngineMissing(), WF_ENGINE_SKIP);
