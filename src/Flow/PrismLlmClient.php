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

    /** Model steps taken by the workflow loop in progress. */
    private int $loopSteps = 0;

    /** Whether the last step handed tool calls back, so the next one continues its loop. */
    private bool $loopOpen = false;

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
        if ($this->session?->thread()->approvalToolNames() !== null) {
            // The thread ends on a turn a person is deciding. Sending it would
            // make core settle that turn from inside a workflow.
            throw ToolNotAvailable::sessionAwaitingApproval();
        }

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
        $loopStep = $tools !== [] && $this->isAgentLoopStep($prompt, $options);

        if ($tools !== []) {
            $this->countStep($loopStep);
            // Schemas only, from FlowToolset::offered(). Core has nothing here
            // it could run: HarnessToolInvoker is the one place a workflow's
            // tool executes.
            $pending->withTools($tools);
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

        $this->admitStep($response->steps, $tools);
        $calls = $this->toolCalls($response->steps);

        if ($loopStep) {
            $this->loopOpen = $calls !== [];
        }

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

        $available = [];
        foreach ($this->toolset->offered() as $tool) {
            $available[$tool->name()] = $tool;
        }
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
     * Whether this call is a step of an `agent` node's loop.
     *
     * fancy-flow calls this client the same way for `agent` and `llm_call`, and
     * differs in two observable ways: `llm_call` always passes a provider and
     * `agent` never does, and an agent's later steps send the tool results as
     * their prompt. Either one marks a loop, so a change to one of them in the
     * engine does not release an agent from the limit.
     *
     * @param  array<string, mixed>  $options
     */
    private function isAgentLoopStep(string $prompt, array $options): bool
    {
        return ! array_key_exists('provider', $options)
            || str_starts_with($prompt, 'Tool results: [');
    }

    /**
     * Hold a workflow's agent loop to the session's step limit.
     *
     * A step that handed back tool calls is continued by the next. A loop left
     * unfinished by a failed node counts against the next call once, and
     * refusing resets the count. A separate model node is one step of its own:
     * it neither adds to a loop's count nor restarts it, so the number of such
     * nodes is bounded by the workflow and not by this limit.
     */
    private function countStep(bool $loopStep): void
    {
        $limit = $this->toolset?->stepLimit() ?? 1;

        if (! $loopStep) {
            if ($limit < 1) {
                throw ToolNotAvailable::stepLimit($limit);
            }

            return;
        }

        $this->loopSteps = $this->loopOpen ? $this->loopSteps + 1 : 1;
        $this->loopOpen = false;

        if ($this->loopSteps > $limit) {
            $this->loopSteps = 0;

            throw ToolNotAvailable::stepLimit($limit);
        }
    }

    /**
     * Decide the whole step before any of its calls reaches the workflow.
     *
     * @param  iterable<mixed>  $steps
     * @param  list<Tool>  $offered
     *
     * @throws ToolNotAvailable when any call in the step may not run
     */
    private function admitStep(iterable $steps, array $offered): void
    {
        $calls = [];

        foreach ($steps as $step) {
            foreach ($step->toolCalls as $call) {
                try {
                    $arguments = $call->arguments();
                } catch (Throwable) {
                    $arguments = null;
                }

                $calls[] = [
                    'name' => (string) $call->name,
                    'id' => (string) $call->id,
                    'arguments' => is_array($arguments) ? $arguments : null,
                    'callIds' => $this->callIds($call),
                ];
            }
        }

        if ($calls === []) {
            return;
        }

        if (! $this->toolset instanceof FlowToolset) {
            throw ToolNotAvailable::named($calls[0]['name'], []);
        }

        $this->toolset->admit(array_map(static fn (Tool $tool): string => $tool->name(), $offered), $calls);
    }

    /**
     * The ids the provider issued for this call. A provider with no call ids
     * leaves the field empty or core fills it with the tool's name, and neither
     * tells two calls apart, so neither counts. Such calls are all distinct and
     * have no protection from the repeated-id check.
     *
     * @return list<string>
     */
    private function callIds(object $call): array
    {
        $name = (string) $call->name;

        return array_values(array_unique(array_filter(
            [(string) $call->id, (string) ($call->resultId ?? '')],
            static fn (string $id): bool => $id !== '' && $id !== $name,
        )));
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
