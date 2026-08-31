<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Illuminate\Contracts\Auth\Access\Gate;
use Prism\Harness\Exceptions\UnsafeAuthorizationConfiguration;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Tool;

final readonly class ToolAuthorizer
{
    /**
     * The ability an application defines to say which tools a run may be
     * offered. Named rather than inlined because the boot-time check and the
     * filter must agree about it — a check watching a different string than
     * the filter consults would pass while guarding nothing.
     */
    public const ABILITY = 'harness.tool';

    /**
     * The ability for an INDIVIDUAL call, consulted once arguments exist.
     *
     * Separate from ABILITY because the two answer different questions: whether
     * a tool may be OFFERED to a run, and whether THIS invocation of it — with
     * these arguments, this many calls in — may proceed.
     */
    public const CALL_ABILITY = 'harness.tool.call';

    private const FLAG = 'prism-harness.agent.authorize_tools';

    public function __construct(private Gate $gate, private bool $enabled = false)
    {
        // Refused here rather than tolerated, for the reason the exception
        // sets out: a defined policy that is never consulted looks like a
        // control to every reader and is not one.
        if (! $enabled && $gate->has(self::ABILITY)) {
            throw UnsafeAuthorizationConfiguration::policyDefinedButDisabled(self::ABILITY, self::FLAG);
        }
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The tools a run may be OFFERED.
     *
     * @param  array<string, Tool>  $tools
     * @return list<Tool>
     */
    public function allowed(Session $session, array $tools): array
    {
        if (! $this->enabled) {
            return array_values($tools);
        }

        return array_values(array_map(
            // Each survivor is wrapped so the SAME policy is asked again when
            // the tool is actually called, with arguments. Offer-time filtering
            // alone cannot bound how a tool is used, only whether it is present.
            fn (Tool $tool): Tool => AuthorizedTool::wrap($tool, $session, $this),
            array_filter(
                $tools,
                fn (Tool $tool): bool => $this->gate->forUser($session->participant())->allows(self::ABILITY, [$session, $tool]),
            ),
        ));
    }

    /**
     * Whether THIS call, with THESE arguments, may proceed.
     *
     * Offer-time filtering cannot express this: at the moment the toolset is
     * assembled the arguments do not exist yet, so a policy can say "may use
     * delete_file" and never "only under /tmp". Consulted per invocation.
     *
     * Returns true when the authorizer is disabled, matching `allowed()` — the
     * constructor has already refused the configuration where that silence
     * would be mistaken for enforcement.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function allowsCall(Session $session, Tool $tool, array $arguments): bool
    {
        if (! $this->enabled || ! $this->gate->has(self::CALL_ABILITY)) {
            return true;
        }

        return $this->gate->forUser($session->participant())->allows(self::CALL_ABILITY, [$session, $tool, $arguments]);
    }
}
