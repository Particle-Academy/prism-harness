<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use Prism\Harness\Contracts\WorkflowLedgerStore;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Stores\MemoryWorkflowLedgerStore;

/**
 * Opens the account a workflow run spends from (G-348).
 *
 * ON BY DEFAULT: `prism-harness.workflow` limits every run to 100 model calls
 * and 100 tool calls unless the operator says otherwise. The operator's
 * values are the ceiling; a host's per-run values and a graph's
 * `harness_budget` can only lower them.
 *
 * A run is identified by the engine's run key, which fancy-flow keeps the same
 * across retries, resumes, workers and nested lanes. A run with no usable key
 * is given one by the Harness and the key is saved with the session, so a
 * retry or resume in that session continues the same budget rather than
 * starting a fresh one. Call {@see self::startSessionRun()} to begin a new
 * workflow run on a session whose runs carry no key.
 */
final class WorkflowBudgets
{
    /** The session capability the Harness-created run key is saved under. */
    public const SESSION_CAPABILITY = 'harness_workflow_budget';

    /**
     * @param  array<string, mixed>  $config  `prism-harness.workflow`
     * @param  array<string, mixed>  $agentConfig  `prism-harness.agent`, for the cost and output fallbacks
     */
    public function __construct(
        private readonly WorkflowLedgerStore $store,
        private readonly array $config,
        private readonly array $agentConfig = [],
    ) {}

    /**
     * The account for one run.
     *
     * The limits are fixed when the run's record is first opened. A later open
     * (a retry, a resume after a deploy) can lower them and never raise them.
     *
     * @param  array<string, mixed>  $limits  the host's limits for this run; lower only
     * @param  array<string, mixed>  $workflow  the graph's `harness_budget`; lower only
     *
     * @throws \InvalidArgumentException for an invalid limit, before anything runs
     */
    public function open(string $runKey, array $limits = [], array $workflow = []): WorkflowBudget
    {
        return $this->openIn($this->store, $runKey, $limits, $workflow);
    }

    /**
     * The account for a run with no usable key, saved with the session that
     * serves it (Wish, q_9779fe97).
     *
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>  $workflow
     */
    public function forSession(Session $session, array $limits = [], array $workflow = []): WorkflowBudget
    {
        return $this->open($this->sessionRunKey($session), $limits, $workflow);
    }

    /**
     * Begin a new workflow run on a session whose runs carry no key: the next
     * workflow call on it opens a fresh account. Returns the new key.
     */
    public function startSessionRun(Session $session): string
    {
        $key = 'harness:'.bin2hex(random_bytes(16));
        $session->usingCapability(self::SESSION_CAPABILITY, ['run_key' => $key]);

        return $key;
    }

    /**
     * The account for a sessionless call with no run key: held in this
     * process only, because there is nothing to save it with.
     */
    public function forProcess(): WorkflowBudget
    {
        return $this->openIn(new MemoryWorkflowLedgerStore, 'process:'.bin2hex(random_bytes(16)), [], []);
    }

    /** The key a session's unidentified workflow runs share, created on first use. */
    public function sessionRunKey(Session $session): string
    {
        $key = $session->capability(self::SESSION_CAPABILITY)['run_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : $this->startSessionRun($session);
    }

    /**
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>  $workflow
     */
    private function openIn(WorkflowLedgerStore $store, string $runKey, array $limits, array $workflow): WorkflowBudget
    {
        if ($runKey === '') {
            throw new \InvalidArgumentException('A workflow budget needs a nonempty run key.');
        }

        $operator = $this->operatorLimits();
        $ignored = [];
        $requested = $operator
            ->narrowedBy(WorkflowLimits::fromArray($limits, 'the host limits'))
            ->narrowedBy(WorkflowLimits::fromArray($workflow, 'the workflow harness_budget'), $ignored);

        $initial = ['limits' => $requested->toArray(), 'started_at' => microtime(true), 'exhausted_at' => null];

        $effective = $store->transact($runKey, $initial, function (array $state) use ($requested): array {
            // The first open fixed the limits; this open may only lower them.
            $stored = is_array($state['limits'] ?? null) ? $state['limits'] : [];
            $snapshot = WorkflowLimits::fromArray(array_intersect_key($stored, array_flip(WorkflowLimits::KEYS)), 'the stored workflow budget');
            $merged = $this->lowest($snapshot, $requested);
            $state['limits'] = $merged->toArray();

            return [$state, $merged];
        });

        $warnings = array_map(
            static fn (string $key): string => sprintf('harness_budget asked for more %s than the operator allows; the operator limit applies.', $key),
            $ignored,
        );

        $output = $this->agentConfig['max_output_tokens_per_call'] ?? null;

        return new WorkflowBudget($runKey, $effective, $store, $initial,
            is_int($output) && $output > 0 ? $output : null, $warnings);
    }

    private function lowest(WorkflowLimits $a, WorkflowLimits $b): WorkflowLimits
    {
        $min = static fn (int|float|null $x, int|float|null $y): int|float|null => $x === null ? $y : ($y === null ? $x : min($x, $y));
        $steps = $min($a->maxSteps, $b->maxSteps);
        $tools = $min($a->maxToolCalls, $b->maxToolCalls);
        $cost = $min($a->maxCostUsd, $b->maxCostUsd);
        $seconds = $min($a->maxSeconds, $b->maxSeconds);

        return new WorkflowLimits(
            $steps === null ? null : (int) $steps,
            $tools === null ? null : (int) $tools,
            $cost === null ? null : (float) $cost,
            $seconds === null ? null : (int) $seconds,
        );
    }

    private function operatorLimits(): WorkflowLimits
    {
        $cost = $this->config['max_cost_usd'] ?? null;
        $fallback = $this->agentConfig['max_cost_usd'] ?? null;

        return WorkflowLimits::fromArray([
            'max_steps' => self::configured($this->config, 'max_steps', 100),
            'max_tool_calls' => self::configured($this->config, 'max_tool_calls', 100),
            // One cost cap for a deployment: a workflow falls back to the
            // agent's, so a cap that holds for turns also holds here.
            'max_cost_usd' => is_numeric($cost) ? (float) $cost : (is_numeric($fallback) ? (float) $fallback : null),
            'max_seconds' => self::configured($this->config, 'max_seconds', null),
        ], 'prism-harness.workflow');
    }

    /** @param array<string, mixed> $config */
    private static function configured(array $config, string $key, ?int $default): mixed
    {
        if (! array_key_exists($key, $config)) {
            return $default;
        }
        $value = $config[$key];

        // env() hands over strings; a whole-number string is the number it spells.
        return is_string($value) && preg_match('/\A\d+\z/', $value) === 1 ? (int) $value : $value;
    }
}
