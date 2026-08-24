<?php

declare(strict_types=1);

namespace Prism\Harness\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Prism\Harness\Support\MessageMapper;
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
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'position' => 'integer',
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
