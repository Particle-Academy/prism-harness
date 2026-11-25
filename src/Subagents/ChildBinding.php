<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use InvalidArgumentException;
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

    /**
     * Which binding a turn runs on, and whether it is saved with the session.
     * The one rule every language's runtime asks, pinned by the harness-plans
     * corpus.
     *
     *  - `runner`: the subagent runner's own context starts the child; its
     *    binding is used and saved with the child's session.
     *  - `saved`: any other turn on a session with a saved binding uses it. A
     *    binding on a context the host supplied never replaces it.
     *  - `refused`: a session the runner started as a child (`$linked`) that
     *    has no saved binding is refused (`child_binding_missing`) before any
     *    request, rather than run on a provider nobody chose for it.
     *  - `host`: a host context's binding on a session that was never a child
     *    is used for that turn only; it is never saved.
     *  - `root`: otherwise the root rules.
     *
     * @param  string  $context  none, metadata, runner or host, as {@see RunTrees::contextKind()}
     * @return array{binding: 'runner'|'saved'|'refused'|'host'|'root', save: bool}
     */
    public static function route(string $context, bool $suppliedBinding, bool $savedBinding, bool $linked): array
    {
        if (! in_array($context, ['none', 'metadata', 'runner', 'host'], true)) {
            throw new InvalidArgumentException('Unknown run context kind ['.$context.'].');
        }
        if ($context === 'runner' && $suppliedBinding) {
            return ['binding' => 'runner', 'save' => true];
        }
        $binding = $savedBinding ? 'saved' : ($linked ? 'refused' : ($suppliedBinding ? 'host' : 'root'));

        return ['binding' => $binding, 'save' => false];
    }

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
