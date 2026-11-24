<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use FancyFlow\Nodes\Support\LlmClient;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\ToolNotAvailable;
use Prism\Harness\Exceptions\UnboundFlowTools;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\BudgetedCalls;
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

    /** The workflow run's account; see {@see self::budget()}. */
    private ?WorkflowBudget $budget;

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
        ?WorkflowBudget $budget = null,
    ) {
        $this->toolset = FlowToolset::from($tools);
        $this->budget = $budget;

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
        // A spent run is refused before the lock, the thread or the provider.
        $this->budget()->assertAvailable();

        if (! $this->session instanceof Session) {
            return $this->completeLocked($prompt, $options);
        }

        // The call reads the session's thread and the provider's answer is
        // added to it, so it runs inside the lock an ordinary turn holds.
        // While another holder has it nothing is read, sent or written.
        return $this->session->flowRuntime()->flowLocked(
            $this->session,
            fn (): array => $this->completeLocked($prompt, $options),
            static fn (SessionLocked $locked): ToolNotAvailable => ToolNotAvailable::modelSessionInUse($locked),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function completeLocked(string $prompt, array $options): array
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

        // The workflow's run tree: its ledger, labelled with the session's
        // named agent and held to that agent's spend ceiling, with the host's
        // cost observer. Priced when a cost cap or a ceiling applies.
        $run = $this->runtime()->flowRun($this->session, $this->budget()->context());
        $price = $run->capped() ? $this->runtime()->flowCallBudget($run, $provider, $model) : null;
        $outputLimit = $price !== null ? $price->maxOutputTokens : $run->budget->maxOutputTokensPerCall;

        $maxTokens = isset($options['max_tokens']) && is_numeric($options['max_tokens']) ? (int) $options['max_tokens'] : null;
        if ($outputLimit !== null) {
            // The operator's output limit wins over a larger graph value.
            $maxTokens = $maxTokens === null ? $outputLimit : min($maxTokens, $outputLimit);
        }
        if ($maxTokens !== null) {
            $pending->withMaxTokens($maxTokens);
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

        $pending->withPrompt($prompt);

        if ($price === null) {
            // Admitted and charged in one decision, before the request.
            $run->ledger->takeStep($run->budget);
            $response = $pending->asText();
            $run->ledger->recordCost($response->usage->cost);
        } else {
            $calls = new BudgetedCalls($price);
            [$reservation, $call] = $calls->reserve($run, $pending->toRequest(), 'text', $pending->provider());
            try {
                $run->ledger->takeStep($run->budget);
            } catch (RunNotPermitted $refused) {
                $run->ledger->releaseCost($reservation);

                throw $refused;
            }
            $usage = null;
            $billing = [];
            $outcome = 'failed';
            try {
                $response = $pending->asText();
                $usage = $response->usage;
                $billing = $response->additionalContent;
                $outcome = 'completed';
            } finally {
                $calls->settle($run, $reservation, $call, $usage, $outcome, additionalContent: $billing);
            }
        }

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
     * The workflow run this client charges (G-348): the one it was given, else
     * its toolset's (saved with the session), else one held for this process.
     */
    private function budget(): WorkflowBudget
    {
        return $this->budget ??= $this->toolset?->budget()
            ?? ($this->session instanceof Session
                ? app(WorkflowBudgets::class)->forSession($this->session)
                : app(WorkflowBudgets::class)->forProcess());
    }

    private function runtime(): AgentRuntime
    {
        return $this->session?->flowRuntime() ?? app(AgentRuntime::class);
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
                $arguments = $this->arguments($call);

                // The workflow runs what it is handed, so a call whose arguments
                // could not be read is not handed over at all.
                if ($arguments === null) {
                    continue;
                }

                $calls[] = [
                    'name' => $call->name,
                    'arguments' => $arguments,
                    'id' => $call->id,
                ];
            }
        }

        return $calls;
    }

    /**
     * @return array<string, mixed>|null null when the arguments cannot be used
     */
    private function arguments(object $call): ?array
    {
        try {
            $arguments = $call->arguments();
        } catch (Throwable) {
            return null;
        }

        return is_array($arguments) ? $arguments : null;
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
