<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

/**
 * A failure that can be branched on without reading English.
 *
 * Decision 0004: every failure carries a stable machine-readable CODE, and the
 * human sentence is explicitly OUTSIDE the contract. A consumer that has to
 * `str_contains` a message to tell one failure from another turns every wording
 * improvement into a silent breaking change — and the suite that pinned the
 * prose becomes a reason not to improve it.
 *
 * PHP's own `Exception::$code` is an int, so the string code lives here rather
 * than being squeezed into `getCode()`. Three languages will spell the ACCESSOR
 * differently — a property in Python, a field in TypeScript, this method here —
 * and none of that is observable. THE CODE STRING IS.
 *
 * 0004 records that `particle-academy/prism` itself does not do this (finding
 * F-1) and that every downstream consumer is matching on prose because of it.
 * That is a reason to start here rather than an excuse not to.
 */
interface HasErrorCode
{
    /**
     * A stable, lower_snake_case identifier for this failure.
     *
     * Identical in every language. Free to be worded differently everywhere.
     */
    public function code(): string;
}
