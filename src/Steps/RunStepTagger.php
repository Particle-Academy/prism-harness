<?php

declare(strict_types=1);

namespace Prism\Harness\Steps;

use Closure;
use Illuminate\Support\Carbon;
use Prism\Harness\Events\RunStepped;
use Prism\Harness\Plans\PlanMachine;
use Prism\Harness\Plans\PlanView;
use Prism\Harness\Support\ToolCallArguments;
use Prism\Harness\Tools\ToolRefusals;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StepStartEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Throwable;

/**
 * Turns the core events a run observes into tagged {@see RunStep}s.
 *
 * One per streamed run: it owns the run's `seq`, so a step is numbered by the
 * run it belongs to and never by whoever happens to be listening. Every step is
 * dispatched as {@see RunStepped}; when the caller asked for steps rather than
 * core events, they are also buffered for it to drain.
 *
 * @internal Built by AgentRuntime; the shape it produces is the contract, not this class.
 */
final class RunStepTagger
{
    private ?string $sessionKey = null;

    private ?string $harnessAgent = null;

    private ?string $runId;

    private ?string $parentRunId = null;

    private ?string $rootRunId = null;

    /** @var array<string, int> hrtime at which each tool call was observed */
    private array $calls = [];

    private int $lastObservedAt;

    private string $stepText = '';

    /** @var list<RunStep> */
    private array $buffer = [];

    /** @var (Closure(): ?array<string, mixed>)|null reads the session's plan, when plan steps are on (G-360) */
    private ?Closure $planSource = null;

    private bool $planStarted = false;

    /** @var list<array<string, mixed>>|null the plan steps last sent on this stream */
    private ?array $planSent = null;

    /** @var list<string>|null */
    private ?array $offered = null;

    /** @var (Closure(): iterable<Message>)|null */
    private ?Closure $history = null;

    /** @var array<string, true>|null */
    private ?array $unapproved = null;

    /** Tool calls observed in the current model step. */
    private int $stepCalls = 0;

    private readonly bool $resuming;

    public function __construct(
        private readonly ?string $turnId,
        private readonly bool $buffered,
        private int $seq = 0,
        ?string $resumeRunId = null,
    ) {
        $this->runId = $resumeRunId;
        $this->resuming = $resumeRunId !== null;
        $this->lastObservedAt = hrtime(true);
    }

    /** Whether this stream continues a parked run under that run's own id. */
    public function resuming(): bool
    {
        return $this->resuming;
    }

    /**
     * The run id this stream runs under: the parked run's own id when resuming
     * one, so its sequence continues; a fresh id otherwise.
     */
    public function claimRunId(): string
    {
        return $this->runId ??= 'run_'.bin2hex(random_bytes(12));
    }

    public function bind(string $sessionKey, string $runId, ?string $parentRunId, ?string $rootRunId, ?string $harnessAgent = null): void
    {
        $this->sessionKey = $sessionKey;
        $this->harnessAgent = $harnessAgent;
        $this->runId = $runId;
        $this->parentRunId = $parentRunId;
        $this->rootRunId = $rootRunId ?? $runId;
        $this->lastObservedAt = hrtime(true);
    }

    /**
     * The tools this run actually offered the model. A result for any other
     * name is a call the mode's allowlist refused.
     *
     * @param  list<string>  $names
     */
    public function offered(array $names): void
    {
        $this->offered = $names;
    }

    /**
     * Where a denied approval can be read back from: the thread, which is
     * where decide() wrote it. Read lazily, at the first failed result.
     *
     * @param  Closure(): iterable<Message>  $history
     */
    public function history(Closure $history): void
    {
        $this->history = $history;
    }

    public function seq(): int
    {
        return $this->seq;
    }

    /** @return list<RunStep> */
    public function drain(): array
    {
        $steps = $this->buffer;
        $this->buffer = [];

        return $steps;
    }

