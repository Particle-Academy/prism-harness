<?php

declare(strict_types=1);

namespace Prism\Harness\Contracts;

use Prism\Harness\Tools\ToolPolicy;
use Prism\Harness\Tools\ToolRegistry;

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
     * A policy entry without `server` never matches a tool that returns one.
     * For a tool that comes out of a registered tool PROVIDER this is only a
     * claim: the registry scopes it to the provider's registered name instead
     * ({@see ToolRegistry::declarationOf()}).
     */
    public function toolServer(): ?string;

    /** Advisory: `read_only`, `write` or `destructive`. `destructive` is never auto-allowed. */
    public function toolTier(): ?string;
}
