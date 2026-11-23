<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

/**
 * A nested agent a parent run may call, and the authority it gets.
 *
 * The authority is DECLARED here rather than inherited. A subagent that ran
 * with whatever its parent happened to hold would make "narrowed toolset" a
 * description instead of a constraint — and the narrowing is the entire reason
 * to reach for a subagent rather than another turn of the parent.
 */
final readonly class Subagent
{
    public function __construct(
        public string $name,
        public string $description,
        public string $mode,
        public RunBudget $budget,
        /**
         * The scope suffix the child's own session and thread live under.
         *
         * Deterministic, so a cold worker resuming the tree lands on the same
         * child conversation instead of starting a fresh one. Defaults to the
         * subagent's name.
         */
        public ?string $scopeSuffix = null,
        /**
         * The provider and model the child runs on. They win over every mode,
         * the child's and the parent's, and lose only to a parent session
         * override this declaration asked to inherit.
         */
        public ?string $provider = null,
        public ?string $model = null,
        /**
         * The named agent the child runs in; null keeps the parent's. Another
         * agent is valid only when the parent MODE lists it in `agents`, which
         * ModeRegistry checks when the mode loads.
         */
        public ?string $agent = null,
        /**
         * Whether the child takes the parent SESSION's provider/model override.
         *
         * Off by default. An override is a choice one session's user made, and
         * a child that silently ran on it would spend against a choice nobody
         * made for the child.
         */
        public bool $inheritSessionModel = false,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $description = $config['description'] ?? null;
        $mode = $config['mode'] ?? null;

        return new self(
            name: $name,
            description: is_string($description) && $description !== ''
                ? $description
                : sprintf('Run the [%s] subagent and return its result.', $name),
            mode: is_string($mode) && $mode !== '' ? $mode : $name,
            budget: RunBudget::fromArray($config),
            scopeSuffix: is_string($config['scope'] ?? null) ? $config['scope'] : null,
            provider: is_string($config['provider'] ?? null) && $config['provider'] !== '' ? $config['provider'] : null,
            model: is_string($config['model'] ?? null) && $config['model'] !== '' ? $config['model'] : null,
            agent: is_string($config['agent'] ?? null) ? $config['agent'] : null,
            inheritSessionModel: ($config['inherit_session_model'] ?? false) === true,
        );
    }

    /**
     * The scope the child session resolves under.
     *
     * A DIFFERENT scope from the parent, which is what keeps this from
     * deadlocking. A session's lock is taken on its address, and a nested run
     * asking for the parent's address inside the parent's own lock is refused
     * immediately — `lock_wait` defaults to 0. Giving the child its own address
     * removes the contention rather than making the lock reentrant, which would
     * let a child mutate parent state mid-run: the precise thing the lock is for.
     */
    public function scopeUnder(string $parentScope): string
    {
        return $parentScope.'::sub::'.($this->scopeSuffix ?? $this->name);
    }
}
