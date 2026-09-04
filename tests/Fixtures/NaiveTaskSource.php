<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Prism\Harness\Contracts\AgentTask;
use Prism\Harness\Contracts\AgentTaskSource;
use Prism\Harness\Enums\TaskOutcome;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Tasks\TaskRecord;

/**
 * A third party's own source, written the obvious way — and therefore WITHOUT
 * the ownership check the shipped one enforces.
 *
 * This exists to hold the completion tool to its own guarantee rather than to
 * the shipped source's. `AgentTaskSource` cannot make an implementation check
 * anything; a consumer writing one against the interface will implement
 * `release()` as "find it, set the state", because that is what the signature
 * suggests and nothing fails if they do.
 *
 * So the tool must refuse a task this worker does not hold EVEN WHEN THE SOURCE
 * WOULD HAVE ALLOWED IT. That is the difference between "we send the right
 * thing" and "the wrong thing cannot be invoked", and it is invisible in a
 * suite where every source is the careful one.
 */
final class NaiveTaskSource implements AgentTaskSource
{
    /** @var array<string, AgentTask> */
    private array $tasks = [];

    public function put(AgentTask $task): void
    {
        $this->tasks[$task->id()] = $task;
    }

    #[\Override]
    public function claim(string $worker, ?int $leaseSeconds = null): ?AgentTask
    {
        return null;
    }

    /**
     * Deliberately unguarded: no worker check at all, so anything that reaches
     * here succeeds.
     */
    #[\Override]
    public function release(AgentTask $task, string $worker, TaskOutcome $outcome): void
    {
        $this->tasks[$task->id()] = new TaskRecord(
            id: $task->id(),
            instruction: $task->instruction(),
            state: $outcome->toState(),
        );
    }

    #[\Override]
    public function pending(): int
    {
        return count(array_filter(
            $this->tasks,
            fn (AgentTask $task): bool => $task->state() === TaskState::Todo,
        ));
    }

    #[\Override]
    public function find(string $id): ?AgentTask
    {
        return $this->tasks[$id] ?? null;
    }
}
