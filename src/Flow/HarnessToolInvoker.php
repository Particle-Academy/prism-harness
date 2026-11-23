<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use FancyFlow\Nodes\Support\ToolInvoker;
use Prism\Harness\Exceptions\ToolNotAvailable;
use Prism\Harness\Exceptions\UnboundFlowTools;
use Prism\Harness\Sessions\Session;
use Prism\Prism\ValueObjects\ToolError;
use Throwable;

/**
 * fancy-flow's `tool_use` node and agent tool calls, routed to Prism tools.
 *
 * OPTIONAL, for the same reason as {@see PrismLlmClient}: fancy-flow needs PHP
 * 8.4 and this package supports 8.2.
 *
 * What it can run is what {@see FlowToolset} says the session offers at the
 * moment of the call. A call that needs approval fails the node, because a
 * workflow node has no way to ask a person.
 *
 * A tool's result is returned as the node's value. It is NOT interpreted here:
 * whatever the tool returned is what the workflow sees and what the model is
 * told, so a tool that returns a string returns a string.
 */
final class HarnessToolInvoker implements ToolInvoker
{
    private readonly ?FlowToolset $toolset;

    /**
     * @param  FlowToolset|array{}  $tools  from {@see Session::flowTools()}
     *
     * @throws UnboundFlowTools when given tools that did not come from a session
     */
    public function __construct(array|FlowToolset $tools = [])
    {
        $this->toolset = FlowToolset::from($tools);
    }

    /**
     * @param  array<string, mixed>  $args
     *
     * @throws ToolNotAvailable when the name was not offered to this invoker
     */
    public function invoke(string $tool, array $args = []): mixed
    {
        if (! $this->toolset instanceof FlowToolset) {
            // Thrown rather than returned as an error string. A model that
            // reads "unknown tool" as a result will try a different name and
            // burn the step budget guessing; the node should fail so the
            // workflow author sees which name was wrong.
            throw ToolNotAvailable::named($tool, []);
        }

        try {
            $result = $this->toolset->run($tool, $args);

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
