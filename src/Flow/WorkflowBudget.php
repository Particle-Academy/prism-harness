<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use Closure;
use Prism\Harness\Contracts\WorkflowLedgerStore;
use Prism\Harness\Events\WorkflowBudgetExhausted;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\LedgerGuard;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunLedger;
use Throwable;

/**
 * The one account a whole workflow run spends from (G-348).
 *
 * A workflow is the root of a run tree. Every model call, agent turn,
 * subagent and workflow tool call in it charges this account, keyed by the
 * engine's run key, so `for_each`, recursive subflows, retries, resumes and
 * parallel workers all draw from the same totals.
 *
 * The {@see RunLedger} it hands out is a cache of the stored totals: every
 * read and write goes through {@see self::atomically()}, which loads the
 * stored state first and stores it after, under the store's exclusive hold.
 *
 * Obtain one from {@see WorkflowBudgets}.
 */
final class WorkflowBudget implements LedgerGuard
{
    private readonly RunContext $root;

    private ?Throwable $pendingFailure = null;

    private ?WorkflowBudgetExhausted $pendingEvent = null;

    /** @param array<string, mixed> $initial */
    public function __construct(
        public readonly string $runKey,
        public readonly WorkflowLimits $limits,
        private readonly WorkflowLedgerStore $store,
        private readonly array $initial,
        ?int $maxOutputTokensPerCall,
        /** @var list<string> */
        public readonly array $warnings = [],
        /** The budget-store record this run's account is kept in; the run key when not given. */
        private readonly ?string $record = null,
    ) {
        $ledger = (new RunLedger('wf:'.$runKey))->guardedBy($this);
        $this->root = new RunContext($ledger, new RunBudget(
            maxSteps: $limits->maxSteps ?? PHP_INT_MAX,
            maxCostUsd: $limits->maxCostUsd,
            maxSeconds: $limits->maxSeconds,
            maxOutputTokensPerCall: $maxOutputTokensPerCall,
            maxToolCalls: $limits->maxToolCalls,
        ));
    }

    /** The run tree's root: the workflow's ledger under the workflow's limits. */
    public function context(): RunContext
    {
        return $this->root;
    }

    /** The context a workflow agent node's session turn runs under. */
    public function nodeContext(): RunContext
    {
        return $this->root->forWorkflowNode();
    }

    /**
     * Refuse now, before anything is read or sent, when the run may not
     * spend again. Charges nothing.
     *
     * @throws RunNotPermitted
     */
    public function assertAvailable(): void
    {
        $this->root->ledger->assertCallAvailable($this->root->budget);
    }

    /**
     * Admit and charge one workflow tool handler, immediately before it runs.
     *
     * @throws RunNotPermitted
     */
    public function takeToolCall(): void
    {
        $this->root->ledger->takeToolCall($this->root->budget);
    }

    public function atomically(RunLedger $ledger, Closure $operation): mixed
    {
        $result = $this->store->transact($this->record ?? $this->runKey, $this->initial, function (array $state) use ($ledger, $operation): array {
            $ledger->restore($state);

            try {
                $result = $operation();
                $this->assertWithinLimits($ledger);

                return [[...$state, ...$ledger->snapshot()], $result];
            } catch (RunNotPermitted $refused) {
                $reason = $this->exhaustionReason($refused, $state);
                if ($reason === null) {
                    $ledger->restore($state);
                    $this->pendingFailure = $refused;

                    return [$state, null];
                }

                return $this->exhaust($ledger, $state, $reason);
            } catch (Throwable $failure) {
                $ledger->restore($state);
                $this->pendingFailure = $failure;

                return [$state, null];
            }
        });

        $this->release();

        return $result;
    }

