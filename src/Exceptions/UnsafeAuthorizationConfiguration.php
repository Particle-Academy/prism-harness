<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use RuntimeException;

/**
 * Thrown at resolve time when a tool-authorization policy is defined but the
 * authorizer that would consult it is switched off.
 *
 * The dangerous shape is not `authorize_tools => false`. That is the documented
 * default and an app that never writes a policy is served correctly by it.
 *
 * The dangerous shape is an app that DEFINES a `harness.tool` ability —
 * deliberately, in an AuthServiceProvider, because someone decided which tools
 * an agent may run — and never turns the flag on. The policy exists. It is
 * never called. Every tool is offered to every run, and nothing anywhere says
 * so: no error, no log line, and a `Gate::define` sitting in the codebase as
 * evidence to the next reader that authorization is handled.
 *
 * That is the same failure the state layer already refuses, in a different
 * slot: a configuration that reports success while providing none of what it
 * appears to provide. {@see UnsafeStateConfiguration} names the precedent.
 *
 * It matters more once subagents exist. A nested run's whole safety story is
 * that its authority is narrowed independently of its parent; if the authorizer
 * is off, a child advertised as constrained runs with the full ambient toolset.
 * The narrowing would be documented, tested against the mode's declared list,
 * and absent at runtime.
 */
final class UnsafeAuthorizationConfiguration extends RuntimeException
{
    public static function policyDefinedButDisabled(string $ability, string $flag): self
    {
        return new self(
            "A [{$ability}] authorization policy is defined, but the harness tool authorizer is disabled "
            ."by `{$flag}`, so that policy is never consulted and every registered tool is offered to "
            ."every run.\n\n"
            .'Either enable the authorizer by setting `'.$flag.'` to true, or remove the '
            ."[{$ability}] ability so nothing suggests tool access is being restricted. "
            .'The one thing not to leave in place is both at once: a policy that reads as a control '
            .'and does nothing.'
        );
    }
}