    /**
     * Record one human decision, as it was written to the thread.
     */
    public function decision(string $approvalId, bool $approved, ?string $reason, ?string $actor): void
    {
        $this->emit(RunStep::DECISION_RECORDED, [
            'approvalId' => $approvalId,
            'verdict' => $approved ? 'approved' : 'denied',
            'reason' => $reason,
            'actor' => $actor,
        ]);
    }

    public function observe(StreamEvent $event): void
    {
        $now = hrtime(true);

        if ($event instanceof ThinkingEvent) {
            $this->emit(RunStep::THINKING, ['delta' => $event->delta]);
        } elseif ($event instanceof TextDeltaEvent) {
            $this->stepText .= $event->delta;
            $this->emit(RunStep::TEXT, ['delta' => $event->delta]);
        } elseif ($event instanceof ToolCallEvent) {
            $this->calls[$event->toolCall->id] = $now;
            $this->stepCalls++;
            $this->emit(RunStep::TOOL_CALL, [
                'toolCallId' => $event->toolCall->id,
                'name' => $event->toolCall->name,
                'args' => ToolCallArguments::recordable($event->toolCall)->argumentsAsObject(),
                'startedAt' => $this->timestamp(),
            ]);
        } elseif ($event instanceof ToolResultEvent) {
            $this->emit(RunStep::TOOL_RESULT, $this->toolResult($event, $now));
        } elseif ($event instanceof ToolApprovalRequestEvent) {
            $this->emit(RunStep::APPROVAL_REQUEST, [
                'approvalId' => $event->approvalId,
                'toolCallId' => $event->toolCall->id,
            ]);
        } elseif ($event instanceof ErrorEvent) {
            // The CODE travels, the provider's message does not: it can carry a
            // request URL or a key embedded in one, and a step is broadcast to
            // listeners that may put it on a screen. Same rule as RunFailed.
            $this->emit(RunStep::ERROR, ['error' => [
                'code' => $event->errorType,
                'message' => 'The model stream reported an error.',
            ]]);
        } elseif ($event instanceof StepStartEvent) {
            $this->stepText = '';
            $this->stepCalls = 0;
            $this->emit(RunStep::STEP_START, []);
        } elseif ($event instanceof StepFinishEvent) {
            $this->emit(RunStep::STEP_FINISH, ['text' => $this->stepText]);
            $this->stepText = '';
        }

        $this->lastObservedAt = $now;
    }

    /** @return array<string, mixed> */
    private function toolResult(ToolResultEvent $event, int $now): array
    {
        $id = $event->toolResult->toolCallId;
        $name = $event->toolResult->toolName;
        $result = $this->text($event->toolResult->result);

        // Measured from the moment Harness observed the call. A result whose
        // call was observed in an EARLIER run (an approved call executed on
        // resume) is measured from the last thing this run observed, which is
        // the closest bound this process has to when it started.
        $observed = isset($this->calls[$id]);
        $startedAt = $this->calls[$id] ?? $this->lastObservedAt;
        unset($this->calls[$id]);
        $durationMs = max(0, intdiv($now - $startedAt, 1_000_000));
        // EXACT only when the call was observed here and ran alone. Core runs a
        // step's tools as a batch before yielding any result, so with siblings
        // the measurement includes their time; and a call executed on resume
        // was observed in an earlier stream. Either way it is an upper bound.
        $exact = $observed && $this->stepCalls <= 1;

        $code = null;
        if (ToolRefusals::consume($this->claimRunId(), $name, $result)) {
            $code = 'tool_call_refused';
        } elseif (! $event->success && $this->unapproved($id)) {
            $code = 'approval_denied';
        } elseif (! $event->success && $this->offered !== null && ! in_array($name, $this->offered, true)) {
            $code = 'tool_not_available';
        }
        $refused = $code !== null;
        $ok = $event->success && ! $refused;

        return [
            'toolCallId' => $id,
            'ok' => $ok,
            ...($ok ? ['result' => $result] : ['error' => [
                'code' => $code ?? 'tool_failed',
                // What the model was given for this result, and no more.
                'message' => $event->error ?? $result,
            ]]),
            'refused' => $refused,
            'durationMs' => $durationMs,
            'durationExact' => $exact,
        ];
    }