    public function refusal(RunLedger $ledger, string $reason): RunNotPermitted
    {
        $this->store->transact($this->record ?? $this->runKey, $this->initial, function (array $state) use ($ledger, $reason): array {
            $ledger->restore($state);
            $probe = new RunLedger('probe');
            $probe->restore($state);
            $workflowStop = $probe->exhaustion($this->root->budget);

            if ($workflowStop === null) {
                // A node's own limit, not the workflow's: only the node stops.
                $this->pendingFailure = RunNotPermitted::exhausted($reason);

                return [$state, null];
            }

            return $this->exhaust($ledger, $state, $workflowStop);
        });

        try {
            $this->release();
        } catch (RunNotPermitted $refused) {
            return $refused;
        }

        return RunNotPermitted::exhausted($reason);
    }

    /**
     * Record the run's first exhaustion: cancel the ledger with its reason so
     * no other limit lets a later node through, and announce it once.
     *
     * @param  array<string, mixed>  $state
     * @return array{0: array<string, mixed>, 1: null}
     */
    private function exhaust(RunLedger $ledger, array $state, string $reason): array
    {
        $ledger->restore($state);
        $snapshot = $ledger->snapshot();

        if (($state['exhausted_at'] ?? null) === null) {
            $snapshot['cancelled'] = true;
            $snapshot['cancel_reason'] = $reason;
            $state['exhausted_at'] = now()->toIso8601String();
            $this->pendingEvent = new WorkflowBudgetExhausted(
                $this->runKey, $ledger->rootRunId, $reason,
                $snapshot['steps'], $snapshot['tool_calls'], $snapshot['cost_usd'],
            );
        } else {
            // Already stopped: every later refusal names the first reason.
            $reason = is_string($state['cancel_reason'] ?? null) ? $state['cancel_reason'] : $reason;
        }

        $state = [...$state, ...$snapshot];
        $ledger->restore($state);
        $this->pendingFailure = RunNotPermitted::workflowBudget($reason);

        return [$state, null];
    }

    /**
     * Why a refusal stops the whole workflow, or null when it does not: a
     * pricing refusal, a host veto or a spend ceiling keeps its own code, and
     * a session's own step ceiling stops only its node.
     *
     * @param  array<string, mixed>  $state
     */
    private function exhaustionReason(RunNotPermitted $refused, array $state): ?string
    {
        if ($refused->code() === 'workflow_budget_exhausted') {
            return $refused->reason() ?? 'workflow budget exhausted';
        }

        if ($refused->code() === 'call_budget_exceeded') {
            $probe = new RunLedger('probe');
            $probe->restore($state);

            return sprintf('cost budget exhausted (the next call does not fit: %.4f USD spent and %.4f held of %.4f USD)',
                $probe->costUsd(), $probe->reservedCostUsd(), $this->limits->maxCostUsd ?? 0.0);
        }

        if ($refused->code() === 'run_not_permitted' && $refused->reason() !== null) {
            $probe = new RunLedger('probe');
            $probe->restore($state);

            return $probe->exhaustion($this->root->budget);
        }

        return null;
    }

    /** The store's own limits, checked after every write whatever path made it. */
    private function assertWithinLimits(RunLedger $ledger): void
    {
        $state = $ledger->snapshot();

        if ($this->limits->maxSteps !== null && $state['steps'] > $this->limits->maxSteps) {
            throw RunNotPermitted::workflowBudget(sprintf('step budget exhausted (%d of %d used)', $this->limits->maxSteps, $this->limits->maxSteps));
        }

        if ($this->limits->maxToolCalls !== null && $state['tool_calls'] > $this->limits->maxToolCalls) {
            throw RunNotPermitted::workflowBudget(sprintf('tool call budget exhausted (%d of %d used)', $this->limits->maxToolCalls, $this->limits->maxToolCalls));
        }
    }

    /** Announce, then throw, what the last transaction decided, once it has committed. */
    private function release(): void
    {
        $event = $this->pendingEvent;
        $failure = $this->pendingFailure;
        $this->pendingEvent = null;
        $this->pendingFailure = null;

        if ($event !== null) {
            event($event);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
