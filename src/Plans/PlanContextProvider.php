<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

use Prism\Harness\Context\ContextProvider;
use Prism\Harness\Sessions\Session;

/**
 * Puts the session's plan in the system prompt of every turn (G-360), so the
 * agent does not forget its own plan between steps. Adds nothing when plans
 * are off for the session or there is no live plan.
 *
 * The block is computed per run and never written to the thread.
 */
final class PlanContextProvider implements ContextProvider
{
    public function __construct(private readonly Plans $plans) {}

    public function context(Session $session, string $prompt): string
    {
        return $this->plans->context($session, $prompt);
    }
}
