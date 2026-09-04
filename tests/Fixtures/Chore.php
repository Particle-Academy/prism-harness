<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Concerns\IsAgentTask;
use Prism\Harness\Contracts\AgentTask;

/**
 * A consumer's OWN model, with the conventional column names.
 *
 * The point of the fixture is that this package ships no task model: this one
 * belongs to the "application", has a column the package has never heard of,
 * and satisfies the contract anyway.
 */
class Chore extends Model implements AgentTask
{
    use IsAgentTask;

    protected $table = 'chores';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['claimed_until' => 'datetime'];
    }
}
