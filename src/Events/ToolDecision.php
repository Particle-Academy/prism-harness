<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * One decision about a call to a tool that needs approval (G-326).
 *
 * Carries the arguments' DIGEST, never the arguments or the result: the sha256
 * of their RFC 8785 canonical JSON, identical in every language.
 * `$policyRevision` is the host policy's revision when the decision was made;
 * `$decidedBy` is `policy` or `person`.
 */
abstract readonly class ToolDecision extends HarnessEvent
{
    public function __construct(
        string $sessionKey,
        string $runId,
        public string $policyRevision,
        public ?string $server,
        public string $tool,
        public string $argsDigest,
        public string $decidedBy,
        ?string $harnessAgent = null,
    ) {
        parent::__construct($sessionKey, $runId, harnessAgent: $harnessAgent);
    }

    /** The event's name in every language. */
    abstract public function type(): string;

    /**
     * The shared shape: the same keys in every language.
     *
     * @return array{type: string, run_id: string, session_key: string, policy_revision: string, server: ?string, tool: string, args_digest: string, decided_by: string, harness_agent: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type(),
            'run_id' => $this->runId,
            'session_key' => $this->sessionKey,
            'policy_revision' => $this->policyRevision,
            'server' => $this->server,
            'tool' => $this->tool,
            'args_digest' => $this->argsDigest,
            'decided_by' => $this->decidedBy,
            'harness_agent' => $this->harnessAgent,
        ];
    }
}
