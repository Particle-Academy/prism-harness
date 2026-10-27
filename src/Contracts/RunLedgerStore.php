<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Closure;

/** Trusted root-run accounting, independent of ephemeral session state. */
interface RunLedgerStore
{
    /** @param array<string, mixed> $state Refuse an existing root; never reset it. */
    public function create(string $rootRunId, array $state): void;

    /** @return array<string, mixed> Refuse a missing or corrupt root. */
    public function read(string $rootRunId): array;

    /**
     * @template TReturn
     *
     * @param  Closure(array<string, mixed>): array{state: array<string, mixed>, result: TReturn}  $operation
     * @return TReturn
     */
    public function update(string $rootRunId, Closure $operation): mixed;
}
