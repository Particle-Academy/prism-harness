<?php

declare(strict_types=1);

namespace Prism\Harness\Flow;

use LogicException;
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
 * Obtain one with {@see Session::flowTools()}.
 */
final class FlowToolset
{
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
     * @param  list<array{name: string, id: string, arguments: array<array-key, mixed>|null}>  $calls
     */
    public function admit(array $offered, array $calls): void
    {
        $this->session->flowRuntime()->admitFlowCalls($this->session, $this->names, $offered, $calls);
    }

    /**
     * @internal Called by {@see HarnessToolInvoker}.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    public function run(string $tool, array $arguments): mixed
    {
        return $this->session->flowRuntime()->runFlowTool($this->session, $this->names, $tool, $arguments);
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
