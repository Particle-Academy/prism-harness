<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use Closure;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;
use Prism\Harness\Sessions\Session;

/** Run a current fancy-flow agent node through a durable Harness session. */
final readonly class HarnessAgentExecutor implements NodeExecutor
{
    /** @param Closure(string): Session $sessions */
    public function __construct(private Closure $sessions) {}

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

        $ctx->emit(RunEvent::log('info', 'Harness agent started', $ctx->node->id));
        $result = $session->send($prompt, $toolNames);
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
