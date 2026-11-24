<?php

declare(strict_types=1);

namespace Prism\Harness\Stores;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Prism\Harness\Contracts\WorkflowLedgerStore;

/**
 * A workflow budget in `harness_workflow_budgets`, shared by every worker of
 * the run (G-348).
 *
 * Each admission is one transaction holding the run's row with
 * `lockForUpdate()` (SQLite serialises writers instead), so the decision and
 * the write cannot interleave with another worker's. The row is keyed by a
 * hash of the run key, so an engine's key of any length or alphabet fits.
 *
 * The totals are also written to plain columns, so an operator can see what a
 * run spent without decoding the state. Reservations held by a worker that
 * died stay in the state, and stay counted, until someone reconciles them.
 */
final class DatabaseWorkflowLedgerStore implements WorkflowLedgerStore
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table = 'harness_workflow_budgets',
    ) {}

    public function transact(string $runKey, array $initial, Closure $operation): mixed
    {
        $id = hash('sha256', $runKey);

        return $this->connection->transaction(function () use ($id, $runKey, $initial, $operation): mixed {
            $now = now();
            $this->query()->insertOrIgnore([
                'id' => $id,
                // A readable copy only when it fits whole; cutting a key could
                // leave invalid UTF-8 a strict database refuses.
                'run_key' => strlen($runKey) <= 255 && preg_match('//u', $runKey) === 1 ? $runKey : 'sha256:'.$id,
                'state' => json_encode($initial, JSON_THROW_ON_ERROR),
                'steps' => 0,
                'tool_calls' => 0,
                'cost_usd' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = $this->query()->where('id', $id)->lockForUpdate()->first();
            $raw = is_object($row) && isset($row->state) && is_string($row->state) ? $row->state : null;
            $decoded = $raw === null ? null : json_decode($raw, true);
            if (! is_array($decoded)) {
                // A row nobody can read is not an empty budget.
                throw new \RuntimeException('The workflow budget record for this run cannot be read.');
            }

            /** @var array<string, mixed> $decoded */
            [$state, $result] = $operation($decoded);

            $this->query()->where('id', $id)->update([
                'state' => json_encode($state, JSON_THROW_ON_ERROR),
                'steps' => is_int($state['steps'] ?? null) ? $state['steps'] : 0,
                'tool_calls' => is_int($state['tool_calls'] ?? null) ? $state['tool_calls'] : 0,
                'cost_usd' => is_numeric($state['cost_usd'] ?? null) ? (float) $state['cost_usd'] : 0,
                'exhausted_at' => is_string($state['exhausted_at'] ?? null) ? $state['exhausted_at'] : null,
                'updated_at' => $now,
            ]);

            return $result;
        });
    }

    private function query(): Builder
    {
        return $this->connection->table($this->table);
    }
}
