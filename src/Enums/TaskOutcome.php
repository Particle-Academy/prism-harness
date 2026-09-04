<?php

declare(strict_types=1);

namespace Prism\Harness\Enums;

use Prism\Harness\Exceptions\InvalidTaskOutcome;

/**
 * How a claimed task ended — the argument to `release()`.
 *
 * DELIBERATELY NOT {@see TaskState}. Only two of the four states can be
 * released INTO, and a signature that accepts all four invites
 * `release($task, TaskState::Todo)` — a caller hand-rolling the requeue that
 * the lease already does, or hand-rolling the "put it back" that this design
 * says is the application's job to do by adding a new task. Neither is
 * reachable through this type, which is the point of it existing.
 *
 * The wire strings match the state values, because the record a release
 * produces stores the state and the two must not drift apart.
 */
enum TaskOutcome: string
{
    case Done = 'done';

    case Failed = 'failed';

    /**
     * Read an outcome that came from OUTSIDE — a tool call, a form, a queue
     * payload — and refuse anything that is not exactly one of the two.
     *
     * THERE IS NO DEFAULT, AND THAT IS THE POINT. The obvious implementation is
     * `$value === 'failed' ? Failed : Done`, and it is a live defect rather
     * than a hypothetical one: a sibling port shipped it, and under it a
     * missing argument, `'complete'`, and `'DONE'` all recorded DONE. Every
     * malformed input resolved to the MORE PRIVILEGED of the two outcomes —
     * an agent declaring victory by typo, which is the exact failure the
     * completion-authority rule exists to prevent, reached without ever
     * defeating the rule.
     *
     * Case-sensitive, and nothing is trimmed: `'DONE'` is refused. A forgiving
     * parse is where three languages disagree about which strings mean success.
     *
     * @throws InvalidTaskOutcome
     */
    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? throw InvalidTaskOutcome::notAnOutcome($value);
    }

    public function toState(): TaskState
    {
        return match ($this) {
            self::Done => TaskState::Done,
            self::Failed => TaskState::Failed,
        };
    }
}
