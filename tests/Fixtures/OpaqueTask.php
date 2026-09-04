<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Prism\Harness\Contracts\AgentTask;
use Prism\Harness\Enums\TaskState;

/**
 * An AgentTask that satisfies the contract and NOTHING MORE.
 *
 * Three methods is the whole contract, and none of them is "who holds this".
 * So a task from a source this package does not ship can be `claimed` without
 * anyone being able to say by whom — and the completion tool's answer for a
 * task whose holder cannot be established has to be NO. A check that fails open
 * against an unfamiliar shape is not a check.
 */
final readonly class OpaqueTask implements AgentTask
{
    public function __construct(
        private string $id,
        private string $instruction,
        private TaskState $state,
    ) {}

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }

    #[\Override]
    public function instruction(): string
    {
        return $this->instruction;
    }

    #[\Override]
    public function state(): TaskState
    {
        return $this->state;
    }
}
