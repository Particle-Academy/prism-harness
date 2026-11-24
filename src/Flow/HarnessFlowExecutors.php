<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use Closure;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Nodes\Ai\AgentExecutor;
use FancyFlow\Nodes\Ai\LlmCallExecutor;
use FancyFlow\Nodes\Ai\ToolUseExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Sessions\Session;

/**
 * Bind the Harness to a fancy-flow run so the whole run spends from one
 * budget (G-348).
 *
 * Replaces the `llm_call`, `agent` and `tool_use` executors on the registry
 * with ones that read the engine's run key from each node's context, open
 * that run's {@see WorkflowBudget}, and then delegate to fancy-flow's own
 * executors with Harness adapters bound to it, so node behaviour is
 * unchanged. Nested lanes (`for_each`, `subflow`) keep kind bindings, so
 * every iteration and every level charges the same account.
 *
 * This is the only route that sees the run key: fancy-flow hands its
 * `LlmClient` and `ToolInvoker` no context. Adapters built without it are
 * still bounded, by a key the Harness saves with the session.
 *
 *     $registry = Builtin::executors($deps);
 *     HarnessFlowExecutors::register($registry, tools: $session->flowTools());
 *     (new FlowRunner)->run($graph, $registry, options: new RunOptions(run: $runKey));
 */
final class HarnessFlowExecutors
{
    /**
     * @param  FlowToolset|null  $tools  the session tools this run's `llm_call`, built-in `agent` and `tool_use` nodes use; null for sessionless model calls and no tools
     * @param  (Closure(string): Session)|null  $sessions  when given, an `agent` node runs as a whole session turn ({@see HarnessAgentExecutor})
     * @param  array<string, mixed>  $limits  this run's limits from the host; they can only lower the operator's
     * @param  array<string, mixed>  $workflow  the graph's `harness_budget` metadata; it can only lower them too
     */
    public static function register(
        ExecutorRegistry $registry,
        ?FlowToolset $tools = null,
        ?Closure $sessions = null,
        ?string $provider = null,
        ?string $model = null,
        array $limits = [],
        array $workflow = [],
    ): ExecutorRegistry {
        $self = new self($tools, $limits, $workflow);

        $registry->bind('llm_call', fn (ExecutionContext $ctx): mixed => $self->charged($ctx,
            fn (WorkflowBudget $budget): mixed => (new LlmCallExecutor($self->client($budget, $provider, $model)))->execute($ctx)));

        $registry->bind('tool_use', fn (ExecutionContext $ctx): mixed => $self->charged($ctx,
            fn (WorkflowBudget $budget): mixed => (new ToolUseExecutor(new HarnessToolInvoker($tools ?? [], $budget)))->execute($ctx)));

        $registry->bind('agent', $sessions !== null
            ? new HarnessAgentExecutor($sessions, $limits, $workflow)
            : fn (ExecutionContext $ctx): mixed => $self->charged($ctx,
                fn (WorkflowBudget $budget): mixed => (new AgentExecutor(
                    $self->client($budget, $provider, $model),
                    new HarnessToolInvoker($tools ?? [], $budget),
                ))->execute($ctx)));

        return $registry;
    }

    /**
     * The account a node charges: the engine's run key when there is one;
     * otherwise the key saved for the session; otherwise one held for this
     * registration's process.
     *
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>  $workflow
     */
    public static function budgetFor(ExecutionContext $ctx, ?Session $session, array $limits, array $workflow, ?Closure $fallback = null): WorkflowBudget
    {
        $budgets = app(WorkflowBudgets::class);
        $runKey = $ctx->run?->runKey;

        if (is_string($runKey) && trim($runKey) !== '') {
            return $budgets->open($runKey, $limits, $workflow);
        }

        if ($session instanceof Session) {
            return $budgets->forSession($session, $limits, $workflow);
        }

        return $fallback !== null ? $fallback() : $budgets->forProcess();
    }

    /**
     * Report a budget refusal on the node, then fail it.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function reporting(ExecutionContext $ctx, WorkflowBudget $budget, Closure $work): mixed
    {
        foreach ($budget->warnings as $warning) {
            $ctx->emit(RunEvent::log('warning', $warning, $ctx->node->id));
        }

        try {
            return $work();
        } catch (RunNotPermitted $refused) {
            $ctx->emit(RunEvent::log('error', $refused->getMessage(), $ctx->node->id));

            throw $refused;
        }
    }

    private ?WorkflowBudget $processBudget = null;

    /**
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>  $workflow
     */
    private function __construct(
        private readonly ?FlowToolset $tools,
        private readonly array $limits,
        private readonly array $workflow,
    ) {}

    /**
     * @param  Closure(WorkflowBudget): mixed  $work
     */
    private function charged(ExecutionContext $ctx, Closure $work): mixed
    {
        $budget = self::budgetFor($ctx, $this->tools?->session(), $this->limits, $this->workflow,
            fn (): WorkflowBudget => $this->processBudget ??= app(WorkflowBudgets::class)->forProcess());

        return self::reporting($ctx, $budget, fn (): mixed => $work($budget));
    }

    private function client(WorkflowBudget $budget, ?string $provider, ?string $model): PrismLlmClient
    {
        return new PrismLlmClient($provider, $model, $this->tools?->session(), $this->tools ?? [], $budget);
    }
}
