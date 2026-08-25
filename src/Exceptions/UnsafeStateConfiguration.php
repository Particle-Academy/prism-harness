<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use RuntimeException;

/**
 * Thrown at resolve time when durable state is pointed at a volatile store.
 *
 * This exception exists because of a specific incident, not a hypothetical. A
 * sibling project in this workspace kept XP de-duplication state in a cache a
 * deploy could clear; a single `cache:clear` between two backfills would have
 * silently re-awarded every contribution. Nothing would have errored — the
 * numbers would just have been wrong.
 *
 * The same shape of mistake here is worse. Pending tool approvals are
 * half-executed agent actions waiting on a human. Losing them does not
 * degrade to a default; it drops work that a person was asked to authorise,
 * with no record that it existed.
 *
 * So the configuration is refused loudly at the point it is resolved, rather
 * than accepted and discovered later.
 */
final class UnsafeStateConfiguration extends RuntimeException
{
    public static function volatileDurableStore(string $slot, string $driver): self
    {
        return new self(
            "The harness [{$slot}] state slot is configured to use the [{$driver}] driver, which reports itself "
            .'as volatile. Durable state — threads and pending tool approvals — must survive a deploy, and a '
            ."volatile store cannot promise that.\n\n"
            ."Either point [{$slot}] at a durable driver such as [database], or, if this Redis really is "
            .'persistent (AOF or RDB, not a disposable cache), say so explicitly by setting '
            ."`durable => true` on the driver's config. That flag is an assertion about your infrastructure, "
            .'so it is off by default.'
        );
    }

    public static function unknownDriver(string $slot, string $name): self
    {
        return new self(
            "The harness [{$slot}] state slot names the driver [{$name}], which is not configured. "
            .'Add it under `harness.drivers`, or point the slot at one that exists.'
        );
    }
}
