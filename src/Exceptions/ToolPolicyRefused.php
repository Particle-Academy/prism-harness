<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use InvalidArgumentException;
use Prism\Harness\Contracts\HasErrorCode;

/**
 * A host tool policy names something that may never run without asking, or is
 * malformed (G-326). Thrown, never silently dropped, so the host learns of it.
 */
final class ToolPolicyRefused extends InvalidArgumentException implements HasErrorCode
{
    public static function because(string $reason): self
    {
        return new self("This tool policy is refused: {$reason}");
    }

    public function code(): string
    {
        return 'tool_policy_refused';
    }
}
