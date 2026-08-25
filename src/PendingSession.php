<?php

declare(strict_types=1);

namespace Prism\Harness;

use Illuminate\Database\Eloquent\Model;
use Prism\Harness\Sessions\Session;

/**
 * The `for($user)` half of `PrismHarness::for($user)->session('support')`.
 *
 * Exists so the participant and the scope are named at separate steps: the
 * participant is usually the authenticated user and comes from context, while
 * the scope is a decision the call site makes. Splitting them keeps the common
 * case readable without collapsing two different questions into one argument.
 */
class PendingSession
{
    public function __construct(
        protected readonly PrismHarness $harness,
        protected readonly Model $participant,
    ) {}

    public function session(?string $scope = null): Session
    {
        return $this->harness->session($this->participant, $scope);
    }
}
