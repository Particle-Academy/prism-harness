<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Illuminate\Contracts\Auth\Access\Gate;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Tool;

final readonly class ToolAuthorizer
{
    public function __construct(private Gate $gate, private bool $enabled = false) {}

    /**
     * @param  array<string, Tool>  $tools
     * @return list<Tool>
     */
    public function allowed(Session $session, array $tools): array
    {
        if (! $this->enabled) {
            return array_values($tools);
        }

        return array_values(array_filter(
            $tools,
            fn (Tool $tool): bool => $this->gate->forUser($session->participant())->allows('harness.tool', [$session, $tool]),
        ));
    }
}
