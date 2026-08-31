<?php

declare(strict_types=1);

namespace Prism\Harness\Modes;

use Prism\Harness\Subagents\Subagent;

final readonly class AgentMode
{
    /**
     * @param  list<string>  $tools
     * @param  list<string>  $skills
     * @param  array<string, Subagent>  $subagents  nested agents this mode may call, by name
     * @param  list<string>  $requiresApproval  tools that must not run until a human says so
     */
    public function __construct(
        public string $name,
        public string $systemPrompt,
        public array $tools,
        public array $skills,
        public int $maxSteps,
        public array $subagents = [],
        public array $requiresApproval = [],
    ) {}

    /**
     * Whether a named tool needs a human before it runs in this mode.
     *
     * Declared PER MODE rather than on the tool, because the same tool is not
     * equally consequential everywhere: `execute_op` against a scratch project
     * is routine and against production is not, and the tool cannot tell which
     * it is in. `'*'` gates every tool the mode offers.
     */
    public function needsApproval(string $tool): bool
    {
        return in_array('*', $this->requiresApproval, true)
            || in_array($tool, $this->requiresApproval, true);
    }
}
