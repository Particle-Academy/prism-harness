<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\Modes\AgentMode;

/**
 * Where a subagent's child gets its provider and model from (G-321 E).
 *
 * One rule, the same in PHP, TypeScript and Python, resolved per field:
 *
 *  1. the parent SESSION's override, only when the subagent declared
 *     `inherit_session_model: true`;
 *  2. the subagent declaration's own `provider` / `model`;
 *  3. the mode the child runs in;
 *  4. the parent's MODE, only when the child runs in the parent's agent;
 *  5. the child agent's default, then the global default.
 *
 * Tiers 1-2 sit ABOVE the child's mode and arrive here as `$provider` /
 * `$model`; tier 4 sits below it and arrives as `$parentModeProvider` /
 * `$parentModeModel`. The child session's own override slot is not read for a
 * child run: the runner owns that address, and a value found there could only
 * be left over from somewhere nobody chose.
 *
 * A parent mode's binding does not cross an agent boundary, because it belongs
 * to the parent agent: the reason to delegate to another agent is usually its
 * provider, and the parent's choice silently replacing it would defeat that.
 */
final readonly class ChildBinding
{
    public function __construct(
        public ?string $provider = null,
        public ?string $model = null,
        public ?string $parentModeProvider = null,
        public ?string $parentModeModel = null,
    ) {}

    public static function for(Subagent $subagent, ?string $parentSessionProvider, ?string $parentSessionModel, ?AgentMode $parentMode, bool $sameAgent): self
    {
        $inherit = $subagent->inheritSessionModel;

        return new self(
            provider: ($inherit ? $parentSessionProvider : null) ?? $subagent->provider,
            model: ($inherit ? $parentSessionModel : null) ?? $subagent->model,
            parentModeProvider: $sameAgent ? $parentMode?->provider : null,
            parentModeModel: $sameAgent ? $parentMode?->model : null,
        );
    }
}
