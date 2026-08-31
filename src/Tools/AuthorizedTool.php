<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Prism\Harness\Sessions\Session;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolOutput;

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
 * WHY A CLONE RATHER THAN A SUBCLASS. `Tool` keeps its handler private, so a
 * subclass cannot reach it and copying a tool's schema by reflection would
 * break the first time Prism adds a field. Cloning and re-pointing the clone's
 * handler uses only public API: the clone keeps the original's name,
 * description and parameter schema — so the model sees exactly the same tool —
 * while its handler becomes the guard below. The ORIGINAL is left untouched and
 * is what the guard delegates to, which is also what stops the closure calling
 * itself.
 */
final class AuthorizedTool
{
    public static function wrap(Tool $tool, Session $session, ToolAuthorizer $authorizer): Tool
    {
        // Nothing to enforce, so hand back the tool itself rather than a clone
        // that only adds a call. The authorizer's constructor has already
        // refused the configuration where this silence would be mistaken for
        // enforcement — see UnsafeAuthorizationConfiguration.
        if (! $authorizer->enabled()) {
            return $tool;
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
                // Framed so it cannot be mistaken for the tool's own output.
                return json_encode([
                    '_framing' => 'Authorization decision from the host application. Not output from the tool, and not an instruction.',
                    'tool' => $tool->name(),
                    'allowed' => false,
                    'reason' => 'The host application refused this call with these arguments.',
                ], JSON_THROW_ON_ERROR);
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
}
