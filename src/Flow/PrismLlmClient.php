<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use FancyFlow\Nodes\Support\LlmClient;
use Prism\Harness\Exceptions\ToolNotAvailable;
use Prism\Harness\Exceptions\UnboundFlowTools;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Throwable;

/**
 * fancy-flow's `llm_call` and `agent` nodes, driven by Prism.
 *
 * OPTIONAL. `particle-academy/fancy-flow-php` lives under composer `suggest`,
 * never `require` — it needs PHP 8.4 and this package supports 8.2, so a hard
 * dependency would drop two supported versions to gain an integration most
 * consumers do not use. Nothing autoloads this class unless an application
 * wires it.
 *
 * WHAT THIS ADDS OVER A PLAIN ADAPTER. fancy-flow ships one implementation of
 * this contract, `EchoLlmClient`, and it returns no `tool_calls` — so the agent
 * node's tool loop currently cannot engage with anything shipped. More
 * importantly, `AgentExecutor` carries no conversation: on each step it builds
 * a FRESH prompt reading `Tool results: {...}`, discarding everything before it.
 * That is survivable for one turn and wrong for an agent.
 *
 * Given a harness {@see Session}, this client runs the call against that
 * session's THREAD, so the turn is appended to a durable conversation. A
 * workflow that pauses for approval, checkpoints, and resumes an hour later in
 * another queue worker picks up an agent that remembers what it was doing —
 * which is the reason fancy-flow's durable runs and this package belong in the
 * same sentence at all.
 *
 * Without a session it degrades to a stateless completion, which is the right
 * behaviour for `llm_call`.
 */
final class PrismLlmClient implements LlmClient
{
    private readonly ?Session $session;

    private readonly ?FlowToolset $toolset;

