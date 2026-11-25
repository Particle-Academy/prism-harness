<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

/**
 * The call does not run: a person denied it or never answered (`person`), or
 * the host revoked the policy that had let it run before it started, or a
 * workflow node's call was refused (`policy`).
 *
 * `$reason` says why a workflow node's call was refused: `arguments`,
 * `approval`, `authorization` or `check_failed`. It is null for every other
 * denial. It is PHP-only and stays out of {@see self::toArray()}, which keeps
 * the shape every language shares.
 */
final readonly class ToolDenied extends ToolDecision
{
    public function __construct(
        string $sessionKey,
        string $runId,
        string $policyRevision,
        ?string $server,
        string $tool,
        string $argsDigest,
        string $decidedBy,
        ?string $harnessAgent = null,
        public ?string $reason = null,
    ) {
        parent::__construct($sessionKey, $runId, $policyRevision, $server, $tool, $argsDigest, $decidedBy, $harnessAgent);
    }

    public function type(): string
    {
        return 'tool_denied';
    }
}
