<?php

declare(strict_types=1);

namespace Prism\Harness;

use Closure;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Modes\AgentMode;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Skills\SkillRegistry;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
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
        /**
         * Resolves the subagent runner, lazily.
         *
         * A Closure rather than the object because the dependency is circular:
         * the runner needs PrismHarness to resolve a child session, and
         * PrismHarness needs this runtime. Deferring to call time breaks the
         * cycle without either side holding a half-built collaborator.
         *
         * @var (Closure(): SubagentRunner)|null
         */
        private ?Closure $subagentRunner = null,
    ) {}

    /** @param list<string>|null $toolNames */
    public function send(Session $session, string $prompt, ?array $toolNames = null, ?RunContext $context = null): AgentResponse
    {
        return $session->lock(function (Session $session) use ($prompt, $toolNames, $context): AgentResponse {
            // The id comes FIRST, before anything that can throw.
            //
            // Mode resolution and provider config used to run ahead of it, so a
            // misconfigured mode threw before there was a run to fail: no
            // `failRun`, no RunFailed event, and an application watching the
            // stream saw a run it had started simply never end. A configuration
            // error is the most likely failure here and was the one least
            // visible.
            $runId = 'run_'.bin2hex(random_bytes(12));

            try {
                $mode = $this->modes->resolve($session->mode());
                $provider = $session->provider() ?? $this->stringConfig('provider');
                $model = $session->model() ?? $this->stringConfig('model');
            } catch (Throwable $failure) {
                // NO `failRun` HERE. `beginRun` has not run yet, and finishing a
                // run the session never started trips its own state guard —
                // which then throws OVER the configuration error that actually
                // caused this, replacing a message naming the broken mode with
                // "run state changed while the run was active". The event is
                // the whole point of this block; there is no run state to
                // transition, because there is no run.
                event(new RunFailed(
                    sessionKey: $session->key(),
                    runId: $runId,
                    exception: $failure::class,
                    parentRunId: $context?->parentRunId,
                    rootRunId: $context?->rootRunId(),
                ));

                throw $failure;
            }

            $session->beginRun($runId, $mode->name, $provider, $model);

            // A root run opens the tree's account; a child inherits it. Either
            // way there is exactly one ledger per tree from here down.
            $run = $context ?? RunContext::root($runId, new RunBudget(
                maxSteps: $mode->maxSteps,
                maxCostUsd: $this->floatConfig('max_cost_usd'),
                maxSeconds: $this->nullableIntConfig('max_seconds'),
            ));
            $budget = $run->budget;

            event(new RunStarted(
                sessionKey: $session->key(),
                runId: $runId,
                mode: $mode->name,
                provider: $provider,
                model: $model,
                parentRunId: $run->parentRunId,
                rootRunId: $run->rootRunId(),
            ));

            // The tree's allowance, not this node's wish. A child was already
            // handed a budget narrowed against what the tree had left when it
            // was spawned; this re-checks at the moment of spending, because a
            // sibling may have consumed the remainder in between.
            $stop = $run->ledger->exhaustion($budget);
            if ($stop !== null) {
                $session->failRun($runId, 'budget');

                throw RunNotPermitted::exhausted($stop);
            }

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

                $names = $this->toolNamesFor($mode, $toolNames);
                if ($mode->skills !== []) {
                    $names[] = 'skill_read';
                }
                $resolved = $this->tools->resolve(array_values(array_unique($names)), $session);

                // Subagents are authority, so they go through the SAME
                // authorization pass as any other tool rather than around it.
                foreach ($this->subagentTools($mode, $session, $run, $runId) as $name => $tool) {
                    $resolved[$name] = $tool;
                }

                // Marked BEFORE authorization, so an approval-gated tool that
                // the policy then removes never reaches the model at all —
                // rather than being offered and stopping the run on a request
                // nobody can grant.
                foreach ($resolved as $name => $tool) {
                    if ($mode->needsApproval($name)) {
                        $resolved[$name] = (clone $tool)->requiresApproval(true);
                    }
                }

                $tools = $this->authorizer->allowed($session, $resolved);
                if ($tools !== []) {
                    $generation->withTools($tools)->withMaxSteps($budget->maxSteps);
                }

                $response = $generation->asText();
                $session->thread()->record($response->messages, $runId);
                $session->completeRun($runId, $response->finishReason->value, $this->toolCallNames($response));

                // Charged AFTER the run, to the tree's shared account. A child
                // spending here is what makes the parent's remaining budget
                // shrink — see RunLedger on why one ledger is passed by
                // reference rather than one per run.
                $run->ledger->recordSteps(count($response->steps));
                $run->ledger->recordCost($this->costOf($response));

                $agentResponse = new AgentResponse(
                    runId: $runId,
                    response: $response,
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                );

                event(new RunFinished(
                    sessionKey: $session->key(),
                    runId: $runId,
                    finishReason: $response->finishReason->value,
                    steps: count($response->steps),
                    // Surfaced on the event, not left for a listener to infer
                    // from the finish reason: a paused run is what an
                    // application has to put in front of a person, and it is
                    // the one end state that needs someone to act.
                    awaitingApproval: $agentResponse->awaitingApproval(),
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                ));

                return $agentResponse;
            } catch (Throwable $failure) {
                $session->failRun($runId, $failure::class);

                event(new RunFailed(
                    sessionKey: $session->key(),
                    runId: $runId,
                    exception: $failure::class,
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                ));

                throw $failure;
            }
        }, ttlSeconds: $this->integerConfig('lock_ttl', 300), waitSeconds: $this->integerConfig('lock_wait', 0));
    }

    /**
     * The tool names a run may resolve.
     *
     * THE MODE IS A CEILING, NOT A DEFAULT. A caller-supplied list narrows it
     * and can never widen it. Before this, `$toolNames ?? $mode->tools` let the
     * caller's list REPLACE the mode's, so a mode named `readonly` guaranteed
     * nothing: any caller could name any registered tool, and `'*'` reached
     * everything the registry held.
     *
     * That was survivable while a run was something an application started for
     * itself. It stops being survivable with subagents, whose entire safety
     * story is a narrowed toolset — a child that can widen its own authority
     * back to the registry is not narrowed, it is merely described that way.
     *
     * `'*'` is read in whichever position it appears:
     *  - in the MODE, it is an unrestricted ceiling, so the caller's list stands;
     *  - from the CALLER, it means "everything this mode allows" — never more.
     *
     * @param  list<string>|null  $requested
     * @return list<string>
     */
    private function toolNamesFor(AgentMode $mode, ?array $requested): array
    {
        $ceiling = $mode->tools;

        if ($requested === null || in_array('*', $requested, true)) {
            return $ceiling;
        }

        if (in_array('*', $ceiling, true)) {
            return $requested;
        }

        return array_values(array_intersect($requested, $ceiling));
    }

    /**
     * The nested agents this mode may call, as tools.
     *
     * Empty unless the mode declares them — a run cannot reach a subagent it
     * was not given, and being nested grants nothing by itself.
     *
     * @return array<string, Tool>
     */
    private function subagentTools(AgentMode $mode, Session $session, RunContext $run, string $runId): array
    {
        if ($mode->subagents === [] || ! $this->subagentRunner instanceof Closure) {
            return [];
        }

        $runner = ($this->subagentRunner)();
        $tools = [];

        foreach ($mode->subagents as $subagent) {
            $tools[$subagent->name] = $runner->tool($subagent, $session, $run, $runId);
        }

        return $tools;
    }

    /**
     * The tools a run actually invoked, in order.
     *
     * @return list<string>
     */
    private function toolCallNames(TextResponse $response): array
    {
        $names = [];

        foreach ($response->steps as $step) {
            foreach ($step->toolCalls as $call) {
                $names[] = $call->name;
            }
        }

        return $names;
    }

    private function floatConfig(string $key): ?float
    {
        $value = $this->config[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableIntConfig(string $key): ?int
    {
        $value = $this->config[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * What a response cost, or null when the provider did not say.
     *
     * Passed through as null rather than coerced to zero — see
     * {@see RunLedger::recordCost()} for why that distinction is the whole
     * difference between an enforced cost cap and a decorative one.
     */
    private function costOf(TextResponse $response): ?float
    {
        return $response->usage->cost;
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
