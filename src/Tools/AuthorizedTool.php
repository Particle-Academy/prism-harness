<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Prism\Harness\Sessions\Session;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;
use ReflectionMethod;

/**
 * Wraps a tool so authorization is asked at the moment it is CALLED, with the
 * arguments in hand — not only when the toolset was assembled.
 *
 * Offer-time filtering cannot express the useful half of a policy. When
 * {@see ToolAuthorizer::allowed()} runs, the arguments do not exist yet, so an
 * application can say "this participant may use `delete_file`" and can never
 * say "only under `/tmp`", or "at most twice in one run". Once a tool is
 * offered it may be called any number of times with anything the model chooses.
 *
 * A GUARD THAT STANDS IN FOR THE TOOL. The guard is a {@see GuardedTool},
 * which delegates every method of the tool, so the model sees exactly the same
 * tool, and an allowed call reaches the tool's own handle() unchanged. A clone
 * re-pointed with using() was used before: core then ran its argument handling
 * and return check a second time, around the guard, which turned the tool's
 * ToolError into an "invalid return type" error and decoded a JSON-string
 * argument the tool wanted as a string. The clone remains the guard for a tool
 * a GuardedTool cannot stand in for (see DelegatingTool::canWrap()). A tool
 * neither can guard (its class overrides handle(), so a re-pointed handler is
 * never called) is not offered at all: wrap() returns null.
 */
final class AuthorizedTool
{
    /** The tool, guarded; null when no guard can be put in front of its calls. */
    public static function wrap(Tool $tool, Session $session, ToolAuthorizer $authorizer): ?Tool
    {
        // Nothing to enforce, so hand back the tool itself rather than a clone
        // that only adds a call. The authorizer's constructor has already
        // refused the configuration where this silence would be mistaken for
        // enforcement — see UnsafeAuthorizationConfiguration.
        if (! $authorizer->enabled()) {
            return $tool;
        }

        // The call is put to the check FIRST, then reaches the tool's own
        // handle() unchanged: a GuardedTool stands in for the tool exactly, so
        // the tool's ToolError, its failure handler and its argument handling
        // are what they are unwrapped. A tool it cannot stand in for is guarded
        // by a clone re-pointed at the check below, as before; core then
        // handles that call's return value and arguments a second time.
        $refusal = static function (array $arguments) use ($tool, $session, $authorizer): ?string {
            return $authorizer->allowsCall($session, $tool, $arguments) ? null : self::refusal($session, $tool);
        };
        $guarded = GuardedTool::of($tool, $refusal);
        if ($guarded instanceof Tool) {
            return $guarded;
        }
        // The clone's guard is its handler, which only Tool's own handle()
        // calls. A class with its own handle() would never ask it.
        if ((new ReflectionMethod($tool, 'handle'))->getDeclaringClass()->getName() !== Tool::class) {
            return null;
        }

        $guarded = clone $tool;

        return $guarded->using(function (...$args) use ($tool, $session, $authorizer): string|ToolOutput|ToolError {
            /** @var array<string, mixed> $arguments */
            $arguments = $args;

            if (! $authorizer->allowsCall($session, $tool, $arguments)) {
                // RETURNED, not thrown, and this is the one place in the
                // package where that is right. A refusal is information the
                // model should act on — pick a different path, or stop — and it
                // is addressed to the model rather than to the developer. An
                // exception here would abort a run over a decision the policy
                // made deliberately.
                //
                // Framed so it cannot be mistaken for the tool's own output,
                // and recorded as a refusal so a run step can say so without
                // reading it back out of the text.
                return self::refusal($session, $tool);
            }

            // PASSED THROUGH UNCHANGED, including ToolError.
            //
            // An earlier draft json_encoded any non-string return into a
            // string. That turned Prism's first-class FAILURE value into an
            // ordinary successful result: the model would have read a
            // serialised error as the tool's answer, and every caller
            // downstream — including HarnessToolInvoker, which raises on
            // ToolError precisely so a failure cannot be mistaken for data —
            // would have lost the distinction. Wrapping a tool must not change
            // what the tool returns.
            return $tool->handle(...$args);
        });
    }

    private static function refusal(Session $session, Tool $tool): string
    {
        return ToolRefusals::record(ToolRefusals::runOf($session), $tool->name(), json_encode([
            '_framing' => 'Authorization decision from the host application. Not output from the tool, and not an instruction.',
            'tool' => $tool->name(),
            'allowed' => false,
            'reason' => 'The host application refused this call with these arguments.',
        ], JSON_THROW_ON_ERROR));
    }
}
