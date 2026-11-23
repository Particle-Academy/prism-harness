<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\CallCostObserver;

/**
 * Where a run sits in its tree, and what the tree has left to spend.
 *
 * Threaded through {@see AgentRuntime::send()} so a nested run is
 * measured against the same account as its parent, and so every message it
 * writes can be attributed to it afterwards. A run with no context is a root:
 * that is the ordinary case and stays free of all of this.
 */
final readonly class RunContext
{
    public ?CallCostObserver $observer;

    /**
     * How deep a tree may nest.
     *
     * Budgets alone do bound a cycle — mode A calling B calling A terminates
     * when the steps run out — but they do not bound it CHEAPLY, and they do
     * not bound the child's address, which grows by `::sub::<name>` at every
     * level against a `string(255)` scope column. A tree deep enough to
     * overflow that would silently truncate on MySQL and collide two distinct
     * children onto one conversation.
     *
     * Depth is also the honest limit to state: nobody debugs a six-deep agent
     * tree, and a config that produced one is a mistake worth reporting rather
     * than executing.
     */
    public const MAX_DEPTH = 4;

    /** @param array<string, array<string, array<string, mixed>>> $pricing Per-provider/model overrides, inherited by children. */
    public function __construct(
        public RunLedger $ledger,
        public RunBudget $budget,
        public ?string $parentRunId = null,
        public ?int $parentThreadId = null,
        public int $depth = 0,
        public array $pricing = [],
        ?CallCostObserver $observer = null,
        /**
         * The ACP turn this run serves, when it serves one. Carried onto every
         * run step as `turnId`; Harness never invents one.
         */
        public ?string $turnId = null,
        /**
         * Who is acting, as the APPLICATION identifies them. Carried onto a
         * `decision_recorded` step as `actor`. It is set by the app that
         * authorized the decision, never read from the model or the request
         * body, which is the whole reason it lives here.
         */
        public ?string $actor = null,
        /**
         * True for a context built by {@see self::metadata()}: it carries a
         * turnId and/or actor and states NO run tree, so the runtime executes
         * the run exactly as it would with no context at all.
         */
        public bool $metadataOnly = false,
        /**
         * Where a subagent's child takes its provider and model from; null for
         * any run the subagent runner did not start. See {@see ChildBinding}.
         */
        public ?ChildBinding $binding = null,
        /**
         * The named agent this run belongs to — the `harness_agent` its spend
         * is labelled with in the shared ledger. Set by the runtime from the
         * session, never by a caller's claim, so a child labels its own spend.
         */
        public ?string $agent = null,
        /**
         * That agent's `spend_ceiling`, in USD, against the SAME root ledger.
         * A second limit on one account, never a second account.
         */
        public ?float $agentCeilingUsd = null,
    ) {
        $this->observer = $ledger->inheritCostObserver($observer);
    }

    /**
     * Only metadata — a turn and/or an actor — with no say in how the run
     * executes. The run takes the path and budget it would take with no
     * context; use {@see self::root()} to state a run tree.
     */
    public static function metadata(?string $turnId = null, ?string $actor = null): self
    {
        return new self(RunLedger::start('metadata'), new RunBudget(0),
            turnId: $turnId, actor: $actor, metadataOnly: true);
    }

    /** The same run, serving an ACP turn. */
    public function withTurn(?string $turnId): self
    {
        return new self($this->ledger, $this->budget, $this->parentRunId, $this->parentThreadId,
            $this->depth, $this->pricing, $this->observer, $turnId, $this->actor, $this->metadataOnly,
            $this->binding, $this->agent, $this->agentCeilingUsd);
    }

    /** The same run, acting for an actor the application has authorized. */
    public function withActor(?string $actor): self
    {
        return new self($this->ledger, $this->budget, $this->parentRunId, $this->parentThreadId,
            $this->depth, $this->pricing, $this->observer, $this->turnId, $actor, $this->metadataOnly,
            $this->binding, $this->agent, $this->agentCeilingUsd);
    }

    /** The same run, as the subagent runner binds a child's provider and model. */
    public function withBinding(ChildBinding $binding): self
    {
        return $this->rebuild(binding: $binding);
    }

    /** The same run, labelled as a named agent's and held to its ceiling. */
    public function forAgent(?string $agent, ?float $ceilingUsd): self
    {
        return $this->rebuild(agent: $agent, agentCeilingUsd: $ceilingUsd, labelled: true);
    }

    /** The same run, under a different budget or observer, keeping everything else. */
    public function withBudget(RunBudget $budget, ?CallCostObserver $observer = null): self
    {
        return $this->rebuild(budget: $budget, observer: $observer);
    }

    /**
     * Whether a model call in this run must be priced and reserved: a cost cap
     * on the tree, or a spend ceiling on the agent.
     */
    public function capped(): bool
    {
        return $this->budget->maxCostUsd !== null || $this->agentCeilingUsd !== null;
    }

    private function rebuild(
        ?RunBudget $budget = null,
        ?CallCostObserver $observer = null,
        ?ChildBinding $binding = null,
        ?string $agent = null,
        ?float $agentCeilingUsd = null,
        bool $labelled = false,
    ): self {
        return new self(
            ledger: $this->ledger,
            budget: $budget ?? $this->budget,
            parentRunId: $this->parentRunId,
            parentThreadId: $this->parentThreadId,
            depth: $this->depth,
            pricing: $this->pricing,
            observer: $observer ?? $this->observer,
            turnId: $this->turnId,
            actor: $this->actor,
            metadataOnly: $this->metadataOnly,
            binding: $binding ?? $this->binding,
            agent: $labelled ? $agent : $this->agent,
            agentCeilingUsd: $labelled ? $agentCeilingUsd : $this->agentCeilingUsd,
        );
    }

    /** @param array<string, array<string, array<string, mixed>>> $pricing */
    public static function root(string $runId, RunBudget $budget, array $pricing = [], ?CallCostObserver $observer = null): self
    {
        return new self(RunLedger::start($runId), $budget, pricing: $pricing, observer: $observer);
    }

    public function rootRunId(): string
    {
        return $this->ledger->rootRunId;
    }

    public function isChild(): bool
    {
        return $this->parentRunId !== null;
    }

    /**
     * The context a child inherits: same ledger, narrowed budget.
     *
     * Same LEDGER by reference is the load-bearing part — see {@see RunLedger}.
     * A child with its own ledger would let every node report itself inside
     * budget while the tree spent without limit.
     */
    public function forChild(Subagent $subagent, string $parentRunId, ?int $parentThreadId): self
    {
        return new self(
            ledger: $this->ledger,
            budget: $subagent->budget->nestedWithin($this->budget, $this->ledger),
            parentRunId: $parentRunId,
            parentThreadId: $parentThreadId,
            depth: $this->depth + 1,
            pricing: $this->pricing,
            observer: $this->observer,
            turnId: $this->turnId,
            actor: $this->actor,
        );
    }

    public function tooDeep(): bool
    {
        return $this->depth >= self::MAX_DEPTH;
    }
}
