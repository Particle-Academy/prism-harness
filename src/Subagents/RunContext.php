<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\AgentRuntime;

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

    public function __construct(
        public RunLedger $ledger,
        public RunBudget $budget,
        public ?string $parentRunId = null,
        public ?int $parentThreadId = null,
        public int $depth = 0,
    ) {}

    public static function root(string $runId, RunBudget $budget): self
    {
        return new self(RunLedger::start($runId), $budget);
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
        );
    }

    public function tooDeep(): bool
    {
        return $this->depth >= self::MAX_DEPTH;
    }
}
