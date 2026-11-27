<?php

declare(strict_types=1);

namespace Prism\Harness\Sessions;

/**
 * The runner start a Session object is running (G-387).
 *
 * Shared by reference between the Session object a start's run uses and
 * every read-only view cloned from it, so ending the start ends it for a
 * view a host kept too. Never stored.
 *
 * @internal
 */
final class StartView
{
    public function __construct(public ?string $run) {}
}
