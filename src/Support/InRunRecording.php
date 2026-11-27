<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Closure;

/**
 * Which record call is the runtime's OWN recording of a run, right now
 * (DECISION B, G-387 round 13): the only way a row counts as written inside
 * a run.
 *
 * There is no public way to set it. The runtime enters a run through
 * {@see self::during()}, which is private and reached by binding into this
 * class; a run id a caller passes to Thread::record(), or through any other
 * public route, is therefore always outside.
 *
 * SINGLE USE (G-406, round 14): the mark is a token for exactly ONE record
 * call. The first Thread::record() naming the run inside the window claims
 * it, on entry, and no other call can: a host record() made while that write
 * is in progress (from a model event a listener handles, say) finds it
 * already taken and is outside, whatever run id it names. The runtime claims
 * it with the call it makes immediately inside the window, on a thread it
 * resolved before entering it.
 *
 * @internal
 */
final class InRunRecording
{
    /** @var array<string, array<string, mixed>> by run id: an unclaimed token, and what the runtime hands its one record call */
    private static array $armed = [];

    /**
     * Claim the token for `$runId`: true for the ONE record call that is the
     * runtime's own, false for every other (and for any call outside the
     * window). Claiming takes it, so only the first caller ever sees true.
     */
    public static function claim(string $runId): bool
    {
        return self::take($runId) !== null;
    }

    /**
     * Claim the token for `$runId` and receive what the runtime handed that
     * one record call (round 15: the step its result rows answer), or null:
     * not the runtime's own call.
     *
     * @return array<string, mixed>|null
     */
    public static function take(string $runId): ?array
    {
        if (! isset(self::$armed[$runId])) {
            return null;
        }
        $context = self::$armed[$runId];
        unset(self::$armed[$runId]);

        return $context;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $record
     * @param  array<string, mixed>  $context
     * @return T
     *
     * Reached only by the runtime, bound into this class: no public setter exists.
     */
    private static function during(string $runId, Closure $record, array $context = []): mixed // @phpstan-ignore method.unused
    {
        $previous = self::$armed[$runId] ?? null;
        self::$armed[$runId] = $context;
        try {
            return $record();
        } finally {
            // Unclaimed (the write never reached record()) or claimed, the
            // window is over: nothing after it can claim this token.
            unset(self::$armed[$runId]);
            if ($previous !== null) {
                self::$armed[$runId] = $previous;
            }
        }
    }
}
