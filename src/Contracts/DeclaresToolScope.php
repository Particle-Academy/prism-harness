<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Tools\ToolPolicy;

/**
 * What a tool declares about itself for the host tool policy (G-326).
 *
 * Implemented by a Prism `Tool` subclass. Both are read by
 * {@see ToolPolicy}, never by the model.
 */
interface DeclaresToolScope
{
    /**
     * The MCP server this tool comes from, or null for a native harness tool.
     *
     * Set by the code that connected the server, never taken from the
     * server's own answer. A policy entry without `server` never matches a
     * tool that returns one.
     */
    public function toolServer(): ?string;

    /** Advisory: `read_only`, `write` or `destructive`. `destructive` is never auto-allowed. */
    public function toolTier(): ?string;
}
