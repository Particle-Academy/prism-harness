<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Prism\Harness\Contracts\HasErrorCode;
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
final class UnsafeStateConfiguration extends RuntimeException implements HasErrorCode
{
    /**
     * One code for every constructor here, deliberately.
     *
     * All of them say the same thing to a consumer branching on failures —
     * durable state has been pointed somewhere it cannot survive — and the
     * differences between them are which slot and why, which is what the
     * message is for. See decision 0004.
     */
    #[\Override]
    public function code(): string
    {
        return 'unsafe_state_configuration';
    }

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

    /**
     * A task list is durable state, and the same rule applies to it.
     *
     * Losing a half-finished task list is a correctness failure rather than a
     * degradation to a default: a list that vanishes on a deploy is
     * INDISTINGUISHABLE FROM A FINISHED ONE. The agent resolves the same
     * session, finds nothing left to do, and reports success — which is the
     * one wrong answer that looks exactly like the right one.
     *
     * Refused when the source is constructed rather than when the first task
     * is claimed, so a misconfiguration cannot lie dormant until a run is
     * already underway.
     */
    public static function volatileTaskList(string $list, string $store): self
    {
        return new self(
            "The agent task list [{$list}] is backed by [{$store}], which reports itself as volatile. A task "
            .'list must survive the request, the worker, a crash and a deploy: losing a half-finished list is '
            ."not a cache miss, it is a run that reports success having silently dropped its remaining work.\n\n"
            .'Point it at a durable store — the harness `durable` slot already is one — or, if this Redis '
            .'really is persistent (AOF or RDB, not a disposable cache), say so explicitly by setting '
            ."`durable => true` on the driver's config."
        );
    }

    /**
     * A lease of zero or less is REFUSED, not clamped to a usable minimum.
     *
     * Clamping is the friendlier choice and it is the one this repository has
     * already been bitten by: a shipped configuration that silently becomes a
     * different configuration is how a Redis default broke every fresh install
     * while the suite stayed green. An operator who writes `lease_seconds => 0`
     * has said something they did not mean, and a package that quietly
     * substitutes 1 has taken the only chance anyone had to notice.
     *
     * It is refused when the source is CONSTRUCTED, before any task is claimed,
     * so nothing is half-done when it fires.
     */
    public static function unusableLease(int $seconds): self
    {
        return new self(
            "A task lease of {$seconds} second(s) cannot hold anything: it expires at or before the moment "
            .'it is granted, so every task would be claimable by every worker at once and a claim would '
            ."guarantee nothing.\n\n"
            .'Set `prism-harness.tasks.lease_seconds` to a positive number of seconds — long enough for a '
            .'model call plus its tool work. It is not clamped to a usable value on your behalf, because a '
            .'configuration that silently becomes a different configuration is one nobody gets to notice.'
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
