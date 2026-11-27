<?php

declare(strict_types=1);

namespace Prism\Harness;

use Closure;
use Generator;
use Illuminate\Support\Collection;
use Prism\Harness\Context\MemoryHooks;
use Prism\Harness\Context\SummarisingCompaction;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Events\FlowToolRefused;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunResumed;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Events\RunStepped;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Exceptions\SessionLockLost;
use Prism\Harness\Exceptions\StructuredSchemaViolation;
use Prism\Harness\Exceptions\ToolNotAvailable;
use Prism\Harness\Flow\FlowToolset;
use Prism\Harness\Models\Thread;
use Prism\Harness\Modes\AgentMode;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Skills\SkillRegistry;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Steps\RunStepTagger;
use Prism\Harness\Structured\SchemaCheck;
use Prism\Harness\Structured\StructuredTranscript;
use Prism\Harness\Subagents\ApprovedTools;
use Prism\Harness\Subagents\BudgetedCalls;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\ResolutionRecorder;
use Prism\Harness\Subagents\ResolvedApprovals;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\StepCalls;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Support\TurnAttachments;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRefusals;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Schema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Streaming\StreamCollector;
use Prism\Prism\Structured\PendingRequest as StructuredPendingRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\Step as StructuredStep;
use Prism\Prism\Text\PendingRequest as TextPendingRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;

