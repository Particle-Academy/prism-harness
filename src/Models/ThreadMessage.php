<?php

declare(strict_types=1);

namespace Prism\Harness\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Support\MessageMapper;
use Prism\Harness\Support\ThreadReads;
use Prism\Prism\Contracts\Message;

/**
 * One turn in a stored conversation.
 *
 * `type` is a real column rather than a key inside the payload so the four
 * message kinds can be queried and indexed — counting tool results or finding
 * the last assistant turn should not mean unpacking JSON for every row.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $position
 * @property string $type
 * @property string|null $run_id
 * @property bool $harness_written whether the harness runtime wrote the row (G-387 round 16): never mass-assignable
 * @property array<string, mixed> $payload
 */
class ThreadMessage extends Model
{
    protected $table = 'harness_thread_messages';

    /** @var list<string> */
    protected $fillable = [
        'thread_id',
        'position',
        'type',
        'run_id',
        'payload',
    ];

    /** @var array<string, bool> by connection name: whether the trust column exists */
    private static array $trusted = [];

    /**
     * Whether this connection's schema has the trust column (G-387 round 16,
     * migration 0001_01_01_000008). A database that has not run it yet FAILS
     * CLOSED (round 17): no row is read as the harness's own, whatever run id
     * it carries, no run starts ({@see RunNotPermitted::notMigrated()}), and
     * a warning says so once. A run id is never a trust signal.
     *
     * @internal
     */
    public static function hasTrustColumn(?string $connection): bool
    {
        $name = $connection ?? (string) config('database.default');
        if (! array_key_exists($name, self::$trusted)) {
            self::$trusted[$name] = Schema::connection($connection)->hasColumn('harness_thread_messages', 'harness_written');
            if (! self::$trusted[$name]) {
                Log::warning('The harness_thread_messages.harness_written column is missing: run the prism-harness migrations. Until then no harness run starts, and no row is read as written by the harness.');
            }
        }

        return self::$trusted[$name];
    }

    /**
     * Forget what {@see self::hasTrustColumn()} read of each schema: after a
     * migration runs (or is rolled back) in the same process.
     *
     * @internal
     */
    public static function forgetSchema(): void
    {
        self::$trusted = [];
    }

    protected static function booted(): void
    {
        // A row changed or removed in place: what a locked operation kept of
        // its thread is no longer it (G-387 round 16, ThreadReads).
        $forget = static function (self $row): void {
            ThreadReads::forget((int) $row->thread_id);
        };
        static::updated($forget);
        static::deleted($forget);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'position' => 'integer',
            'harness_written' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class, 'thread_id');
    }

    public function toPrismMessage(): Message
    {
        return MessageMapper::fromArray($this->type, $this->payload);
    }
}
