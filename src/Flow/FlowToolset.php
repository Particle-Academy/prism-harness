<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use LogicException;
use Prism\Harness\Exceptions\ToolNotAvailable;
use Prism\Harness\Exceptions\UnboundFlowTools;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Tool;

/**
 * The tools one session may use from a workflow.
 *
 * It holds a session and an optional list of names, and no tools. Every use
 * asks the session again, so a workflow that resumes later sees the session's
 * current mode and authorization rather than what was true when it was built.
 * The names can only narrow what the session offers.
 *
 * Build one per workflow run, and take both adapters from it: it remembers
 * which call ids have run, for that run only and in memory. A run resumed in
 * another process starts with none.
 *
 * Obtain one with {@see Session::flowTools()}.
 */
final class FlowToolset
{
    /** @var array<string, true> The call ids whose handler has started in this run. */
    private array $ranCallIds = [];

    /** @var array<int, array{name: string, arguments: array<array-key, mixed>, callIds: list<string>}> */
    private array $handedOver = [];

    /** @param list<string>|null $names */
    private function __construct(
        private readonly Session $session,
        private readonly ?array $names,
    ) {}

    /**
     * @internal Use {@see Session::flowTools()}.
     *
     * @param  list<string>|null  $names
     */
    public static function of(Session $session, ?array $names = null): self
    {
        return new self($session, $names);
    }

    /**
     * @internal The adapters' constructor check.
     *
     * @param  array<string, Tool>|self  $tools
     *
     * @throws UnboundFlowTools when handed tools that did not come from a session
     */
    public static function from(array|self $tools): ?self
    {
        if ($tools instanceof self) {
            return $tools;
        }

        if ($tools !== []) {
            throw UnboundFlowTools::rawTools();
        }

        return null;
    }

    public function session(): Session
    {
        return $this->session;
    }

    /**
     * What the session offers right now, as schemas with nothing that can run.
     *
     * @return list<Tool>
     */
    public function offered(): array
    {
        return $this->session->flowRuntime()->flowOffer($this->session, $this->names);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (Tool $tool): string => $tool->name(), $this->offered());
    }

    /**
     * @internal Called by {@see PrismLlmClient}.
     *
     * @param  list<string>  $offered
     * @param  list<array{name: string, id: string, arguments: array<array-key, mixed>|null, callIds?: list<string>}>  $calls
     *
     * @throws ToolNotAvailable when a call's id has already run, before anything in the step does
     */
    public function admit(array $offered, array $calls): void
    {
        foreach ($calls as $call) {
            foreach ($call['callIds'] ?? [] as $callId) {
                if (isset($this->ranCallIds[$callId])) {
                    throw ToolNotAvailable::repeatedCall($call['name']);
                }
            }
        }

        $this->session->flowRuntime()->admitFlowCalls($this->session, $this->names, $offered, $calls);

        foreach ($calls as $call) {
            if (($call['callIds'] ?? []) !== [] && $call['arguments'] !== null) {
                $this->handedOver[] = ['name' => $call['name'], 'arguments' => $call['arguments'], 'callIds' => $call['callIds']];
            }
        }
    }

    /**
     * @internal Called by {@see HarnessToolInvoker}.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function run(string $tool, array $arguments): mixed
    {
        return $this->session->flowRuntime()->runFlowTool(
            $this->session,
            $this->names,
            $tool,
            $arguments,
            fn () => $this->recordRun($tool, $arguments),
        );
    }

    /** @internal Called by {@see PrismLlmClient}. */
    public function stepLimit(): int
    {
        return $this->session->flowRuntime()->flowStepLimit($this->session);
    }

    public function llmClient(?string $provider = null, ?string $model = null): PrismLlmClient
    {
        return new PrismLlmClient($provider, $model, $this->session, $this);
    }

    public function toolInvoker(): HarnessToolInvoker
    {
        return new HarnessToolInvoker($this);
    }

    /**
     * Mark the call this invocation answers. The engine passes a name and
     * arguments and no id, so it is matched to the oldest call handed over
     * with the same two. An invocation matching none is not a model's call.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    private function recordRun(string $tool, array $arguments): void
    {
        foreach ($this->handedOver as $index => $call) {
            if ($call['name'] === $tool && $call['arguments'] === $arguments) {
                foreach ($call['callIds'] as $callId) {
                    $this->ranCallIds[$callId] = true;
                }

                unset($this->handedOver[$index]);

                return;
            }
        }
    }

    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('A workflow toolset cannot be serialized. Build it from the session when the run resumes.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A workflow toolset cannot be restored. Build it from the session when the run resumes.');
    }
}
