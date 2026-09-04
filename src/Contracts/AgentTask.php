<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Concerns\IsAgentTask;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Tasks\StoreTaskSource;

/**
 * One unit of work an agent has been asked to do.
 *
 * THE PACKAGE SHIPS NO TASK MODEL, NO SCHEMA AND NO MIGRATION. What a task IS
 * differs for every consumer and is not this package's to decide; what is
 * identical for every consumer — durable state, handing one task to exactly one
 * worker, telling "started and died" apart from "never started" — is.
 *
 * So there are two ways to satisfy this:
 *
 *  - {@see StoreTaskSource} keeps its own records in the
 *    harness's durable store. Nothing to migrate, works immediately.
 *  - {@see IsAgentTask} adapts a consumer's OWN
 *    Eloquent model, whatever its columns are called.
 *
 * Three methods, and that is the whole contract. Anything richer is a job, and
 * a job needs a queue — which this deliberately is not.
 */
interface AgentTask
{
    /** Stable and unique WITHIN ITS SOURCE. Not globally unique. */
    public function id(): string;

    /** What the model is asked to do. */
    public function instruction(): string;

    public function state(): TaskState;
}