    /**
     * Whether a call was gated on approval and not approved: denied outright,
     * or never answered (core denies by default).
     */
    private function unapproved(string $toolCallId): bool
    {
        if ($this->unapproved === null) {
            $this->unapproved = [];
            if ($this->history !== null) {
                $requested = [];
                $approved = [];
                foreach (($this->history)() as $message) {
                    if ($message instanceof AssistantMessage) {
                        foreach ($message->toolApprovalRequests as $request) {
                            $requested[$request->approvalId] = $request->toolCallId;
                        }
                    } elseif ($message instanceof ToolResultMessage) {
                        foreach ($message->toolApprovalResponses as $response) {
                            $approved[$response->approvalId] = $response->approved;
                        }
                    }
                }
                foreach ($requested as $approvalId => $callId) {
                    if (($approved[$approvalId] ?? false) !== true) {
                        $this->unapproved[$callId] = true;
                    }
                }
            }
        }

        return isset($this->unapproved[$toolCallId]);
    }

    /**
     * Turn on `plan_updated` steps for this stream (G-360). The first step of
     * the stream is preceded by a full snapshot when the session has a live
     * plan, so a reader catches up on anything that changed between runs.
     *
     * @param  Closure(): ?array<string, mixed>  $source
     */
    public function plans(Closure $source): void
    {
        $this->planSource = $source;
    }

    /**
     * The plan was written while this stream ran: a snapshot the first time,
     * then only the steps that changed.
     *
     * @param  array<string, mixed>  $plan
     */
    public function planUpdated(array $plan): void
    {
        if ($this->planSource === null) {
            return;
        }
        $this->planStarted = true;
        $data = PlanView::update($plan, $this->planSent);
        $this->planSent = PlanView::steps($plan);
        $this->emit(RunStep::PLAN_UPDATED, $data, PlanView::CONTRACT_VERSION);
    }

    /** @param array<string, mixed> $data */
    private function emit(string $type, array $data, string $contractVersion = RunStep::CONTRACT_VERSION): void
    {
        if ($this->planSource !== null && ! $this->planStarted) {
            $this->planStarted = true;
            $plan = ($this->planSource)();
            if (PlanMachine::isLive($plan)) {
                /** @var array<string, mixed> $plan */
                $this->planUpdated($plan);
            }
        }
        $runId = $this->claimRunId();
        $step = new RunStep(
            runId: $runId,
            parentRunId: $this->parentRunId,
            rootRunId: $this->rootRunId ?? $runId,
            turnId: $this->turnId,
            seq: ++$this->seq,
            at: $this->timestamp(),
            type: $type,
            data: $data,
            contractVersion: $contractVersion,
        );

        if ($this->buffered) {
            $this->buffer[] = $step;
        }

        // Opt-in. A step carries tool arguments, tool results and model text; dispatching it by default would put
        // that content into every app that records or broadcasts all events (Telescope, wildcard listeners) the
        // moment it upgraded. streamSteps()/streamDecision() callers receive steps directly either way.
        if (config('prism-harness.step_events', false) !== true) {
            return;
        }

        try {
            event(new RunStepped($this->sessionKey ?? '', $step, $this->harnessAgent));
        } catch (Throwable $listenerFailure) {
            // Observation only. A listener that throws must not stop or alter
            // the run it is watching.
            report($listenerFailure);
        }
    }

    private function timestamp(): string
    {
        return Carbon::now('UTC')->format('Y-m-d\TH:i:s.u\Z');
    }

    private function text(mixed $result): string
    {
        if (is_string($result)) {
            return $result;
        }
        if ($result === null) {
            return '';
        }

        return is_scalar($result) ? (string) $result : (json_encode($result) ?: '');
    }
}
