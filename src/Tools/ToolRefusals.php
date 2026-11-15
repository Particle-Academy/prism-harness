<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Prism\Harness\Sessions\Session;

/**
 * Which tool results were REFUSALS Harness produced, said at the point of refusal.
 *
 * A refusal Harness hands the model — an authorization decision, a task
 * completion gate, a subagent the tree would not let run — travels to the model
 * as an ordinary string result, on purpose: it is information the model should
 * act on. Core therefore reports it as a successful tool call, and nothing on
 * the result says it was refused. Recovering that from the TEXT would be a
 * guess any tool could imitate, so the site that refuses records it here, and
 * the run step stream reads it back.
 *
 * SCOPED TO ONE RUN. A record is keyed by the run that refused, only that run
 * can consume it, and the runtime forgets a run's records when the run ends —
 * on every path, success or failure, streamed or not. Under Octane or a
 * long-lived worker this state outlives the request, so a record that any run
 * could consume would let one session's refusal relabel another session's
 * real result.
 *
 * In-process. A refusal made in a concurrent child process is not seen here,
 * and reads as an ordinary result; that is the limit of a signal core does not
 * carry.
 *
 * @internal
 */
final class ToolRefusals
{
    /** Runs, not records: a run that never ended (a killed process) cannot grow this without bound. */
    private const RUN_LIMIT = 64;

    /** @var array<string, array<string, int>> run id => (tool, result) key => count */
    private static array $pending = [];

    /**
     * The run a refusal belongs to: the session's run while it is running.
     */
    public static function runOf(Session $session): ?string
    {
        $run = $session->run();

        return is_array($run) && ($run['status'] ?? null) === 'running' && is_string($run['id'] ?? null)
            ? $run['id'] : null;
    }

    /**
     * Record that `$result` is a refusal of `$tool` in `$runId`, and hand it
     * back unchanged. Outside a run there is nothing to attribute it to, so
     * nothing is recorded.
     */
    public static function record(?string $runId, string $tool, string $result): string
    {
        if ($runId === null) {
            return $result;
        }
        $key = self::key($tool, $result);
        // Re-inserted at the end, so the run most recently active is the last evicted.
        $records = self::$pending[$runId] ?? [];
        unset(self::$pending[$runId]);
        $records[$key] = ($records[$key] ?? 0) + 1;
        self::$pending[$runId] = $records;
        if (count(self::$pending) > self::RUN_LIMIT) {
            unset(self::$pending[array_key_first(self::$pending)]);
        }

        return $result;
    }

    /**
     * Whether `$runId` recorded this result as a refusal; consumes one record.
     */
    public static function consume(string $runId, string $tool, string $result): bool
    {
        $key = self::key($tool, $result);
        if (! isset(self::$pending[$runId][$key])) {
            return false;
        }
        if (--self::$pending[$runId][$key] === 0) {
            unset(self::$pending[$runId][$key]);
        }
        if (self::$pending[$runId] === []) {
            unset(self::$pending[$runId]);
        }

        return true;
    }

    /** Forget everything a run recorded. Called when the run ends, on every path. */
    public static function forget(string $runId): void
    {
        unset(self::$pending[$runId]);
    }

    /** @internal For tests: how many runs currently hold records. */
    public static function runsHeld(): int
    {
        return count(self::$pending);
    }

    private static function key(string $tool, string $result): string
    {
        return hash('sha256', $tool."\0".$result);
    }
}
