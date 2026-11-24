<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use Closure;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;
use Prism\Harness\Sessions\Session;

/**
 * Run a current fancy-flow agent node through a durable Harness session.
 *
 * The turn runs on the workflow's run tree (G-348): every step, and every
 * subagent's step, charges the run's one budget, and the node gets the
 * smaller of the session's own step ceiling and what the workflow has left.
 */
final readonly class HarnessAgentExecutor implements NodeExecutor
{
    /**
     * @param  Closure(string): Session  $sessions
     * @param  array<string, mixed>  $limits  this run's limits from the host; lower only
     * @param  array<string, mixed>  $workflow  the graph's `harness_budget`; lower only
     */
    public function __construct(
        private Closure $sessions,
        private array $limits = [],
        private array $workflow = [],
    ) {}

    /** @return array<string, mixed> */
    public function execute(ExecutionContext $ctx): array
    {
        $scope = $ctx->option('harness_scope');
        if (! is_string($scope) || trim($scope) === '') {
            throw new \InvalidArgumentException('A Harness flow agent requires a nonempty [harness_scope].');
        }

        $session = ($this->sessions)($scope);

        $provider = $ctx->option('provider');
        if (is_string($provider) && $provider !== '') {
            $session->usingProvider($provider);
        }

        $model = $ctx->option('model');
        if (is_string($model) && $model !== '') {
            $session->usingModel($model);
        }

        $mode = $ctx->option('mode');
        if (is_string($mode) && $mode !== '') {
            $session->usingMode($mode);
        }

        $prompt = $ctx->option('prompt', $ctx->input('in', ''));
        if (! is_string($prompt) || trim($prompt) === '') {
            throw new \InvalidArgumentException('A Harness flow agent requires a nonempty prompt.');
        }

        $requested = $ctx->option('tools');
        $toolNames = is_array($requested)
            ? array_values(array_filter($requested, fn (mixed $name): bool => is_string($name) && $name !== ''))
            : null;

        $budget = HarnessFlowExecutors::budgetFor($ctx, $session, $this->limits, $this->workflow);
        $ctx->emit(RunEvent::log('info', 'Harness agent started', $ctx->node->id));
        $result = HarnessFlowExecutors::reporting($ctx, $budget, function () use ($budget, $session, $prompt, $toolNames) {
            $budget->assertAvailable();

            return $session->send($prompt, $toolNames, context: $budget->nodeContext());
        });
        $response = $result->response;

        return [
            'run_id' => $result->runId,
            'text' => $result->text(),
            'finish_reason' => $response->finishReason->value,
            'usage' => [
                'input_tokens' => $response->usage->promptTokens,
                'output_tokens' => $response->usage->completionTokens,
                'reasoning_tokens' => $response->usage->thoughtTokens,
                'cost' => $response->usage->cost,
            ],
        ];
    }
}
