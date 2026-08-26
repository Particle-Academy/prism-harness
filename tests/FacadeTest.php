<?php

declare(strict_types=1);

use Prism\Harness\Facades\PrismHarness;
use Prism\Harness\PendingSession;

/**
 * The README has always shown `PrismHarness::for($user)` and there was no
 * facade behind it, so every example in it was a fatal error for anyone who
 * copied one. Every sibling package ships a facade (PrismMcp, PrismWorkspace);
 * this one was simply missed.
 *
 * The test resolves it through the container the way an application would,
 * rather than asserting the class exists — the failure mode was never a missing
 * file, it was a name that did not resolve.
 */
it('resolves the facade the documentation has always shown', function (): void {
    expect(PrismHarness::for(participant()))->toBeInstanceOf(PendingSession::class);
});

it('reaches the same manager instance the container holds', function (): void {
    expect(PrismHarness::getFacadeRoot())->toBe(app(Prism\Harness\PrismHarness::class));
});
