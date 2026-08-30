<?php

declare(strict_types=1);

namespace Prism\Harness;

use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Skills\SkillRegistry;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Throwable;

final readonly class AgentRuntime
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private ModeRegistry $modes,
        private ToolRegistry $tools,
        private ToolAuthorizer $authorizer,
        private SkillRegistry $skills,
        private array $config,
    ) {}

    /** @param list<string>|null $toolNames */
    public function send(Session $session, string $prompt, ?array $toolNames = null): AgentResponse
    {
        return $session->lock(function (Session $session) use ($prompt, $toolNames): AgentResponse {
            $mode = $this->modes->resolve($session->mode());
            $provider = $session->provider() ?? $this->stringConfig('provider');
            $model = $session->model() ?? $this->stringConfig('model');
            $runId = 'run_'.bin2hex(random_bytes(12));
            $session->beginRun($runId, $mode->name, $provider, $model);

            try {
                $generation = Prism::text()
                    ->using($provider, $model)
                    ->withThread($session->thread())
                    ->withTelemetryMetadata(sessionId: $session->key())
                    ->withPrompt($prompt);

                $systemPrompt = $this->skills->augmentPrompt($mode->systemPrompt, $mode->skills);
                if ($systemPrompt !== '') {
                    $generation->withSystemPrompt($systemPrompt);
                }

                $names = $toolNames ?? $mode->tools;
                if ($mode->skills !== []) {
                    $names[] = 'skill_read';
                }
                $tools = $this->authorizer->allowed($session, $this->tools->resolve(array_values(array_unique($names)), $session));
                if ($tools !== []) {
                    $generation->withTools($tools)->withMaxSteps($mode->maxSteps);
                }

                $response = $generation->asText();
                $session->thread()->record($response->messages);
                $session->completeRun($runId, $response->finishReason->value);

                return new AgentResponse($runId, $response);
            } catch (Throwable $failure) {
                $session->failRun($runId, $failure::class);
                throw $failure;
            }
        }, ttlSeconds: $this->integerConfig('lock_ttl', 300), waitSeconds: $this->integerConfig('lock_wait', 0));
    }

    private function stringConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new \RuntimeException(sprintf('Harness runtime [%s] is not configured.', $key));
        }

        return $value;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = $this->config[$key] ?? $default;

        return is_int($value) && $value >= 0 ? $value : $default;
    }
}
