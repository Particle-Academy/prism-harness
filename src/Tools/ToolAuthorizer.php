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

    /** @param array<string, mixed> $agents */
    public function __construct(
        private Gate $gate,
        private bool $enabled = false,
        private array $agents = [],
        private ?string $agent = null,
        private string $offerAbility = self::ABILITY,
        private string $callAbility = self::CALL_ABILITY,
        private string $flag = self::FLAG,
    ) {
        // Refused here rather than tolerated, for the reason the exception
        // sets out: a defined policy that is never consulted looks like a
        // control to every reader and is not one.
        if (! $enabled && ($gate->has($offerAbility) || $gate->has($callAbility))) {
            throw UnsafeAuthorizationConfiguration::policyDefinedButDisabled($gate->has($offerAbility) ? $offerAbility : $callAbility, $flag);
        }
    }

    public function forSession(Session $session): self
    {
        if ($session->agent() === $this->agent) {
            return $this;
        }
        if ($this->agent !== null) {
            throw new \LogicException('Harness authorizer/session agent mismatch.');
        }
        $agent = $session->agent();
        $options = $this->agents[$agent] ?? [];
        if (! is_array($options)) {
            throw new \InvalidArgumentException('Agent authorization options must be a map.');
        }
        $enabled = array_key_exists('authorize_tools', $options) ? $options['authorize_tools'] : $this->enabled;
        $offer = array_key_exists('tool_ability', $options) ? $options['tool_ability'] : $this->offerAbility;
        $call = array_key_exists('tool_call_ability', $options) ? $options['tool_call_ability'] : $this->callAbility;
        if (! is_bool($enabled) || ! is_string($offer) || $offer === '' || ! is_string($call) || $call === '') {
            throw new \InvalidArgumentException('Malformed agent tool authorization options.');
        }

        return new self($this->gate, $enabled, agent: $agent, offerAbility: $offer, callAbility: $call, flag: 'prism-harness.agents.'.$agent.'.authorize_tools');
    }

    public function callAbility(): string
    {
        return $this->callAbility;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Whether the application has defined a per-call policy at all.
     *
     * `allowsCall()` returns true when none is defined, which is right for an
     * ordinary tool: the offer-time ability already decided, and asking a
     * policy that does not exist would refuse everything. But SILENCE IS NOT
     * AUTHORIZATION for an authority that must never be granted by accident —
     * see {@see TaskCompletionTool}, which refuses unless
     * the host has said yes to this specific call. Exposed so such a tool can
     * tell "allowed" from "nobody was asked".
     */
    public function hasCallPolicy(): bool
    {
        return $this->gate->has($this->callAbility);
    }

    /**
     * The tools a run may be OFFERED.
     *
     * @param  array<string, Tool>  $tools
     * @return list<Tool>
     */
    public function allowed(Session $session, array $tools): array
    {
        $scoped = $this->forSession($session);
        if ($scoped !== $this) {
            return $scoped->allowed($session, $tools);
        }
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
                fn (Tool $tool): bool => $this->gate->forUser($session->actor())->allows($this->offerAbility, [$session, $tool]),
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
        $scoped = $this->forSession($session);
        if ($scoped !== $this) {
            return $scoped->allowsCall($session, $tool, $arguments);
        }
        if (! $this->enabled || ! $this->gate->has($this->callAbility)) {
            return true;
        }

        return $this->gate->forUser($session->actor())->allows($this->callAbility, [$session, $tool, $arguments]);
    }
}
