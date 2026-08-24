<?php

declare(strict_types=1);

namespace Prism\Harness\Enums;

/**
 * Whether a store's contents survive a deploy.
 *
 * This is the distinction the whole state layer turns on. Redis is the natural
 * home for live session state, but in most Laravel deployments it is a *cache* —
 * and a cache is disposable by definition. Something has to say which of the
 * two a configured store actually is, because the package cannot detect it and
 * guessing wrong loses a half-executed agent action rather than a cheap value.
 */
enum Durability: string
{
    /**
     * Contents may vanish at any time — a flush, an eviction, a deploy.
     *
     * Only safe for state whose loss degrades to a default: the active mode,
     * the selected model, run bookkeeping. Ask for it again and you get a
     * sensible answer.
     */
    case Volatile = 'volatile';

    /**
     * Contents survive until deliberately removed.
     *
     * Required for anything whose loss is a correctness failure rather than an
     * inconvenience — a pending tool approval is a half-executed action waiting
     * on a human, and it has to outlive the request, the worker, and a deploy.
     */
    case Durable = 'durable';

    public function isDurable(): bool
    {
        return $this === self::Durable;
    }
}
