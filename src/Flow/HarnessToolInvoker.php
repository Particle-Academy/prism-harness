<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use FancyFlow\Nodes\Support\ToolInvoker;
use Prism\Harness\Exceptions\ToolNotAvailable;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Throwable;

/**
 * fancy-flow's `tool_use` node and agent tool calls, routed to Prism tools.
 *
 * OPTIONAL, for the same reason as {@see PrismLlmClient}: fancy-flow needs PHP
 * 8.4 and this package supports 8.2.
 *
 * THE ALLOWLIST IS THE CONSTRUCTOR, and that is a deliberate limit rather than
 * a design. This package has no tool registry and no permission model — the
 * README lists both as planned — so there is nothing here to consult about
 * whether a caller MAY run a tool. What exists is the set handed in, and a name
 * outside it is refused.
 *
 * That is weaker than a permission model and stronger than the alternative: an
 * invoker that resolved tools from a container would let a workflow author name
 * any bound tool, including ones the flow was never meant to reach. When the
 * harness grows real permissions, this is where they attach.
 *
 * A tool's result is returned as the node's value. It is NOT interpreted here:
 * whatever the tool returned is what the workflow sees and what the model is
 * told, so a tool that returns a string returns a string.
 */
final class HarnessToolInvoker implements ToolInvoker
{
    /**
     * @param  array<string, Tool>  $tools  the only tools this invoker will run, by name
     */
    public function __construct(private readonly array $tools = []) {}

    /**
     * @param  array<string, mixed>  $args
     *
     * @throws ToolNotAvailable when the name was not offered to this invoker
     */
    public function invoke(string $tool, array $args = []): mixed
    {
        if (! isset($this->tools[$tool])) {
            // Thrown rather than returned as an error string. A model that
            // reads "unknown tool" as a result will try a different name and
            // burn the step budget guessing; the node should fail so the
            // workflow author sees which name was wrong.
            throw ToolNotAvailable::named($tool, array_keys($this->tools));
        }

        try {
            $result = $this->tools[$tool]->handle(...$args);

            if ($result instanceof ToolError) {
                // Prism has a first-class failure VALUE as well as exceptions:
                // a tool can report failure by returning ToolError without
                // throwing. Passing that object through would put it in the
                // workflow's data as something opaque, and a later node would
                // treat a failure as a result. Raised so both failure shapes
                // reach the run the same way.
                throw ToolNotAvailable::reported($tool, $result->message);
            }

            return $result;
        } catch (ToolNotAvailable $e) {
            throw $e;
        } catch (Throwable $e) {
            // A tool that throws is a fact about the run, and the workflow's
            // audit trail should carry the reason rather than an empty result.
            // Rethrown with the tool named, because a bare exception from deep
            // inside a handler does not say which node produced it.
            throw ToolNotAvailable::failed($tool, $e);
        }
    }
}