final readonly class AgentRuntime
{
    /** The run id on decisions made for a workflow node, which has no harness run. */
    private const FLOW_RUN = 'flow';

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
        /** @var array<string, mixed> */
        private array $pricing = [],
        /** @var (Closure(): ?CallCostObserver)|null */
        private ?Closure $costObserver = null,
        /** @var array<string, mixed> */
        private array $agents = [],
    ) {
        foreach ($agents as $agent => $options) {
            SessionAddress::validateAgent($agent);
            if (! is_array($options)) {
                throw new \InvalidArgumentException('Agent settings must be a map.');
            }
            foreach (['provider', 'model'] as $key) {
                if (array_key_exists($key, $options) && $options[$key] !== null && (! is_string($options[$key]) || $options[$key] === '')) {
                    throw new \InvalidArgumentException('Agent provider/model defaults must be nonempty strings or null.');
                }
            }
            foreach (['max_steps_ceiling', 'max_output_tokens_per_call'] as $key) {
                if (array_key_exists($key, $options) && $options[$key] !== null && (! is_int($options[$key]) || $options[$key] < 1)) {
                    throw new \InvalidArgumentException('Agent step/output ceilings must be positive integers or null.');
                }
            }
            foreach (['lock_ttl' => 1, 'lock_wait' => 0] as $key => $minimum) {
                if (array_key_exists($key, $options) && (! is_int($options[$key]) || $options[$key] < $minimum)) {
                    throw new \InvalidArgumentException('Agent lock settings must be whole seconds with positive TTL and nonnegative wait.');
                }
            }
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent  media sent with the prompt; see TurnAttachments for what is refused
     */
    public function send(Session $session, string $prompt, ?array $toolNames = null, ?RunContext $context = null, array $additionalContent = []): AgentResponse
    {
        // Refused BEFORE the lock and before a run exists: a bad attachment is a
        // mistake in the call, and it should not cost a run row, events or budget.
        $attachments = TurnAttachments::admit($prompt, $additionalContent);
        $context = $this->executionContext($context);
        $runId = null;

        try {
            return $this->sendLocked($session, $prompt, $toolNames, $context, $attachments, $runId);
        } finally {
            if ($runId !== null) {
                ToolRefusals::forget($runId);
            }
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     */
    private function sendLocked(Session $session, string $prompt, ?array $toolNames, ?RunContext $context, array $attachments, ?string &$runId): AgentResponse
    {
        return $session->fencedLock(function (Session $session) use ($prompt, $toolNames, $context, $attachments, &$runId): AgentResponse {
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
                $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());
                $provider = $session->provider() ?? $this->stringConfig('provider', $session);
                $model = $session->model() ?? $this->stringConfig('model', $session);
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
                maxSteps: $this->ceilingFor($session, $mode),
                maxCostUsd: $this->floatConfig('max_cost_usd'),
                maxSeconds: $this->nullableIntConfig('max_seconds'),
                maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
            ));
            $run = $this->boundedAgentRun($session, $run);
            $this->continuePausedSteps($session, $prompt, $context, $run);
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
            if ($budget->maxCostUsd === null && $stop !== null) {
                $session->failRun($runId, 'budget');

                throw RunNotPermitted::exhausted($stop);
            }

            try {
                $run = $this->observedRun($run);
                $this->assertMeteredCompaction($budget);
                $this->endRepeatedIdStep($session, $runId);
                // The history is read HERE, once, and handed to Prism as
                // messages rather than as a Thread. See newMessages() for why
                // this run has to know exactly which message objects it sent.
                $history = iterator_to_array($session->thread()->messages(), false);

                $generation = Prism::text()
                    ->using($provider, $model)
                    ->withMessages($prompt === '' ? $history : [...$history, new UserMessage($prompt, $attachments)])
                    ->withTelemetryMetadata(sessionId: $session->key());

                if ($mode->providerOptions !== []) {
                    $generation->withProviderOptions($mode->providerOptions);
                }

                $systemPrompt = app(MemoryHooks::class)->systemPrompt($this->skills->augmentPrompt($mode->systemPrompt, $mode->skills), $session, $prompt);
                if ($systemPrompt !== '') {
                    $generation->withSystemPrompt($systemPrompt);
                }

                $tools = $this->authorizedTools($mode, $session, $toolNames, $run, $runId);
                $stepOnly = $context !== null || $mode->subagents !== [];
                $recorder = $budget->maxCostUsd === null && ! $stepOnly ? ResolutionRecorder::for($history) : null;
                $watched = $recorder?->watch($tools);
                if ($tools !== []) {
                    $generation->withTools($watched ?? $tools)->withMaxSteps($budget->maxCostUsd === null ? $this->requestSteps($session, $run) : 1);
                }
                if ($budget->maxOutputTokensPerCall !== null) {
                    $generation->withMaxTokens($budget->maxOutputTokensPerCall);
                }
                $response = $budget->maxCostUsd === null
                    ? ($stepOnly ? (new StepCalls)->text($generation, $run,
                        function (TextResponse $response) use ($session, &$history, $runId, $tools): void {
                            $this->recordTurn($session, $this->newMessages($response, $history), $runId, $tools);
                        }, function (array $messages) use ($session, &$history, $runId, $tools): void {
                            $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                            $history = $messages;
                        }) : $this->recordingResolution($recorder, $watched !== null, $generation, function (array $messages) use ($session, &$history, $runId, $tools): void {
                            $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                            $history = $messages;
                        }, $session, $history, $runId, $tools, fn (): TextResponse => $generation->asText()))
                    : $this->budgetedCalls($run, $provider, $model)->text($generation, $run,
                        function (TextResponse $response) use ($session, &$history, $runId, $tools): void {
                            $this->recordTurn($session, $this->newMessages($response, $history), $runId, $tools);
                        }, function (array $messages) use ($session, &$history, $runId, $tools): void {
                            $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                            $history = $messages;
                        });
                if ($budget->maxCostUsd === null && ! $stepOnly) {
                    $this->recordTurn($session, $this->newMessages($response, $history), $runId, $tools);
                }
                $failed = in_array($response->finishReason, [FinishReason::Error, FinishReason::Unknown], true);
                if ($failed) {
                    $session->failRun($runId, $response->finishReason->value);
                } else {
                    $session->completeRun($runId, $response->finishReason->value, $this->toolCallNames($response));
                }

                // Shared trees charge each step before its provider call.
                // Ordinary runs retain their aggregate accounting here; an
                // uncapped run's reported cost is recorded once after the run.
                if ($budget->maxCostUsd === null) {
                    if (! $stepOnly) {
                        $run->ledger->recordSteps(count($response->steps));
                    }
                    $run->ledger->recordCost($this->costOf($response));
                }

                $agentResponse = new AgentResponse(
                    runId: $runId,
                    response: $response,
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                );

                if ($failed) {
                    event(new RunFailed($session->key(), $runId, $response->finishReason->value,
                        $run->parentRunId, $run->rootRunId()));
                } else {
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
                }

                return $agentResponse;
            } catch (Throwable $failure) {
                if ($failure instanceof SessionLockLost && $failure->isWriteRejected()) {
                    throw $failure;
                }
                $session->failRun($runId, $failure instanceof RunNotPermitted ? 'budget' : $failure::class);

                event(new RunFailed(
                    sessionKey: $session->key(),
                    runId: $runId,
                    exception: $failure::class,
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                ));

                throw $failure;
            }
        }, ttlSeconds: $this->integerConfig('lock_ttl', 300, $session), waitSeconds: $this->integerConfig('lock_wait', 0, $session));
    }

    /**
     * A turn whose answer is a document.
     *
     * The same run as {@see self::send()} — same mode, same tools, same budget,
     * same events, same lock — asking the provider for structured output and
     * checking what comes back against the schema before the caller sees it.
     *
     * WHAT THE THREAD KEEPS IS THE TEXT. The assistant message is the document
     * exactly as the model wrote it, with the parsed object attached beside it
     * as `structured` metadata. Never instead of it: a later turn replays this
     * conversation as messages, and a transcript that reads differently because
     * of the SHAPE of the request that produced it is the same defect as one
     * that reads differently when streamed — which {@see self::stream()} exists
     * to avoid. The metadata is stored and replayed; no provider map reads that
     * key, so nothing is sent back out with it.
     *
     * A FAILED DOCUMENT IS STILL RECORDED, and then raised. The exchange
     * happened: the model was asked and answered, and a thread that omits the
     * answer it did not like is a thread that cannot explain the retry sitting
     * next to it. The run is marked failed, `RunFailed` fires, and the caller
     * gets {@see StructuredSchemaViolation} carrying the document.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent  media sent with the prompt; see TurnAttachments for what is refused
     *
     * @throws StructuredSchemaViolation when the model returns no document, or one the schema refuses
     */
    public function sendStructured(Session $session, string $prompt, Schema $schema, ?array $toolNames = null, ?RunContext $context = null, array $additionalContent = []): StructuredAgentResponse
    {
        $attachments = TurnAttachments::admit($prompt, $additionalContent);
        $context = $this->executionContext($context);
        $runId = null;

        try {
            return $this->sendStructuredLocked($session, $prompt, $schema, $toolNames, $context, $attachments, $runId);
        } finally {
            if ($runId !== null) {
                ToolRefusals::forget($runId);
            }
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     */
    private function sendStructuredLocked(Session $session, string $prompt, Schema $schema, ?array $toolNames, ?RunContext $context, array $attachments, ?string &$runId): StructuredAgentResponse
    {
        return $session->fencedLock(function (Session $session) use ($prompt, $schema, $toolNames, $context, $attachments, &$runId): StructuredAgentResponse {
            $runId = 'run_'.bin2hex(random_bytes(12));

            try {
                $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());
                $provider = $session->provider() ?? $this->stringConfig('provider', $session);
                $model = $session->model() ?? $this->stringConfig('model', $session);
            } catch (Throwable $failure) {
                // As in send(): no run exists yet, so there is no run state to
                // fail — only an event to fire.
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

            $run = $context ?? RunContext::root($runId, new RunBudget(
                maxSteps: $this->ceilingFor($session, $mode),
                maxCostUsd: $this->floatConfig('max_cost_usd'),
                maxSeconds: $this->nullableIntConfig('max_seconds'),
                maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
            ));
            $run = $this->boundedAgentRun($session, $run);
            $this->continuePausedSteps($session, $prompt, $context, $run);
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

            $stop = $run->ledger->exhaustion($budget);
            if ($budget->maxCostUsd === null && $stop !== null) {
                $session->failRun($runId, 'budget');

                throw RunNotPermitted::exhausted($stop);
            }

            try {
                $run = $this->observedRun($run);
                $this->assertMeteredCompaction($budget);
                $this->endRepeatedIdStep($session, $runId);
                $history = iterator_to_array($session->thread()->messages(), false);
                $userMessage = $prompt === '' ? null : new UserMessage($prompt, $attachments);

                $generation = Prism::structured()
                    ->using($provider, $model)
                    ->withSchema($schema)
                    ->withMessages($userMessage instanceof UserMessage ? [...$history, $userMessage] : $history)
                    ->withTelemetryMetadata(sessionId: $session->key());

                if ($mode->providerOptions !== []) {
                    $generation->withProviderOptions($mode->providerOptions);
                }

                $systemPrompt = app(MemoryHooks::class)->systemPrompt($this->skills->augmentPrompt($mode->systemPrompt, $mode->skills), $session, $prompt);
                if ($systemPrompt !== '') {
                    $generation->withSystemPrompt($systemPrompt);
                }

                $tools = $this->authorizedTools($mode, $session, $toolNames, $run, $runId);
                $stepOnly = $context !== null || $mode->subagents !== [];
                $recorder = $budget->maxCostUsd === null && ! $stepOnly ? ResolutionRecorder::for($history) : null;
                $watched = $recorder?->watch($tools);
                if ($tools !== []) {
                    $generation->withTools($watched ?? $tools)->withMaxSteps($budget->maxCostUsd === null ? $this->requestSteps($session, $run) : 1);
                }
                if ($budget->maxOutputTokensPerCall !== null) {
                    $generation->withMaxTokens($budget->maxOutputTokensPerCall);
                }
                $response = $budget->maxCostUsd === null
                    ? ($stepOnly ? (new StepCalls)->structured($generation, $run,
                        function (StructuredResponse $response) use ($session, &$userMessage, $runId, $tools): void {
                            $this->recordTurn($session, StructuredTranscript::of($response, $userMessage), $runId, $tools);
                        }, function (array $messages) use ($session, &$history, &$userMessage, $runId, $tools): void {
                            $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                            $history = $messages;
                            $userMessage = null;
                        }) : $this->recordingResolution($recorder, $watched !== null, $generation, function (array $messages) use ($session, &$history, &$userMessage, $runId, $tools): void {
                            $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                            $history = $messages;
                            $userMessage = null;
                        }, $session, $history, $runId, $tools, fn (): StructuredResponse => $generation->asStructured()))
                    : $this->budgetedCalls($run, $provider, $model)->structured($generation, $run,
                        function (StructuredResponse $response) use ($session, &$userMessage, $runId, $tools): void {
                            $this->recordTurn($session, StructuredTranscript::of($response, $userMessage), $runId, $tools);
                        }, function (array $messages) use ($session, &$history, &$userMessage, $runId, $tools): void {
                            $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                            $history = $messages;
                            $userMessage = null;
                        });

                if ($budget->maxCostUsd === null && ! $stepOnly) {
                    $this->recordTurn($session, $this->resolvedFirstInTranscript($response, $userMessage, $history), $runId, $tools);
                }

                $violation = $this->violationIn($schema, $response);

                if ($violation instanceof StructuredSchemaViolation) {
                    throw $violation;
                }

                $failed = in_array($response->finishReason, [FinishReason::Error, FinishReason::Unknown], true);
                if ($failed) {
                    $session->failRun($runId, $response->finishReason->value);
                } else {
                    $session->completeRun($runId, $response->finishReason->value, $this->structuredToolCallNames($response));
                }

                if ($budget->maxCostUsd === null) {
                    if (! $stepOnly) {
                        $run->ledger->recordSteps(count($response->steps));
                    }
                    $run->ledger->recordCost($response->usage->cost);
                }

                if ($failed) {
                    event(new RunFailed($session->key(), $runId, $response->finishReason->value,
                        $run->parentRunId, $run->rootRunId()));
                } else {
                    event(new RunFinished(
                        sessionKey: $session->key(),
                        runId: $runId,
                        finishReason: $response->finishReason->value,
                        steps: count($response->steps),
                        // A structured run returns a document or raises, so it has
                        // no state in which somebody has to decide something.
                        awaitingApproval: false,
                        parentRunId: $run->parentRunId,
                        rootRunId: $run->rootRunId(),
                    ));
                }

                return new StructuredAgentResponse(
                    runId: $runId,
                    response: $response,
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                );
            } catch (Throwable $failure) {
                if ($failure instanceof SessionLockLost && $failure->isWriteRejected()) {
                    throw $failure;
                }
                $session->failRun($runId, $failure instanceof RunNotPermitted ? 'budget' : $failure::class);

                event(new RunFailed(
                    sessionKey: $session->key(),
                    runId: $runId,
                    exception: $failure::class,
                    parentRunId: $run->parentRunId,
                    rootRunId: $run->rootRunId(),
                ));

                throw $failure;
            }
        }, ttlSeconds: $this->integerConfig('lock_ttl', 300, $session), waitSeconds: $this->integerConfig('lock_wait', 0, $session));
    }

    /**
     * What is wrong with this document, or null when nothing is.
     *
     * Prism leaves `structured` null when the text held no readable document,
     * so the two failures are told apart here rather than collapsed into one
     * message a caller has to read prose out of.
     */
    private function violationIn(Schema $schema, StructuredResponse $response): ?StructuredSchemaViolation
    {
        if ($response->structured === null) {
            return StructuredSchemaViolation::unreadable($response->text);
        }

        $problems = SchemaCheck::problems($schema, $response->structured);

        return $problems === []
            ? null
            : StructuredSchemaViolation::failsSchema($response->text, $problems);
    }

    /**
     * The tools a structured run invoked, in order.
     *
     * @return list<string>
     */
    private function structuredToolCallNames(StructuredResponse $response): array
    {
        return array_map(fn (ToolCall $call): string => $call->name, $response->toolCalls);
    }

    /**
     * The same run, delivered as it happens.
     *
     * Yields Prism's stream events UNTOUCHED, so an application already
     * rendering thinking, tool calls and results keeps exactly the payloads it
     * renders today. What this adds is the durable half: the turn is recorded
     * and the run is closed out when the stream ends.
     *
     * THE LOCK IS HELD FOR THE WHOLE ITERATION, which is the awkward part of
     * streaming through a durable session and is why the `finally` matters. A
     * consumer that abandons the generator — a disconnected browser, an
     * exception upstream — would otherwise leave the run open and the session
     * locked until the TTL expired. PHP runs a generator's `finally` when it is
     * destroyed, so the run is closed on that path too, recorded with whatever
     * the stream had produced by then rather than discarded.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent  media sent with the prompt; see TurnAttachments for what is refused
     * @return Generator<int, StreamEvent>
     */
    public function stream(Session $session, string $prompt, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null): Generator
    {
        yield from $this->taggedStream($session, $prompt, $toolNames, $additionalContent, $this->executionContext($context),
            new RunStepTagger($context?->turnId, buffered: false));
    }

    /**
     * The same run as {@see self::stream()}, delivered as tagged {@see RunStep}s.
     *
     * The run is identical — the same paths, the same recording, the same
     * events, including a {@see RunStepped} per step — and what the caller
     * receives is the step stream instead of core's events. A core event with
     * no step mapping is still observed by the run; it is just not forwarded.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent
     * @return Generator<int, RunStep>
     */
    public function streamSteps(Session $session, string $prompt, ?array $toolNames = null, array $additionalContent = [], ?RunContext $context = null): Generator
    {
        $tagger = new RunStepTagger($context?->turnId, buffered: true);
        foreach ($this->taggedStream($session, $prompt, $toolNames, $additionalContent, $this->executionContext($context), $tagger) as $event) {
            foreach ($tagger->drain() as $step) {
                yield $step;
            }
        }
        foreach ($tagger->drain() as $step) {
            yield $step;
        }
    }

    /**
     * Continue a run parked for approval, as tagged steps (G-284).
     *
     * The session lock is taken first; then `$record` writes the decisions,
     * through the same code as decide(), and reports where the parked run's
     * sequence ended. Only then are they reported and the run resumed on the
     * step path, which resolves
     * approvals from the thread. A dropped stream has lost nothing, and a
     * second resume finds every approved call already answered and runs none
     * of them again.
     *
     * The resumed run keeps the parked run's id when the session still knows
     * it, so `seq` continues from where that run's stream ended.
     *
     * @internal Called by Session::streamDecision().
     *
     * @param  list<ToolApprovalResponse>  $decisions
     * @param  Closure(): array{0: ?string, 1: int, 2?: list<ToolApprovalResponse>}  $record  runs inside the lock
     * @return Generator<int, RunStep>
     */
    public function streamDecisionSteps(Session $session, array $decisions, Closure $record, ?RunContext $context): Generator
    {
        // The lock FIRST, before the decisions are written or any provider is
        // called. A held session refuses here, having changed nothing.
        $release = $session->holdFencedLock($this->integerConfig('lock_ttl', 300, $session), $this->integerConfig('lock_wait', 0, $session));
        try {
            // Iterated rather than delegated with `yield from`, so an abandoned
            // stream destroys the run (closing it out under the fence) BEFORE
            // the lock is released.
            foreach ($this->resumeLocked($session, $decisions, $record, $context) as $step) {
                yield $step;
            }
        } finally {
            $release();
        }
    }

    /**
     * @param  list<ToolApprovalResponse>  $decisions
     * @param  Closure(): array{0: ?string, 1: int, 2?: list<ToolApprovalResponse>}  $record
     * @return Generator<int, RunStep>
     */
    private function resumeLocked(Session $session, array $decisions, Closure $record, ?RunContext $context): Generator
    {
        $written = $record();
        [$parkedRunId, $lastSeq] = $written;
        // Report what was RECORDED, which is what the run acts on: a step
        // whose call ids repeat records every decision as a denial (G-392).
        $decisions = $written[2] ?? $decisions;
        $tagger = new RunStepTagger($context?->turnId, buffered: true, seq: $lastSeq, resumeRunId: $parkedRunId);
        $actor = $context?->actor;
        $context = $this->executionContext($context);
        $runId = $tagger->claimRunId();
        $tagger->bind($session->key(), $runId, $context?->parentRunId, $context?->rootRunId());
        foreach ($decisions as $decision) {
            // From the APP's run context, never from the model or the request.
            $tagger->decision($decision->approvalId, $decision->approved, $decision->reason, $actor);
        }
        foreach ($tagger->drain() as $step) {
            yield $step;
        }

        // The step path, always. It resolves approvals from the thread before
        // any provider call and records the merge first, and it takes an empty
        // prompt as "continue" rather than as a turn to record.
        $capped = ($context === null ? $this->floatConfig('max_cost_usd') : $context->budget->maxCostUsd) !== null;
        $events = $capped
            ? $this->streamCapped($session, '', null, $context, [], $tagger)
            : $this->streamStepTree($session, '', null, $context, [], $tagger);
        foreach ($events as $event) {
            foreach ($tagger->drain() as $step) {
                yield $step;
            }
        }
        foreach ($tagger->drain() as $step) {
            yield $step;
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $additionalContent
     * @return Generator<int, StreamEvent>
     */
    private function taggedStream(Session $session, string $prompt, ?array $toolNames, array $additionalContent, ?RunContext $context, RunStepTagger $tagger): Generator
    {
        // First, before a run begins. A generator runs nothing until it is
        // iterated, so this refuses on the first iteration rather than at the
        // call, which is still before any run state exists.
        $attachments = TurnAttachments::admit($prompt, $additionalContent);

        // THE SESSION LOCK, held for the whole iteration — every path below
        // (plain, step tree, capped) runs inside it, as send() runs inside
        // fencedLock(). Taken before any run state or provider call, so a
        // session another request is running refuses with SessionLocked and
        // nothing else happens.
        $release = $session->holdFencedLock($this->integerConfig('lock_ttl', 300, $session), $this->integerConfig('lock_wait', 0, $session));
        try {
            // Iterated rather than delegated with `yield from`: when the
            // consumer abandons the stream, the loop's iterator is freed before
            // this `finally` runs, so the run is closed out — its state written
            // under the fence — BEFORE the lock is released. Keys pass through.
            foreach ($this->lockedStream($session, $prompt, $toolNames, $context, $attachments, $tagger) as $key => $event) {
                yield $key => $event;
            }
        } finally {
            $release();
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     * @return Generator<int, StreamEvent>
     */
    private function lockedStream(Session $session, string $prompt, ?array $toolNames, ?RunContext $context, array $attachments, RunStepTagger $tagger): Generator
    {

        if (($context === null ? $this->floatConfig('max_cost_usd') : $context->budget->maxCostUsd) !== null) {
            yield from $this->streamCapped($session, $prompt, $toolNames, $context, $attachments, $tagger);

            return;
        }

        if ($this->observerFor($context) !== null) {
            throw RunNotPermitted::callBudget('budget_observer_uncapped');
        }

        $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());
        if ($context !== null || $mode->subagents !== []) {
            yield from $this->streamStepTree($session, $prompt, $toolNames, $context, $attachments, $tagger);

            return;
        }
        $provider = $session->provider() ?? $this->stringConfig('provider', $session);
        $model = $session->model() ?? $this->stringConfig('model', $session);
        $runId = $tagger->claimRunId();
        $tagger->bind($session->key(), $runId, null, null);
        $tagger->history(fn (): iterable => $session->thread()->messages());

        $namedRun = $session->agent() === null ? null : $this->boundedAgentRun($session, RunContext::root($runId, new RunBudget(
            maxSteps: $this->ceilingFor($session, $mode),
            maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
        )));
        $pausedSteps = $this->pausedSteps($session, $prompt, $context);
        $namedRun?->ledger->recordSteps($pausedSteps);
        if ($namedRun !== null && ($stop = $namedRun->ledger->exhaustion($namedRun->budget)) !== null) {
            throw RunNotPermitted::exhausted($stop);
        }
        if ($namedRun === null && $pausedSteps >= $mode->maxSteps) {
            throw RunNotPermitted::exhausted(sprintf('step budget exhausted (%d of %d used)', $pausedSteps, $mode->maxSteps));
        }

        $session->beginRun($runId, $mode->name, $provider, $model);

        event(new RunStarted(
            sessionKey: $session->key(),
            runId: $runId,
            mode: $mode->name,
            provider: $provider,
            model: $model,
        ));

        // ASSEMBLED BY CORE, NOT REBUILT HERE. See the loop below.
        /** @var array{messages: list<mixed>, response: TextResponse}|null $collected */
        $collected = null;
        $tools = [];
        $finishReason = FinishReason::Unknown;
        $streamStarted = false;
        /** @var list<ToolResult> $resolvedFirst */
        $resolvedFirst = [];

        try {
            $this->endRepeatedIdStep($session, $runId);
            $generation = Prism::text()
                ->using($provider, $model)
                ->withThread($session->thread())
                ->withTelemetryMetadata(sessionId: $session->key())
                ->withPrompt($prompt, $attachments);

            if ($mode->providerOptions !== []) {
                $generation->withProviderOptions($mode->providerOptions);
            }

            $systemPrompt = app(MemoryHooks::class)->systemPrompt($this->skills->augmentPrompt($mode->systemPrompt, $mode->skills), $session, $prompt);
            if ($systemPrompt !== '') {
                $generation->withSystemPrompt($systemPrompt);
            }

            if ($session->agent() !== null) {
                $tools = $this->ownedOffer($mode, $session, $toolNames, $namedRun, $runId)['tools'];
            } else {
                $names = $this->toolNamesFor($mode, $toolNames);
                if ($mode->skills !== []) {
                    $names[] = 'skill_read';
                }
                $resolved = $this->tools->resolve(array_values(array_unique($names)), $session);
                foreach ($resolved as $name => $tool) {
                    if ($mode->needsApproval($name)) {
                        $resolved[$name] = (clone $tool)->requiresApproval(true);
                    }
                }
                $tools = $this->authorizer->allowed($session, $resolved);
            }
            $tools = $this->approvalScopedOffer($session, $tools);
            $tagger->offered($this->toolNames($tools));
            if ($tools !== []) {
                $generation->withTools($tools)->withMaxSteps($namedRun === null ? $mode->maxSteps - $pausedSteps : $this->requestSteps($session, $namedRun));
            }
            $maxOutput = $namedRun === null
                ? $this->nullableIntConfig('max_output_tokens_per_call', $session)
                : $namedRun->budget->maxOutputTokensPerCall;
            if ($maxOutput !== null) {
                $generation->withMaxTokens($maxOutput);
            }

            // The transcript is assembled by CORE, not rebuilt here.
            //
            // An earlier draft accumulated deltas into messages itself, and had
            // to document that the result could differ from what `send()`
            // stores. That difference is invisible by construction: a thread is
            // replayed to a model as context, so a message assembled slightly
            // wrong never surfaces as an error — only, much later, as a model
            // that remembers the conversation differently than it happened.
            // Telling callers to choose between streaming and a faithful record
            // was a workaround for a gap on our side, not a design.
            //
            // `StreamCollector` yields every event through untouched and hands
            // back the same Message objects and Response the non-streaming path
            // builds. A streamed turn and a sent turn now record the SAME
            // transcript, because the same code wrote both.
            $collector = new StreamCollector(
                $generation->asStream(),
                null,
                function (mixed $pending, Collection $messages, TextResponse $response) use (&$collected): void {
                    $collected = ['messages' => $messages->all(), 'response' => $response];
                },
            );

            foreach ($collector->collect() as $event) {
                // Core resolves a resumed run's approvals first, and yields
                // each resolved call's result before the provider's stream
                // starts. Kept, in case the turn ends before core's own
                // record of them arrives (see the finally below).
                if ($event instanceof StreamStartEvent) {
                    $streamStarted = true;
                } elseif (! $streamStarted && $event instanceof ToolResultEvent) {
                    $resolvedFirst[] = $event->toolResult;
                }
                if ($event instanceof ErrorEvent) {
                    $finishReason = FinishReason::Error;
                } elseif ($event instanceof StreamEndEvent && $finishReason !== FinishReason::Error) {
                    $finishReason = $event->finishReason === FinishReason::Error
                        ? FinishReason::Error
                        : ($event->usage === null ? FinishReason::Unknown : $event->finishReason);
                }

                $tagger->observe($event);
                yield $event;
            }
        } catch (Throwable $failure) {
            $session->failRun($runId, $failure::class);

            event(new RunFailed(
                sessionKey: $session->key(),
                runId: $runId,
                exception: $failure::class,
            ));

            throw $failure;
        } finally {
            if ($namedRun !== null && $collected !== null) {
                $namedRun->ledger->recordSteps(count($collected['response']->steps));
                $namedRun->ledger->recordCost($this->costOf($collected['response']));
            }
            // A resumed run's approved calls that core ran before the stream
            // ended without its own record of them (the consumer abandoned
            // the stream, or a tool threw its own exception): their results
            // are recorded, after the prompt as in the request, as one tool
            // result row with only what is new. Otherwise the next resume
            // would run those calls again.
            $resolution = $collected === null && $resolvedFirst !== []
                ? ResolvedApprovals::merged(iterator_to_array($session->thread()->messages(), false), $resolvedFirst)
                : null;
            $resolution = $resolution === null ? [] : [$resolution[count($resolution) - 1]];
            // Reached on completion, on an exception, AND when an abandoned
            // generator is destroyed. Recording whatever arrived beats losing a
            // partial turn: a conversation missing the half the user already
            // watched stream past is the worse outcome.
            if ($session->run()['status'] === 'running') {
                // The user's own turn, then whatever core assembled. A stream
                // abandoned before StreamEnd never fires the collector's
                // callback, so `$collected` is null and only the prompt is
                // recorded — a partial turn kept rather than a whole one lost,
                // and honest about how far it got.
                $messages = [new UserMessage($prompt, $attachments), ...$resolution, ...($collected['messages'] ?? [])];

                $this->recordTurn($session, $messages, $runId, $tools);
                $resolution = [];
                if (in_array($finishReason, [FinishReason::Error, FinishReason::Unknown], true)) {
                    $session->failRun($runId, $finishReason->value);
                    event(new RunFailed($session->key(), $runId, $finishReason->value));
                } else {
                    $session->completeRun($runId, $finishReason->value);
                    event(new RunFinished(
                        sessionKey: $session->key(),
                        runId: $runId,
                        finishReason: $finishReason->value,
                        steps: count($collected['response']->steps ?? []),
                        awaitingApproval: false,
                    ));
                }
            }
            if ($resolution !== []) {
                // The run already failed (a tool's own exception), so no turn
                // was recorded above; the resolved calls' results still are.
                $this->recordTurn($session, $resolution, $runId, $tools);
            }
            $this->rememberSeq($session, $runId, $tagger);
            ToolRefusals::forget($runId);
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     * @return Generator<StreamEvent>
     */
    private function streamStepTree(Session $session, string $prompt, ?array $toolNames, ?RunContext $context, array $attachments, RunStepTagger $tagger): Generator
    {
        $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());
        $provider = $session->provider() ?? $this->stringConfig('provider', $session);
        $model = $session->model() ?? $this->stringConfig('model', $session);
        $runId = $tagger->claimRunId();
        $run = $context ?? RunContext::root($runId, new RunBudget(
            maxSteps: $this->ceilingFor($session, $mode),
            maxSeconds: $this->nullableIntConfig('max_seconds'),
            maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
        ));
        $run = $this->boundedAgentRun($session, $run);
        $this->continuePausedSteps($session, $prompt, $context, $run);
        $tagger->bind($session->key(), $runId, $run->parentRunId, $run->rootRunId());
        $tagger->history(fn (): iterable => $session->thread()->messages());
        $session->beginRun($runId, $mode->name, $provider, $model);
        // A parked run continued under its own id has already STARTED once;
        // a second RunStarted for it would read as a new run.
        event($tagger->resuming()
            ? new RunResumed($session->key(), $runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId())
            : new RunStarted($session->key(), $runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId()));
        $recorded = false;
        $finish = FinishReason::Unknown;
        $steps = 0;
        $awaitingApproval = false;
        $reportedCost = null;
        $completedCall = false;
        try {
            $run = $this->observedRun($run);
            $this->endRepeatedIdStep($session, $runId);
            $history = iterator_to_array($session->thread()->messages(), false);
            $generation = Prism::text()->using($provider, $model)
                ->withMessages($prompt === '' ? $history : [...$history, new UserMessage($prompt, $attachments)])
                ->withTelemetryMetadata(sessionId: $session->key());
            if ($mode->providerOptions !== []) {
                $generation->withProviderOptions($mode->providerOptions);
            }
            $systemPrompt = app(MemoryHooks::class)->systemPrompt($this->skills->augmentPrompt($mode->systemPrompt, $mode->skills), $session, $prompt);
            if ($systemPrompt !== '') {
                $generation->withSystemPrompt($systemPrompt);
            }
            $tools = $this->authorizedTools($mode, $session, $toolNames, $run, $runId);
            $tagger->offered($this->toolNames($tools));
            if ($tools !== []) {
                $generation->withTools($tools);
            }
            if ($run->budget->maxOutputTokensPerCall !== null) {
                $generation->withMaxTokens($run->budget->maxOutputTokensPerCall);
            }
            yield from $this->observed($tagger, (new StepCalls)->stream($generation, $run,
                function (array $messages, TextResponse $response) use ($session, $prompt, $attachments, $runId, $tools, &$recorded, &$finish, &$steps, &$awaitingApproval, &$reportedCost, &$completedCall): void {
                    $this->recordTurn($session, [
                        ...(! $recorded && $prompt !== '' ? [new UserMessage($prompt, $attachments)] : []), ...$messages,
                    ], $runId, $tools);
                    $recorded = true;
                    $completedCall = true;
                    if (($cost = $this->costOf($response)) !== null) {
                        $reportedCost = ($reportedCost ?? 0.0) + $cost;
                    }
                    $finish = $response->finishReason;
                    $steps++;
                    foreach ($messages as $message) {
                        if ($message instanceof AssistantMessage && $message->toolApprovalRequests !== []) {
                            $awaitingApproval = true;
                        }
                    }
                }, function (array $messages) use ($session, &$history, $runId, $tools, &$recorded): void {
                    $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                    $history = $messages;
                    $recorded = true;
                }));
        } catch (Throwable $failure) {
            $session->failRun($runId, $failure instanceof RunNotPermitted ? 'budget' : $failure::class);
            event(new RunFailed($session->key(), $runId, $failure::class, $run->parentRunId, $run->rootRunId()));

            throw $failure;
        } finally {
            if ($completedCall) {
                $run->ledger->recordCost($reportedCost);
            }
            if (($session->run()['status'] ?? null) === 'running') {
                if (! $recorded && $prompt !== '') {
                    $session->thread()->record([new UserMessage($prompt, $attachments)], $runId);
                }
                if (in_array($finish, [FinishReason::Error, FinishReason::Unknown], true)) {
                    $session->failRun($runId, $finish->value);
                    event(new RunFailed($session->key(), $runId, $finish->value, $run->parentRunId, $run->rootRunId()));
                } else {
                    $session->completeRun($runId, $finish->value);
                    event(new RunFinished($session->key(), $runId, $finish->value, $steps, $awaitingApproval,
                        $run->parentRunId, $run->rootRunId()));
                }
            }
            $this->rememberSeq($session, $runId, $tagger);
            ToolRefusals::forget($runId);
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $attachments
     * @return Generator<int, StreamEvent>
     */
    private function streamCapped(Session $session, string $prompt, ?array $toolNames, ?RunContext $context, array $attachments, RunStepTagger $tagger): Generator
    {
        $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());
        $provider = $session->provider() ?? $this->stringConfig('provider', $session);
        $model = $session->model() ?? $this->stringConfig('model', $session);
        $runId = $tagger->claimRunId();
        $run = $context ?? RunContext::root($runId, new RunBudget(
            maxSteps: $this->ceilingFor($session, $mode),
            maxCostUsd: $this->floatConfig('max_cost_usd'),
            maxSeconds: $this->nullableIntConfig('max_seconds'),
            maxOutputTokensPerCall: $this->nullableIntConfig('max_output_tokens_per_call', $session),
        ));
        $run = $this->boundedAgentRun($session, $run);
        $this->continuePausedSteps($session, $prompt, $context, $run);
        $tagger->bind($session->key(), $runId, $run->parentRunId, $run->rootRunId());
        $tagger->history(fn (): iterable => $session->thread()->messages());
        $session->beginRun($runId, $mode->name, $provider, $model);
        // A parked run continued under its own id has already STARTED once;
        // a second RunStarted for it would read as a new run.
        event($tagger->resuming()
            ? new RunResumed($session->key(), $runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId())
            : new RunStarted($session->key(), $runId, $mode->name, $provider, $model, $run->parentRunId, $run->rootRunId()));
        $recorded = false;
        $finish = FinishReason::Unknown;
        $steps = 0;
        $awaitingApproval = false;
        try {
            $run = $this->observedRun($run);
            $this->assertMeteredCompaction($run->budget);
            $this->endRepeatedIdStep($session, $runId);
            $history = iterator_to_array($session->thread()->messages(), false);
            $generation = Prism::text()->using($provider, $model)
                ->withMessages($prompt === '' ? $history : [...$history, new UserMessage($prompt, $attachments)])
                ->withTelemetryMetadata(sessionId: $session->key());
            if ($mode->providerOptions !== []) {
                $generation->withProviderOptions($mode->providerOptions);
            }
            $systemPrompt = app(MemoryHooks::class)->systemPrompt($this->skills->augmentPrompt($mode->systemPrompt, $mode->skills), $session, $prompt);
            if ($systemPrompt !== '') {
                $generation->withSystemPrompt($systemPrompt);
            }
            $tools = $this->authorizedTools($mode, $session, $toolNames, $run, $runId);
            $tagger->offered($this->toolNames($tools));
            if ($tools !== []) {
                $generation->withTools($tools);
            }
            yield from $this->observed($tagger, $this->budgetedCalls($run, $provider, $model)->stream($generation, $run,
                function (array $messages, TextResponse $response) use ($session, $prompt, $attachments, $runId, $tools, &$recorded, &$finish, &$steps, &$awaitingApproval): void {
                    $this->recordTurn($session, [
                        ...(! $recorded && $prompt !== '' ? [new UserMessage($prompt, $attachments)] : []), ...$messages,
                    ], $runId, $tools);
                    $recorded = true;
                    $finish = $response->finishReason;
                    $steps++;
                    foreach ($messages as $message) {
                        if ($message instanceof AssistantMessage && $message->toolApprovalRequests !== []) {
                            $awaitingApproval = true;
                        }
                    }
                }, function (array $messages) use ($session, &$history, $runId, $tools, &$recorded): void {
                    $this->recordTurn($session, $this->newMessageObjects($messages, $history), $runId, $tools);
                    $history = $messages;
                    $recorded = true;
                }));
        } catch (Throwable $failure) {
            $session->failRun($runId, $failure instanceof RunNotPermitted ? 'budget' : $failure::class);
            event(new RunFailed($session->key(), $runId, $failure::class, $run->parentRunId, $run->rootRunId()));

            throw $failure;
        } finally {
            if (($session->run()['status'] ?? null) === 'running') {
                if (! $recorded && $prompt !== '') {
                    $session->thread()->record([new UserMessage($prompt, $attachments)], $runId);
                }
                if (in_array($finish, [FinishReason::Error, FinishReason::Unknown], true)) {
                    $session->failRun($runId, $finish->value);
                    event(new RunFailed($session->key(), $runId, $finish->value, $run->parentRunId, $run->rootRunId()));
                } else {
                    $session->completeRun($runId, $finish->value);
                    event(new RunFinished($session->key(), $runId, $finish->value, $steps, $awaitingApproval,
                        $run->parentRunId, $run->rootRunId()));
                }
            }
            $this->rememberSeq($session, $runId, $tagger);
            ToolRefusals::forget($runId);
        }
    }

    /**
     * The context a run EXECUTES under.
     *
     * A context carrying only a turnId and/or an actor is metadata: the run
     * takes exactly the path, and the budget, it would take with no context at
     * all. Only a context that states a run tree changes how a run executes.
     */
    private function executionContext(?RunContext $context): ?RunContext
    {
        return $context?->metadataOnly === true ? null : $context;
    }

    /**
     * Pass core's events through UNCHANGED, keys included, observing each.
     *
     * @param  iterable<array-key, StreamEvent>  $events
     * @return Generator<array-key, StreamEvent>
     */
    private function observed(RunStepTagger $tagger, iterable $events): Generator
    {
        foreach ($events as $key => $event) {
            $tagger->observe($event);
            yield $key => $event;
        }
    }

    /**
     * @param  list<Tool>  $tools
     * @return list<string>
     */
    private function toolNames(array $tools): array
    {
        return array_map(fn (Tool $tool): string => $tool->name(), $tools);
    }

    /**
     * Where this run's step sequence ended, so a resumed run continues it.
     *
     * Last, and isolated: the run is already closed out, and failing to note
     * a sequence number must not turn a finished run into a failed one.
     */
    private function rememberSeq(Session $session, string $runId, RunStepTagger $tagger): void
    {
        if ($tagger->seq() === 0) {
            return;
        }
        try {
            $session->rememberStepSeq($runId, $tagger->seq());
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    private function budgetedCalls(RunContext $run, string $provider, string $model): BudgetedCalls
    {
        return new BudgetedCalls(CallBudget::forRun($run, $provider, $model, $this->pricing));
    }

    private function observerFor(?RunContext $run): ?CallCostObserver
    {
        return $run->observer ?? $run?->ledger->inheritCostObserver(null)
            ?? ($this->costObserver === null ? null : ($this->costObserver)());
    }

    private function observedRun(RunContext $run): RunContext
    {
        $observer = $this->observerFor($run);
        if ($observer === null) {
            return $run;
        }
        if ($run->budget->maxCostUsd === null) {
            throw RunNotPermitted::callBudget('budget_observer_uncapped');
        }

        return $run->observer !== null ? $run : new RunContext($run->ledger, $run->budget,
            $run->parentRunId, $run->parentThreadId, $run->depth, $run->pricing, $observer, $run->turnId, $run->actor);
    }

    private function assertMeteredCompaction(RunBudget $budget): void
    {
        if ($budget->maxCostUsd !== null && app(CompactionStrategy::class) instanceof SummarisingCompaction) {
            throw RunNotPermitted::callBudget('budget_compaction_unmetered');
        }
    }

    /**
     * The tools this run may actually offer the model, resolved and authorized.
     *
     * One method because every turn shape needs the same four steps in the same
     * order, and a second copy of them is how a text turn and a structured turn
     * end up with different authority for the same mode.
     *
     * @return list<array{name: string, owner: string, source: string}>
     */
    public function effectiveTools(Session $session, ?string $modeName = null): array
    {
        $mode = $this->modes->forAgent($session->agent())->resolve($modeName ?? $session->mode());

        return $this->ownedOffer($mode, $session, null, inspection: true)['descriptors'];
    }

    /**
     * What a workflow may offer the model for this session: each tool's name,
     * description and schema, with nothing that can execute.
     *
     * @internal Read through {@see FlowToolset}.
     *
     * @param  list<string>|null  $toolNames  narrows the mode, and cannot widen it
     * @return list<Tool>
     */
    public function flowOffer(Session $session, ?array $toolNames = null): array
    {
        return array_map(static function (array $entry): Tool {
            $tool = $entry['tool'];
            $copy = (new Tool)->as($tool->name())->for($tool->description())->clientExecuted();
            foreach ($tool->parameters() as $name => $schema) {
                $copy->withParameter($schema, in_array($name, $tool->requiredParameters(), true));
            }
            $options = $tool->providerOptions();
            if (is_array($options)) {
                $copy->withProviderOptions($options);
            }

            return $copy;
        }, array_values($this->flowEntries($session, $toolNames)));
    }

    /**
     * Decide one model step as a whole, before any of its calls is handed over.
     *
     * @internal Called through {@see FlowToolset}.
     *
     * @param  list<string>|null  $toolNames
     * @param  list<string>  $offered  what this request offered the model
     * @param  list<array{name: string, id: string, arguments: array<array-key, mixed>|null, callIds?: list<string>}>  $calls
     *
     * @throws ToolNotAvailable when any call in the step may not run
     */
    public function admitFlowCalls(Session $session, ?array $toolNames, array $offered, array $calls): void
    {
        $entries = $this->flowEntries($session, $toolNames);
        $ids = [];
        $admitted = [];

        foreach ($calls as $call) {
            $name = $call['name'];
            // Only an id the provider issued can repeat. A call without one is
            // distinct, and every check below still applies to it.
            foreach ($call['callIds'] ?? [] as $callId) {
                if (isset($ids[$callId])) {
                    throw ToolNotAvailable::repeatedCall($name);
                }
            }
            foreach ($call['callIds'] ?? [] as $callId) {
                $ids[$callId] = true;
            }
            if (! in_array($name, $offered, true) || ! isset($entries[$name])) {
                throw ToolNotAvailable::named($name, $offered);
            }
            if ($call['arguments'] === null) {
                throw ToolNotAvailable::unreadableArguments($name);
            }
            $admitted[] = [$entries[$name], $call['arguments']];
        }

        $refusal = null;
        foreach ($admitted as [$entry, $arguments]) {
            $refused = $this->flowRefusal($session, $entry, $arguments);
            $refusal ??= $refused;
        }

        if ($refusal instanceof ToolNotAvailable) {
            throw $refusal;
        }
    }

    /**
     * Run one workflow tool call, deciding it again at the moment it runs.
     *
     * @internal Called through {@see FlowToolset}.
     *
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $arguments
     * @param  (callable(): void)|null  $starting  called once the call is allowed, just before its handler
     *
     * @throws ToolNotAvailable when the call may not run
     */
    public function runFlowTool(Session $session, ?array $toolNames, string $name, array $arguments, ?callable $starting = null): mixed
    {
        // The decision and the handler both run inside the session lock.
        return $this->flowLocked(
            $session,
            fn (Session $session): mixed => $this->runFlowToolLocked($session, $toolNames, $name, $arguments, $starting),
            static fn (SessionLocked $locked): ToolNotAvailable => ToolNotAvailable::sessionInUse($name, $locked),
        );
    }

    /**
     * Run a workflow's use of the session inside the lock an ordinary turn
     * holds, with the same timeout. While another holder has it the callback
     * does not run and the refusal is thrown instead; nothing waits beyond the
     * configured lock_wait. The lock is released on every exit.
     *
     * @internal Called by {@see runFlowTool()} and {@see PrismLlmClient}.
     *
     * @template TReturn
     *
     * @param  Closure(Session): TReturn  $callback
     * @param  Closure(SessionLocked): ToolNotAvailable  $refusal
     * @return TReturn
     */
    public function flowLocked(Session $session, Closure $callback, Closure $refusal): mixed
    {
        $entered = false;

        try {
            return $session->fencedLock(function (Session $session) use ($callback, &$entered): mixed {
                $entered = true;

                return $callback($session);
            }, ttlSeconds: $this->integerConfig('lock_ttl', 300, $session), waitSeconds: $this->integerConfig('lock_wait', 0, $session));
        } catch (SessionLocked $locked) {
            if ($entered) {
                // Thrown from inside the callback, not by taking this lock.
                throw $locked;
            }

            throw $refusal($locked);
        }
    }

    /**
     * @param  list<string>|null  $toolNames
     * @param  array<array-key, mixed>  $arguments
     * @param  (callable(): void)|null  $starting
     */
    private function runFlowToolLocked(Session $session, ?array $toolNames, string $name, array $arguments, ?callable $starting): mixed
    {
        $entries = $this->flowEntries($session, $toolNames);

        if (! isset($entries[$name])) {
            throw ToolNotAvailable::named($name, array_keys($entries));
        }

        $refusal = $this->flowRefusal($session, $entries[$name], $arguments);

        if ($refusal instanceof ToolNotAvailable) {
            throw $refusal;
        }

        if ($starting !== null) {
            $starting();
        }

        return $entries[$name]['tool']->handle(...$arguments);
    }

    /**
     * The most model steps one workflow loop may take on this session.
     *
     * @internal Called through {@see FlowToolset}.
     */
    public function flowStepLimit(Session $session): int
    {
        $session->forgetCachedState();

        return $this->ceilingFor($session, $this->modes->forAgent($session->agent())->resolve($session->mode()));
    }

    /**
     * The tools a workflow may run right now, read fresh from the session's
     * store. Subagents are left out: a subagent call spends from a run budget,
     * and a workflow node has no run to charge.
     *
     * @param  list<string>|null  $toolNames
     * @return array<string, array{tool: Tool, gated: bool}>
     */
    private function flowEntries(Session $session, ?array $toolNames): array
    {
        $session->forgetCachedState();
        $mode = $this->modes->forAgent($session->agent())->resolve($session->mode());

        [$resolved, $offered] = $session->resolveReadOnly($mode->name, function (Session $view) use ($mode, $toolNames): array {
            $wantsSkills = $toolNames === null || in_array('skill_read', $toolNames, true);
            $names = $this->toolNamesFor($mode, $toolNames);
            $additional = [];
            if ($view->agent() === null && $mode->skills !== [] && $wantsSkills) {
                $names[] = 'skill_read';
            }
            if ($view->agent() !== null && $wantsSkills && in_array('skill_read', $mode->tools, true)) {
                $additional['skill_read'] = ['tool' => $this->skills->readerTool($mode->skills), 'owner' => 'harness', 'source' => 'builtin'];
            }
            $subagents = array_map(static fn ($subagent): string => $subagent->name, array_values($mode->subagents));
            $detailed = $this->tools->resolveDetailed(array_values(array_unique(array_diff($names, $subagents))), $view, $additional);
            $resolved = [];
            foreach (array_diff_key($detailed, array_flip($subagents)) as $name => $entry) {
                $resolved[$name] = $entry['tool'];
            }
            if ($view->agent() !== null && isset($resolved['complete_task'])) {
                $authorizer = $this->authorizer->forSession($view);
                if (! $authorizer->enabled() || ! $authorizer->hasCallPolicy()) {
                    throw new \LogicException('Named task completion requires enabled authorization and an explicit call policy.');
                }
            }

            return [$resolved, $this->authorizer->allowed($view, $resolved)];
        });

        $entries = [];
        foreach ($this->approvalScopedOffer($session, $offered) as $tool) {
            $name = $tool->name();
            $entries[$name] = ['tool' => $resolved[$name], 'gated' => $mode->needsApproval($name)];
        }

        return $entries;
    }

    /**
     * Why this call may not run, or null. Records each refusal.
     *
     * @param  array{tool: Tool, gated: bool}  $entry
     * @param  array<array-key, mixed>  $arguments
     */
    private function flowRefusal(Session $session, array $entry, array $arguments): ?ToolNotAvailable
    {
        $tool = $entry['tool'];

        foreach (array_keys($arguments) as $key) {
            if (is_int($key) || preg_match('/\A[+-]?[0-9]+\z/', $key) === 1) {
                return $this->recordFlowRefusal($session, $tool, $arguments, 'arguments', ToolNotAvailable::positionalArguments($tool->name()));
            }
        }

        try {
            if ($tool->needsApproval($arguments)) {
                return $this->recordFlowRefusal($session, $tool, $arguments, 'approval', ToolNotAvailable::awaitingApproval($tool->name()));
            }
            if (! $this->authorizer->allowsCall($session, $tool, $arguments)) {
                return $this->recordFlowRefusal($session, $tool, $arguments, 'authorization', ToolNotAvailable::refusedByPolicy($tool->name()));
            }
            // Read last, so a change made while the checks above ran still counts.
            if ($entry['gated']) {
                return $this->recordFlowRefusal($session, $tool, $arguments, 'approval', ToolNotAvailable::awaitingApproval($tool->name()));
            }
        } catch (Throwable $failure) {
            return $this->recordFlowRefusal($session, $tool, $arguments, 'check_failed', ToolNotAvailable::failed($tool->name(), $failure));
        }

        return null;
    }

    /** @param array<array-key, mixed> $arguments */
    private function recordFlowRefusal(Session $session, Tool $tool, array $arguments, string $reason, ToolNotAvailable $refusal): ToolNotAvailable
    {
        event(new FlowToolRefused($session->key(), self::FLOW_RUN, $tool->name(), $reason));

        return $refusal;
    }

    /**
     * @param  list<string>|null  $requested
     * @return array{tools: list<Tool>, descriptors: list<array{name: string, owner: string, source: string}>}
     */
    private function ownedOffer(AgentMode $mode, Session $session, ?array $requested, ?RunContext $run = null, string $runId = 'inspection', bool $inspection = false): array
    {
        return $session->resolveReadOnly($mode->name, function (Session $view) use ($mode, $requested, $run, $runId, $inspection): array {
            $names = $this->toolNamesFor($mode, $requested, $view->agent() !== null);
            if ($view->agent() === null && $mode->skills !== []) {
                $names[] = 'skill_read';
            }
            $additional = [];
            if ($view->agent() !== null && in_array('skill_read', $mode->tools, true)) {
                $additional['skill_read'] = ['tool' => $this->skills->readerTool($mode->skills), 'owner' => 'harness', 'source' => 'builtin'];
            }
            foreach ($mode->subagents as $subagent) {
                if ($view->agent() !== null && ! in_array($subagent->name, $mode->tools, true)) {
                    continue;
                }
                if ($this->subagentRunner === null) {
                    throw new \LogicException('No Harness subagent runner is configured.');
                }
                if (! $inspection && $run === null) {
                    throw new \LogicException('Named subagent streaming requires a RunContext.');
                }
                if ($view->agent() === null) {
                    $names[] = $subagent->name;
                }
                $additional[$subagent->name] = ['tool' => ($this->subagentRunner)()->tool($subagent, $view, $run, $runId), 'owner' => 'harness.subagent', 'source' => 'builtin'];
            }
            $detailed = $this->tools->resolveDetailed($names, $view, $additional);
            $resolved = [];
            foreach ($detailed as $name => $entry) {
                $resolved[$name] = $mode->needsApproval($name) ? (clone $entry['tool'])->requiresApproval(true) : $entry['tool'];
            }
            if ($view->agent() !== null && isset($resolved['complete_task'])) {
                $authorizer = $this->authorizer->forSession($view);
                if (! $authorizer->enabled() || ! $authorizer->hasCallPolicy()) {
                    throw new \LogicException('Named task completion requires enabled authorization and an explicit call policy.');
                }
            }
            $offered = $this->authorizer->allowed($view, $resolved);
            $descriptors = [];
            foreach ($offered as $tool) {
                $descriptors[] = ['name' => $tool->name(), 'owner' => $detailed[$tool->name()]['owner'], 'source' => $detailed[$tool->name()]['source']];
            }

            return ['tools' => $offered, 'descriptors' => $descriptors];
        });
    }

    /**
     * @param  list<string>|null  $toolNames
     * @return list<Tool>
     */
    private function authorizedTools(AgentMode $mode, Session $session, ?array $toolNames, RunContext $run, string $runId): array
    {
        if ($session->agent() !== null) {
            return $this->approvalScopedOffer($session, $this->ownedOffer($mode, $session, $toolNames, $run, $runId)['tools']);
        }
        $names = $this->toolNamesFor($mode, $toolNames);
        if ($mode->skills !== []) {
            $names[] = 'skill_read';
        }
        $resolved = $this->tools->resolve(array_values(array_unique($names)), $session);

        // Subagents are authority, so they go through the SAME authorization
        // pass as any other tool rather than around it.
        foreach ($this->subagentTools($mode, $session, $run, $runId) as $name => $tool) {
            $resolved[$name] = $tool;
        }

        // Marked BEFORE authorization, so an approval-gated tool that the policy
        // then removes never reaches the model at all — rather than being
        // offered and stopping the run on a request nobody can grant.
        foreach ($resolved as $name => $tool) {
            if ($mode->needsApproval($name)) {
                $resolved[$name] = (clone $tool)->requiresApproval(true);
            }
        }

        return $this->approvalScopedOffer($session, $this->authorizer->allowed($session, $resolved));
    }

    /**
     * @param  list<Tool>  $tools
     * @return list<Tool>
     */
    private function approvalScopedOffer(Session $session, array $tools): array
    {
        $scope = $session->thread()->approvalToolNames();

        // Intersect AFTER resolving and authorizing the current mode, including
        // builtins. A changed mode or registry cannot expand the stored offer.
        return $scope === null ? $tools : array_values(array_filter($tools,
            static fn (Tool $tool): bool => in_array($tool->name(), $scope, true)));
    }

    /**
     * @param  iterable<Message>  $messages
     * @param  list<Tool>  $tools
     */
    private function recordTurn(Session $session, iterable $messages, string $runId, array $tools): void
    {
        // Materialised once: the thread and the observers both read it.
        $messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        $session->thread()->record($messages, $runId, array_map(static fn (Tool $tool): string => $tool->name(), $tools));
        // AFTER the durable record, so an observer can never lose a turn the
        // thread kept, and its failure (isolated in MemoryHooks) cannot undo one.
        app(MemoryHooks::class)->recorded($session, $runId, $messages);
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
    private function toolNamesFor(AgentMode $mode, ?array $requested, bool $strict = false): array
    {
        $ceiling = $mode->tools;

        if ($requested === null || in_array('*', $requested, true)) {
            return $ceiling;
        }

        if (in_array('*', $ceiling, true)) {
            return $requested;
        }

        if ($strict && array_diff($requested, $ceiling) !== []) {
            throw new \LogicException('A requested tool is not available in the active agent mode.');
        }

        return array_values(array_intersect($requested, $ceiling));
    }

    /**
     * The step ceiling for THIS run: the mode's default unless the caller asked
     * for something else, and never more than the operator allows.
     *
     * A mode's constant is a sensible default, not a fact about every task run
     * in that mode. A caller holding a frozen, approved budget knows the size
     * of the job better than the config does — and when the two disagree the
     * agent is told one number and cut off at another, which discards work
     * rather than bounding it.
     *
     * `max_steps_ceiling` is the operator's word and outranks both. Without it
     * this method would let any caller lift its own limit, which is the exact
     * shape `RunBudget::nestedWithin()` refuses for subagents.
     */
    private function ceilingFor(Session $session, AgentMode $mode): int
    {
        if ($session->agent() !== null) {
            $requested = $session->maxSteps() ?? $mode->maxSteps;
            $ceiling = $this->nullableIntConfig('max_steps_ceiling', $session);

            return $ceiling === null ? $requested : min($requested, $ceiling);
        }
        $requested = $session->maxSteps();

        if ($requested === null) {
            return $mode->maxSteps;
        }

        $ceiling = $this->nullableIntConfig('max_steps_ceiling');

        return $ceiling === null ? $requested : min($requested, $ceiling);
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
     * What this turn ADDED to the conversation. That is not `$response->messages`.
     *
     * A real provider handler builds `$response->messages` from the request it
     * sent, and the request carries the history. Recording that list whole
     * re-appended the entire conversation on every turn, so a thread doubled
     * each time. The Lab's commentary thread reached 2,046 rows holding 20
     * distinct messages after ten runs, 2^11 - 2 exactly. It went unseen for
     * every release from v0.2.0 on because `Prism::fake()` returns only the
     * messages a test gives it, and every test gave it one exchange.
     *
     * The history is dropped by IDENTITY. It is exactly the objects this run
     * handed to Prism, and a handler that echoes them returns those same
     * instances, so every message that is not one of them is new. A response
     * with none of them (a fake, or a handler that returns only the new
     * exchange) is recorded whole.
     *
     * By identity, not as a prefix. Resuming an approval, Prism removes the
     * tool result messages after the assistant and appends one merged message,
     * so the response no longer BEGINS with the history. Matched as a prefix,
     * that recorded the whole conversation again, the doubling this method
     * exists to prevent, on the approval path only.
     *
     * @param  list<Message>  $history
     * @return list<Message>
     */
    private function newMessages(TextResponse $response, array $history): array
    {
        return $this->newMessageObjects($response->messages->values()->all(), $history);
    }

    /**
     * A structured turn's transcript, with the result of the approvals core
     * resolved inside the turn.
     *
     * When a structured turn resumes approved calls, core runs them inside
     * its generation and merges their results into the messages it sends (the
     * first step's messages), not into the steps the transcript is rebuilt
     * from. Without that message the record lost the results, and the next
     * resume ran the approved calls again. It goes right after the prompt,
     * where core put it in the request.
     *
     * @param  list<Message>  $history
     * @return list<Message>
     */
    private function resolvedFirstInTranscript(StructuredResponse $response, ?UserMessage $prompt, array $history): array
    {
        $transcript = StructuredTranscript::of($response, $prompt);
        $first = $response->steps->first();
        if (! $first instanceof StructuredStep) {
            return $transcript;
        }
        // The resolution's merged message is the one core builds with the
        // approval decisions on it; the step's own tool results carry none.
        $resolution = array_values(array_filter(
            $this->newMessageObjects(array_values($first->messages), $prompt instanceof UserMessage ? [...$history, $prompt] : $history),
            fn (Message $message): bool => $message instanceof ToolResultMessage && $message->toolApprovalResponses !== [],
        ));
        if ($resolution === []) {
            return $transcript;
        }
        $at = $prompt instanceof UserMessage ? 1 : 0;

        return [...array_slice($transcript, 0, $at), ...$resolution, ...array_slice($transcript, $at)];
    }

    /**
     * A plain send() or structured turn, with the results of the approved
     * calls core resolved inside it recorded when the turn throws.
     *
     * Core keeps those results until the generation returns, so a turn that
     * throws after they ran (a tool built withoutErrorHandling(), a provider
     * failure on a later step) lost them, and the next resume ran the calls
     * again. They are recorded before the failure goes on, as one tool result
     * row with only what is new; a failure to record is reported, never put
     * in place of the turn's own failure.
     *
     * When an approved call's tool cannot be watched (see ObservedTool), the
     * approvals are resolved here first, before the turn, and recorded at
     * once, as on the step-by-step paths; their telemetry then follows those
     * paths too (no ToolInvoked unless the application has a telemetry
     * context open).
     *
     * @template T
     *
     * @param  Closure(list<Message>): void  $resolved  records the messages a resolution here produced
     * @param  list<Message>  $history
     * @param  list<Tool>  $tools
     * @param  Closure(): T  $generate
     * @return T
     */
    private function recordingResolution(?ResolutionRecorder $recorder, bool $watching, TextPendingRequest|StructuredPendingRequest $generation, Closure $resolved, Session $session, array $history, string $runId, array $tools, Closure $generate): mixed
    {
        if ($recorder !== null && ! $watching) {
            (new ApprovedTools)->resolve($generation, $resolved);
            $recorder = null;
        }
        try {
            return $generate();
        } catch (Throwable $failure) {
            $resolved = $recorder === null ? null : ResolvedApprovals::merged($history, $recorder->results());
            if ($resolved !== null) {
                try {
                    $this->recordTurn($session, $this->newMessageObjects($resolved, $history), $runId, $tools);
                } catch (Throwable $unrecorded) {
                    report($unrecorded);
                }
            }

            throw $failure;
        }
    }

    /** @param list<Message> $messages
     * @param  list<Message>  $history
     * @return list<Message>
     */
    private function newMessageObjects(array $messages, array $history): array
    {
        $sent = [];

        foreach ($history as $message) {
            $sent[spl_object_id($message)] = true;
        }

        return array_values(array_filter(
            $messages,
            fn (Message $message): bool => ! isset($sent[spl_object_id($message)]),
        ));
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

    private function nullableIntConfig(string $key, ?Session $session = null): ?int
    {
        $value = $session === null ? ($this->config[$key] ?? null) : $this->agentValue($session, $key);

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

    private function stringConfig(string $key, ?Session $session = null): string
    {
        $value = $session === null ? ($this->config[$key] ?? null) : $this->agentValue($session, $key);
        if (! is_string($value) || $value === '') {
            throw new \RuntimeException(sprintf('Harness runtime [%s] is not configured.', $key));
        }

        return $value;
    }

    private function integerConfig(string $key, int $default, ?Session $session = null): int
    {
        $value = $session === null ? ($this->config[$key] ?? $default) : $this->agentValue($session, $key);

        return is_int($value) && $value >= 0 ? $value : $default;
    }

    private function agentValue(Session $session, string $key): mixed
    {
        $options = $session->agent() === null ? [] : ($this->agents[$session->agent()] ?? []);

        return is_array($options) && array_key_exists($key, $options) ? $options[$key] : ($this->config[$key] ?? null);
    }

    /**
     * Steps the paused run has already taken, when this turn resumes it.
     *
     * A resume (an empty prompt with an approval waiting at the tail) is the
     * same run, so it continues that run's step count and never opens a new
     * allowance. A new prompt is a new run. A child run is left alone: its
     * steps are already on the tree ledger its caller passes back in.
     */
    private function pausedSteps(Session $session, string $prompt, ?RunContext $context): int
    {
        if ($prompt !== '' || $context !== null) {
            return 0;
        }

        return $session->thread()->approvalRunSteps() ?? 0;
    }

    /**
     * Before a turn reads the conversation: a paused step whose call ids
     * repeat gives each of its calls still without a result the G-392 denial
     * as its result, matched by position, so none of them runs and each has
     * exactly one outcome (see {@see Thread::endRepeatedIdStep()}).
     */
    private function endRepeatedIdStep(Session $session, string $runId): void
    {
        $session->thread()->endRepeatedIdStep(Session::REPEATED_CALL_ID_REASON, $runId);
    }

    private function continuePausedSteps(Session $session, string $prompt, ?RunContext $context, RunContext $run): void
    {
        $run->ledger->recordSteps($this->pausedSteps($session, $prompt, $context));
    }

    private function requestSteps(Session $session, RunContext $run): int
    {
        return max(0, $run->budget->maxSteps - $run->ledger->steps());
    }

    private function boundedAgentRun(Session $session, RunContext $run): RunContext
    {
        if ($session->agent() === null) {
            return $run;
        }
        $steps = $this->nullableIntConfig('max_steps_ceiling', $session);
        $output = $this->nullableIntConfig('max_output_tokens_per_call', $session);
        $budget = $run->budget;

        return new RunContext(
            ledger: $run->ledger,
            budget: new RunBudget(
                maxSteps: $steps === null ? $budget->maxSteps : min($budget->maxSteps, $run->ledger->steps() + $steps),
                maxCostUsd: $budget->maxCostUsd,
                maxSeconds: $budget->maxSeconds,
                maxOutputTokensPerCall: $output === null ? $budget->maxOutputTokensPerCall : min($budget->maxOutputTokensPerCall ?? $output, $output),
            ),
            parentRunId: $run->parentRunId, parentThreadId: $run->parentThreadId,
            depth: $run->depth, pricing: $run->pricing, observer: $run->observer,
            turnId: $run->turnId, actor: $run->actor,
        );
    }
}
