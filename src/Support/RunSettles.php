<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Closure;

/**
 * What settles a runner start (G-387), by run id: taken once, by whichever
 * path ends the run, before that path writes the run's end or emits its
 * terminal event. In memory only.
 *
 * @internal
 */
final class RunSettles
{
    /** @var array<string, Closure(bool): void> */
    private array $settles = [];

    /** @var array<string, true> the starts whose task row this runtime wrote */
    private array $recorded = [];

    /** @param Closure(bool): void $settle called with whether the run failed */
    public function put(string $runId, Closure $settle): void
    {
        $this->settles[$runId] = $settle;
        unset($this->recorded[$runId]);
    }

    /**
     * Record that `$runId` wrote its rows, a runner start's task row first,
     * once the write returned (round 13). Only for a start not yet settled.
     */
    public function markRecorded(string $runId): void
    {
        if (isset($this->settles[$runId])) {
            $this->recorded[$runId] = true;
        }
    }

    /** Whether this runtime wrote `$runId`'s task row. */
    public function recorded(string $runId): bool
    {
        return isset($this->recorded[$runId]);
    }

    /** @return (Closure(bool): void)|null */
    public function take(string $runId): ?Closure
    {
        $settle = $this->settles[$runId] ?? null;
        unset($this->settles[$runId]);

        return $settle === null ? null : function (bool $failed) use ($settle, $runId): void {
            try {
                $settle($failed);
            } finally {
                unset($this->recorded[$runId]);
            }
        };
    }
}
