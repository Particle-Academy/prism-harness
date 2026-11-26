<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use LogicException;

/**
 * The application's event dispatcher cannot hold a resumed run's telemetry
 * until the approved calls are recorded.
 *
 * Resolving approvals relies on Laravel's event deferral internals (see
 * Prism\Harness\Subagents\DeferredEvents). Rather than run without them, and
 * let a telemetry listener run between an approved call and its record, the
 * harness refuses.
 */
final class UnsupportedEventDispatcher extends LogicException
{
    public static function notLaravel(string $type): self
    {
        return new self("unsupported_event_dispatcher: the harness needs Laravel's Illuminate\\Events\\Dispatcher (or an EventFake over it) to hold a resumed run's telemetry until its approved calls are recorded; the application's event dispatcher is [{$type}].");
    }

    public static function missingField(string $class, string $field): self
    {
        return new self("unsupported_event_dispatcher: [{$class}] has no [{$field}] property; this Laravel release changed the event deferral internals the harness relies on to hold a resumed run's telemetry until its approved calls are recorded. Pin a supported Laravel version and report it.");
    }
}
