<?php

declare(strict_types=1);

namespace Prism\Harness\Sessions;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Prism\Harness\Exceptions\AddressLimitExceeded;

/** Write-ahead ownership index. Orphan index entries are safe; unindexed writes are not. */
final readonly class DatabaseAddressIndex
{
    private ?int $maxAddressesPerParticipant;

    public function __construct(private ConnectionInterface $connection, mixed $maxAddressesPerParticipant = null)
    {
        if (! is_callable([$connection, 'getSchemaBuilder'])) {
            throw new \LogicException('Named address indexing requires a connection exposing the Laravel schema builder.');
        }
        $schema = $connection->getSchemaBuilder();
        foreach (['harness_address_heads', 'harness_addresses', 'harness_address_resources'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new \LogicException('Named agents and ParticipantRef require migration [0001_01_01_000006_add_agent_addresses]; run the package migration before using these features.');
            }
        }
        $columns = $schema->getColumnListing('harness_threads');
        foreach (['agent_id', 'participant_ref_type', 'participant_ref_id', 'address_key'] as $column) {
            if (! in_array($column, $columns, true)) {
                throw new \LogicException('Named agents and ParticipantRef require migration [0001_01_01_000006_add_agent_addresses]; run the package migration before using these features.');
            }
        }
        if ($maxAddressesPerParticipant !== null && (! is_int($maxAddressesPerParticipant) || $maxAddressesPerParticipant < 1)) {
            throw new InvalidArgumentException('max_addresses_per_participant must be a positive integer or null.');
        }
        $this->maxAddressesPerParticipant = $maxAddressesPerParticipant;
    }

    public static function configured(ConnectionInterface $connection): self
    {
        return new self($connection, config('prism-harness.addresses.max_addresses_per_participant'));
    }

    public function record(SessionAddress $address): void
    {
        $table = $this->connection->table('harness_addresses');
        if ((clone $table)->where('address_key', $address->indexKey())->exists()) {
            return;
        }
        $participant = self::participantKey($address->participantType, $address->participantId);
        $this->connection->transaction(function () use ($address, $participant): void {
            $heads = $this->connection->table('harness_address_heads');
            $heads->insertOrIgnore(['participant_key' => $participant, 'address_count' => 0]);
            $head = (clone $heads)->where('participant_key', $participant)->lockForUpdate()->first();
            if ($head === null) {
                throw new \LogicException('Harness address index head was not persisted.');
            }
            $table = $this->connection->table('harness_addresses');
            if ((clone $table)->where('address_key', $address->indexKey())->exists()) {
                return;
            }
            $count = (int) $head->address_count;
            if ($this->maxAddressesPerParticipant !== null && $count >= $this->maxAddressesPerParticipant) {
                throw new AddressLimitExceeded;
            }
            $table->insert([
                'address_key' => $address->indexKey(),
                'agent_id' => $address->agent,
                'participant_type' => $address->participantType,
                'participant_id' => $address->participantId,
                'participant_key' => $participant,
                'position' => $count,
                'scope' => $address->scope,
            ]);
            (clone $heads)->where('participant_key', $participant)->update(['address_count' => $count + 1]);
        });
    }

    public function resource(SessionAddress $address, string $slot, string $kind, string $key): void
    {
        if (! in_array($slot, ['ephemeral', 'durable'], true) || ! in_array($kind, ['state', 'lock', 'tasks'], true)) {
            throw new InvalidArgumentException('Unknown harness address resource kind or slot.');
        }
        $this->record($address);
        $this->connection->table('harness_address_resources')->insertOrIgnore([
            'resource_key' => hash('sha256', json_encode([$address->indexKey(), $slot, $kind, $key], JSON_THROW_ON_ERROR)),
            'address_key' => $address->indexKey(),
            'slot' => $slot,
            'kind' => $kind,
            'store_key' => $key,
        ]);
    }

    /** @return array{entries: list<array<string, mixed>>, next_cursor: int|null} */
    public function page(?string $participantType, ?string $participantId, int $cursor = 0, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 100 || $cursor < 0) {
            throw new InvalidArgumentException('Address index pages require a valid cursor and a limit of 1–100.');
        }

        $rows = $this->connection->table('harness_addresses')
            ->where('participant_key', self::participantKey($participantType, $participantId))
            ->where('position', '>=', $cursor)
            ->orderBy('position')->limit($limit + 1)->get()
            ->map(fn (object $row): array => (array) $row)->all();

        return ['entries' => array_slice($rows, 0, $limit), 'next_cursor' => count($rows) > $limit ? $cursor + $limit : null];
    }

    private static function participantKey(?string $type, ?string $id): string
    {
        return hash('sha256', json_encode([$type, $id], JSON_THROW_ON_ERROR));
    }
}
