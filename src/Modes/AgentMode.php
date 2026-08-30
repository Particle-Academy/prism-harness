<?php

declare(strict_types=1);

namespace Prism\Harness\Modes;

final readonly class AgentMode
{
    /**
     * @param  list<string>  $tools
     * @param  list<string>  $skills
     */
    public function __construct(
        public string $name,
        public string $systemPrompt,
        public array $tools,
        public array $skills,
        public int $maxSteps,
    ) {}
}
