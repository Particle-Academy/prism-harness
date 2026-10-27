<?php

declare(strict_types=1);

namespace Prism\Harness\Tangents;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Prism\Harness\Contracts\RunLedgerStore;

/** Every accounting mutation locks the same root row before admission. */
final readonly class DatabaseRunLedgerStore implements RunLedgerStore
{
    public function __construct(private ConnectionInterface $connection) {}

    public function create(string $rootRunId, array $state): void
    {
        $this->connection->table('harness_run_ledgers')->insert([
            'id' => $rootRunId,
            'state' => json_encode($state, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    public function read(string $rootRunId): array
    {
        $row = $this->connection->table('harness_run_ledgers')->where('id', $rootRunId)->first();

        return $this->decode($row?->state);
    }

    public function update(string $rootRunId, Closure $operation): mixed
    {
        return $this->connection->transaction(function () use ($rootRunId, $operation): mixed {
            $row = $this->connection->table('harness_run_ledgers')->where('id', $rootRunId)->lockForUpdate()->first();
            $change = $operation($this->decode($row?->state));
            $this->connection->table('harness_run_ledgers')->where('id', $rootRunId)->update([
                'state' => json_encode($change['state'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            ]);

            return $change['result'];
        });
    }

    /** @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        if (! is_string($json)) {
            throw new \LogicException('The durable run ledger is unavailable.');
        }
        $state = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($state) || array_is_list($state)) {
            throw new \LogicException('The durable run ledger is malformed.');
        }

        return $state;
    }
}
