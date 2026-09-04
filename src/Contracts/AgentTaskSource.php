<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Enums\TaskOutcome;
use Prism\Harness\Exceptions\TaskNotReleasable;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Tools\TaskCompletionTool;
use Prism\Harness\Tools\ToolAuthorizer;

/**
 * Where tasks come from, and the only thing allowed to move one between states.
 *
 * `claim()` IS ONE CALL, AND THAT IS THE WHOLE POINT OF THIS INTERFACE. "Read
 * the next task" and "mark it mine" as two calls is the race this design exists
 * to prevent: two workers read the same row, both write their own name on it,
 * and the same instruction runs twice — with no error anywhere, because both
 * writes succeeded.
 *
 * ORDERING IS PART OF THE CONTRACT. `claim()` returns tasks in INSERTION ORDER
 * unless the source defines otherwise. A source MAY expose an explicit
 * position; it MUST NOT reorder implicitly. Nothing errors when ordering
 * changes — the agent simply does the work in a different sequence and produces
 * a different result, which is the hardest kind of divergence to notice.
 *
 * THE LOOP IS BOUNDED BY THE EXISTING {@see RunBudget},
 * not by a limit invented here. `pending()` says whether there is anything left
 * to do; the budget says whether there is anything left to spend. Two spellings
 * of one limit across an ecosystem is how a limit ends up set in the place that
 * is not enforced.
 */
interface AgentTaskSource
{
    /**
     * How long a claim is held before it expires back to `todo`.
     *
     * Five minutes: long enough for a model call plus tool work, short enough
     * that a crashed worker does not wedge the list for an hour. The number
     * matters far less than it being the SAME number in every language, so it
     * is written down once here rather than defaulted at each call site.
     */
    public const DEFAULT_LEASE_SECONDS = 300;

    /**
     * Atomically take the next available task, or null when there is none.
     *
     * Two workers calling this concurrently get DIFFERENT tasks, or one of them
     * gets null. Never the same task twice.
     *
     * @param  string  $worker  Who is claiming. Compared verbatim when the
     *                          holder extends its lease, so it must identify
     *                          one worker and not a class of them.
     * @param  int|null  $leaseSeconds  Null uses the source's configured lease.
     */
    public function claim(string $worker, ?int $leaseSeconds = null): ?AgentTask;

    /**
     * Record what happened to a claimed task.
     *
     * CALLED BY THE APPLICATION, FROM EVIDENCE — not by the agent. If the model
     * can set its own task to `done`, then "run until the goal is met" quietly
     * becomes "run until it decides it is met", and a run that has stalled ends
     * by declaring victory. A consumer that wants the agent to close its own
     * tasks registers {@see TaskCompletionTool}, which is
     * refused unless the existing {@see ToolAuthorizer}
     * says otherwise.
     *
     * @throws TaskNotReleasable when the task is already terminal, was never
     *                           claimed, or is not in this source
     */
    public function release(AgentTask $task, TaskOutcome $outcome): void;

    /**
     * How many tasks remain claimable, counting any whose lease has expired.
     *
     * A COUNT, NOT A LISTING. It exists to terminate the loop and a count is
     * enough for that. Returning a list invites the source to materialise every
     * task on every pass, and a consumer that wants the list already has its
     * own query.
     */
    public function pending(): int;

    /**
     * The task with this id, or null.
     *
     * Present because `release()` takes a task and every caller that reaches
     * this package from outside — an HTTP route, a queued job, a tool call —
     * holds an id and nothing else. Without it the contract cannot be driven by
     * anything but the loop that claimed.
     */
    public function find(string $id): ?AgentTask;
}
