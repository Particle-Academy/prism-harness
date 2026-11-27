<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Closure;
use Illuminate\Database\Connection;
use Prism\Harness\Models\ThreadMessage;

/**
 * How a thread's rows are read (G-387 round 16, item 7): ONCE per run, then
 * kept, so the readers a resume makes (what is paused, what is pending, what
 * stands, the replay) do not each read the whole thread again.
 *
 * Only while a session's lock is held ({@see self::scoped()}, entered by the
 * session's fenced lock): outside it every read is a fresh read, as before.
 * Inside it, every read first asks the store for the thread's fingerprint
 * (its row count, highest id and latest update), which costs the same
 * whatever the thread's length:
 *
 *  - unchanged: the rows kept are the rows;
 *  - rows appended since (any writer, this process or another): only those
 *    rows are read, and added;
 *  - anything else (a row deleted, a row changed in place): the whole thread
 *    is read again.
 *
 * A row changed in place through the model is forgotten at once (the model's
 * events). One changed in place by raw SQL in the same second as the latest
 * update is the one change a fingerprint cannot see; such a writer has table
 * access, which already decides everything here.
 *
 * Nothing is kept once the outermost scope ends.
 *
 * @internal
 */
final class ThreadReads
{
    /** At most this many threads are kept at once. */
    private const KEPT = 8;

    private static int $depth = 0;

    /** @var array<int, array{count: int, max_id: int, updated: ?string, rows: list<array{id: int, position: int, type: string, run_id: ?string, harness: bool, updated: ?string, payload: array<array-key, mixed>}>, view: ?ThreadView}> */
    private static array $kept = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function scoped(Closure $work): mixed
    {
        $leave = self::enter();
        try {
            return $work();
        } finally {
            $leave();
        }
    }

    /**
     * Enter a scope; the closure leaves it (once).
     *
     * @return Closure(): void
     */
    public static function enter(): Closure
    {
        self::$depth++;
        $left = false;

        return static function () use (&$left): void {
            if ($left) {
                return;
            }
            $left = true;
            self::$depth--;
            if (self::$depth <= 0) {
                self::$depth = 0;
                self::$kept = [];
            }
        };
    }

    /** Forget what is kept for a thread: a row of it changed in place. */
    public static function forget(int $threadId): void
    {
        unset(self::$kept[$threadId]);
    }

    /**
     * Rows of a thread a write just removed (the journal rows its results
     * answer): dropped from what is kept, so the next read reads only what
     * the write appended.
     *
     * @param  list<int>  $ids
     */
    public static function removed(int $threadId, array $ids): void
    {
        $kept = self::$kept[$threadId] ?? null;
        if ($kept === null || $ids === []) {
            return;
        }
        $rows = array_values(array_filter($kept['rows'], static fn (array $row): bool => ! in_array($row['id'], $ids, true)));
        self::$kept[$threadId] = ['count' => count($rows), 'max_id' => $rows === [] ? 0 : max(array_column($rows, 'id')),
            'updated' => self::latest($rows), 'rows' => $rows, 'view' => null];
    }

    /**
     * The rows of thread `$threadId`, oldest first, and the view over them.
     */
    public static function view(Connection $connection, int $threadId): ThreadView
    {
        if (self::$depth === 0) {
            return ThreadView::of(self::strip(self::read($connection, $threadId)));
        }
        $print = $connection->table('harness_thread_messages')->where('thread_id', $threadId)
            ->selectRaw('count(*) as n, max(id) as i, max(updated_at) as u')->first();
        $count = (int) ($print->n ?? 0);
        $maxId = (int) ($print->i ?? 0);
        $updated = is_string($print->u ?? null) ? $print->u : null;
        $kept = self::$kept[$threadId] ?? null;
        if ($kept !== null && $kept['count'] === $count && $kept['max_id'] === $maxId && $kept['updated'] === $updated) {
            return $kept['view'] ??= ThreadView::of(self::strip($kept['rows']));
        }
        $rows = null;
        if ($kept !== null && $count > $kept['count'] && $maxId > $kept['max_id']) {
            // Appended since: read only those.
            $added = self::read($connection, $threadId, $kept['max_id']);
            $merged = [...$kept['rows'], ...$added];
            if (count($merged) === $count && self::latest($merged) === $updated) {
                usort($merged, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);
                $rows = $merged;
            }
        }
        $rows ??= self::read($connection, $threadId);
        // Bounded: a scope a caller never left keeps no more than a few threads.
        unset(self::$kept[$threadId]);
        if (count(self::$kept) >= self::KEPT) {
            unset(self::$kept[array_key_first(self::$kept)]);
        }
        self::$kept[$threadId] = ['count' => count($rows), 'max_id' => $maxId, 'updated' => self::latest($rows), 'rows' => $rows, 'view' => null];

        return self::$kept[$threadId]['view'] = ThreadView::of(self::strip($rows));
    }

    /**
     * @return list<array{id: int, position: int, type: string, run_id: ?string, harness: bool, updated: ?string, payload: array<array-key, mixed>}>
     */
    private static function read(Connection $connection, int $threadId, ?int $afterId = null): array
    {
        $query = $connection->table('harness_thread_messages')->where('thread_id', $threadId);
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }
        $rows = [];
        // A schema that has not run the trust column's migration reads a row
        // as the harness's own by its run id, as before it existed.
        $trusted = ThreadMessage::hasTrustColumn($connection->getName());
        $columns = ['id', 'position', 'type', 'run_id', 'payload', 'updated_at', ...($trusted ? ['harness_written'] : [])];
        foreach ($query->orderBy('position')->get($columns) as $row) {
            $payload = is_string($row->payload) ? json_decode($row->payload, true) : null;
            $rows[] = ['id' => (int) $row->id, 'position' => (int) $row->position, 'type' => (string) $row->type,
                'run_id' => is_string($row->run_id) ? $row->run_id : null,
                'harness' => $trusted ? (bool) $row->harness_written : (is_string($row->run_id) && $row->run_id !== ''),
                'updated' => is_string($row->updated_at) ? $row->updated_at : null, 'payload' => is_array($payload) ? $payload : []];
        }

        return $rows;
    }

    /** @param list<array{updated: ?string}> $rows */
    private static function latest(array $rows): ?string
    {
        $latest = null;
        foreach ($rows as $row) {
            if ($row['updated'] !== null && ($latest === null || strcmp($row['updated'], $latest) > 0)) {
                $latest = $row['updated'];
            }
        }

        return $latest;
    }

    /**
     * @param  list<array{id: int, position: int, type: string, run_id: ?string, harness: bool, updated: ?string, payload: array<array-key, mixed>}>  $rows
     * @return list<array{id: int, position: int, type: string, run_id: ?string, harness: bool, payload: array<array-key, mixed>}>
     */
    private static function strip(array $rows): array
    {
        return array_map(static function (array $row): array {
            unset($row['updated']);

            return $row;
        }, $rows);
    }
}
