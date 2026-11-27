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
    /** @var array<string, Closure(): void> */
    private array $settles = [];

    /** @param Closure(): void $settle */
    public function put(string $runId, Closure $settle): void
    {
        $this->settles[$runId] = $settle;
    }

    /** @return (Closure(): void)|null */
    public function take(string $runId): ?Closure
    {
        $settle = $this->settles[$runId] ?? null;
        unset($this->settles[$runId]);

        return $settle;
    }
}