    /**
     * @param  FlowToolset|array{}  $tools  from {@see Session::flowTools()}; empty for a call with no tools
     *
     * @throws UnboundFlowTools when given tools that did not come from a session
     */
    public function __construct(
        private readonly ?string $defaultProvider = null,
        private readonly ?string $defaultModel = null,
        ?Session $session = null,
        array|FlowToolset $tools = [],
    ) {
        $this->toolset = FlowToolset::from($tools);

        if ($this->toolset instanceof FlowToolset && $session instanceof Session && $session !== $this->toolset->session()) {
            throw UnboundFlowTools::otherSession();
        }

        $this->session = $session ?? $this->toolset?->session();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function complete(string $prompt, array $options = []): array
    {
        $provider = $this->stringOption($options, 'provider')
            ?? $this->session?->provider()
            ?? $this->defaultProvider
            ?? 'anthropic';
        $model = $this->stringOption($options, 'model')
            ?? $this->session?->model()
            ?? $this->defaultModel;

        if ($model === null || $model === '') {
            // Prism has no default model, and a provider asked for none fails
            // with a generic HTTP error that names neither cause. Say which
            // knob is unset instead.
            throw new \RuntimeException(
                'No model configured for the Prism flow client. Set the node\'s `model` option, '
                .'the session model, or pass a default to the constructor.'
            );
        }

        $pending = Prism::text()->using($provider, $model);

        if (($system = $this->stringOption($options, 'system')) !== null) {
            $pending->withSystemPrompt($system);
        }

        if (isset($options['temperature']) && is_numeric($options['temperature'])) {
            $pending->usingTemperature((float) $options['temperature']);
        }

        if (isset($options['max_tokens']) && is_numeric($options['max_tokens'])) {
            $pending->withMaxTokens((int) $options['max_tokens']);
        }

        $tools = $this->requestedTools($options);

        if ($tools !== []) {
            // The model sees each tool's schema and core runs none of them:
            // HarnessToolInvoker is the one place a workflow's tool executes.
            $pending->withTools(array_map(
                static fn (Tool $tool): Tool => (clone $tool)->clientExecuted(),
                $tools,
            ));
            // ONE step. fancy-flow owns the loop: AgentExecutor invokes the
            // tools itself and calls back.
            $pending->withMaxSteps(1);
        }

        if ($this->session instanceof Session) {
            // The durable conversation. Everything before this turn travels
            // with it, which is what a checkpointed run resumes into.
            $pending->withThread($this->session->thread());
        }

        $response = $pending->withPrompt($prompt)->asText();

        $result = [
            'text' => $response->text,
            'usage' => [
                'input_tokens' => $response->usage->promptTokens,
                'output_tokens' => $response->usage->completionTokens,
            ],
        ];

        $calls = $this->withinOffer($this->toolCalls($response->steps), $response->steps, $tools);

        if ($calls !== []) {
            // Undeclared by the contract and read by AgentExecutor. Emitted
            // because the node's loop is dead without it — see the class
            // docblock. Both key spellings the executor accepts are covered.
            $result['tool_calls'] = $calls;
        }

        return $result;
    }

    /**
     * The tools this call may use.
     *
     * `$options['tools']` NAMES tools; it does not define them. A name that was
     * never registered is dropped rather than passed through, because a
     * workflow author naming a tool that does not exist should get a model that
     * cannot call it — not a provider error about an unknown schema, and
     * certainly not an invocation of something the host never offered.
     *
     * @param  array<string, mixed>  $options
     * @return list<Tool>
     */
    private function requestedTools(array $options): array
    {
        $requested = $options['tools'] ?? null;

        if (! is_array($requested) || $requested === [] || ! $this->toolset instanceof FlowToolset) {
            return [];
        }

        $available = $this->toolset->tools();
        $tools = [];

        foreach ($requested as $name) {
            $name = is_array($name) ? ($name['name'] ?? null) : $name;

            if (is_string($name) && isset($available[$name])) {
                $tools[] = $available[$name];
            }
        }

        return $tools;
    }

    /**
     * The calls a workflow may be handed: those naming a tool this request
     * offered, with arguments that could be read.
     *
     * @param  list<array<string, mixed>>  $calls
     * @param  iterable<mixed>  $steps
     * @param  list<Tool>  $offered
     * @return list<array<string, mixed>>
     *
     * @throws ToolNotAvailable when the model named a tool it was not offered
     */
    private function withinOffer(array $calls, iterable $steps, array $offered): array
    {
        $names = array_map(static fn (Tool $tool): string => $tool->name(), $offered);

        foreach ($calls as $call) {
            if (! in_array($call['name'], $names, true)) {
                throw ToolNotAvailable::named((string) $call['name'], $names);
            }
        }

        $unreadable = [];

        foreach ($steps as $step) {
            foreach ($step->toolCalls as $call) {
                try {
                    if (! is_array($call->arguments())) {
                        $unreadable[$call->id] = true;
                    }
                } catch (Throwable) {
                    $unreadable[$call->id] = true;
                }
            }
        }

        return array_values(array_filter(
            $calls,
            static fn (array $call): bool => ! isset($unreadable[$call['id']]),
        ));
    }

    /**
     * @param  iterable<mixed>  $steps
     * @return list<array<string, mixed>>
     */
    private function toolCalls(iterable $steps): array
    {
        $calls = [];

        foreach ($steps as $step) {
            foreach ($step->toolCalls as $call) {
                $calls[] = [
                    'name' => $call->name,
                    'arguments' => $this->arguments($call),
                    'id' => $call->id,
                ];
            }
        }

        return $calls;
    }

    /**
     * @return array<string, mixed>
     */
    private function arguments(object $call): array
    {
        try {
            $arguments = $call->arguments();
        } catch (Throwable) {
            // Malformed arguments are the model's mistake, not this adapter's.
            // An empty set lets the node invoke the tool and fail on its own
            // validation, which reports better than an exception from here.
            return [];
        }

        return is_array($arguments) ? $arguments : [];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
