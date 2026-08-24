<?php

declare(strict_types=1);

namespace Prism\Harness\Sessions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Prism\Harness\Contracts\SessionStore;
use Prism\Harness\Exceptions\UnsafeStateConfiguration;
use Prism\Harness\Stores\DatabaseSessionStore;
use Prism\Harness\Stores\RedisSessionStore;

/**
 * Resolves the two state slots, and refuses a configuration that would lose work.
 *
 * State is split into two named slots rather than one store, because the two
 * halves have genuinely different requirements:
 *
 *  - `ephemeral` — active mode, selected model, run bookkeeping. Losing it
 *    degrades to a default. Redis is the right home.
 *  - `durable` — threads and pending tool approvals. Losing it is a
 *    correctness failure, not a cache miss.
 *
 * The guard is the point of this class: a driver that reports itself Volatile
 * is refused for the durable slot at resolve time. Accepting it and finding out
 * later is exactly the failure this package was written to avoid.
 */
class SessionStoreManager
{
    public const SLOT_EPHEMERAL = 'ephemeral';

    public const SLOT_DURABLE = 'durable';

    /** @var array<string, SessionStore> */
    protected array $resolved = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly Container $container,
        protected readonly array $config,
    ) {}

    public function ephemeral(): SessionStore
    {
        return $this->slot(self::SLOT_EPHEMERAL);
    }

    public function durable(): SessionStore
    {
        return $this->slot(self::SLOT_DURABLE);
    }

    public function slot(string $slot): SessionStore
    {
        if (isset($this->resolved[$slot])) {
            return $this->resolved[$slot];
        }

        $name = $this->slotDriverName($slot);
        $store = $this->make($slot, $name);

        // Checked here rather than at boot so it fires in the same place
        // whether the store is configured in a config file, swapped in a test,
        // or changed at runtime — and so a misconfiguration cannot lie dormant
        // until the first approval needs saving.
        if ($slot === self::SLOT_DURABLE && ! $store->durability()->isDurable()) {
            throw UnsafeStateConfiguration::volatileDurableStore($slot, $name);
        }

        return $this->resolved[$slot] = $store;
    }

    protected function slotDriverName(string $slot): string
    {
        $slots = $this->config['stores'] ?? [];

        return is_array($slots) && is_string($slots[$slot] ?? null)
            ? $slots[$slot]
            : 'database';
    }

    protected function make(string $slot, string $name): SessionStore
    {
        $drivers = $this->config['drivers'] ?? [];
        $driver = is_array($drivers) ? ($drivers[$name] ?? null) : null;

        if (! is_array($driver)) {
            throw UnsafeStateConfiguration::unknownDriver($slot, $name);
        }

        return match ($driver['driver'] ?? $name) {
            'redis' => new RedisSessionStore(
                redis: $this->container->make(RedisFactory::class),
                connection: (string) ($driver['connection'] ?? 'default'),
                prefix: (string) ($driver['prefix'] ?? 'harness:'),
                // An assertion about infrastructure, not a preference — see the
                // exception text. Off unless the operator turns it on.
                durable: (bool) ($driver['durable'] ?? false),
            ),
            'database' => new DatabaseSessionStore(
                connection: $this->container->make(DatabaseManager::class)
                    ->connection($driver['connection'] ?? null),
                table: (string) ($driver['table'] ?? 'harness_session_state'),
            ),
            default => throw UnsafeStateConfiguration::unknownDriver($slot, $name),
        };
    }
}
