<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Concerns\IsAgentTask;
use Prism\Harness\Contracts\AgentTask;

/**
 * The same contract on a model whose columns are named nothing like the
 * convention — which is the case the per-method overrides exist for, and the
 * one that proves the trait is not quietly requiring a schema after all.
 */
class Errand extends Model implements AgentTask
{
    use IsAgentTask;

    protected $table = 'errands';

    protected $guarded = [];

    protected $primaryKey = 'ref';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function taskInstructionColumn(): string
    {
        return 'body';
    }

    protected function taskStateColumn(): string
    {
        return 'status';
    }

    protected function taskClaimedByColumn(): string
    {
        return 'holder';
    }

    protected function taskClaimedUntilColumn(): string
    {
        return 'lease_ends_at';
    }
}
