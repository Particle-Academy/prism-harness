<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use LogicException;

/**
 * A workflow adapter was given tools that did not come from a session.
 *
 * A wiring error, so it is raised when the adapter is built rather than when a
 * node first runs, and it names the call that replaces the wiring.
 */
final class UnboundFlowTools extends LogicException
{
    public static function rawTools(): self
    {
        return new self(
            'Workflow adapters no longer accept a plain array of tools. Build them from a session: '
            .'$tools = $session->flowTools(); $tools->llmClient(); $tools->toolInvoker();'
        );
    }

    public static function otherSession(): self
    {
        return new self(
            'This toolset belongs to a different session. Build the adapters from the same session '
            .'with $session->flowTools().'
        );
    }
}
