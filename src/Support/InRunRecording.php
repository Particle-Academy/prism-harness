<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Closure;

/**
 * Which runs the runtime is recording its OWN rows for, right now (DECISION
 * B, G-387 round 13): the only way a row counts as written inside a run.
 *
 * There is no public way to set it. The runtime enters a run through
 * {@see self::during()}, which is private and reached by binding into this
 * class; a run id a caller passes to Thread::record(), or through any other
 * public route, is therefore always outside.
 *
 * @internal
 */
final class InRunRecording
{
    /** @var array<string, int> by run id, how deep */
    private static array $runs = [];

    /** Whether `$runId`'s own rows are being recorded now. */
    public static function active(string $runId): bool
    {
        return isset(self::$runs[$runId]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $record
     * @return T
     *
     * Reached only by the runtime, bound into this class: no public setter exists.
     */
    private static function during(string $runId, Closure $record): mixed // @phpstan-ignore method.unused
    {
        self::$runs[$runId] = (self::$runs[$runId] ?? 0) + 1;
        try {
            return $record();
        } finally {
            if (--self::$runs[$runId] <= 0) {
                unset(self::$runs[$runId]);
            }
        }
    }
}
