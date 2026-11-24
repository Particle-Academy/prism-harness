<?php

declare(strict_types=1);

use Prism\Harness\Exceptions\ToolPolicyRefused;
use Prism\Harness\Tools\ToolPolicy;

// G-360: a plan is a person's agreement. No host policy may let a plan tool
// run without asking, however the name is spelled.

it('refuses a policy that names a plan tool, in any spelling', function (string $name): void {
    expect(fn () => ToolPolicy::fromArray(['revision' => 'r1', 'allow' => [['tool' => $name]], 'honor' => true]))
        ->toThrow(ToolPolicyRefused::class);
})->with([
    'plan_draft' => 'plan_draft',
    'plan_propose_change' => 'plan_propose_change',
    'plan_pause_for_person' => 'plan_pause_for_person',
    'plan_complete_step' => 'plan_complete_step',
    'upper case' => 'PLAN_DRAFT',
    'trailing space' => 'plan_draft ',
    'trailing NBSP' => "plan_draft\u{00A0}",
    'leading ZWSP' => "\u{200B}plan_draft",
    'Cyrillic a' => "pl\u{0430}n_draft",
    'scoped to a server' => 'plan_draft',
]);

it('never lets a plan tool run under a policy, even one built without the tool list', function (): void {
    $policy = ToolPolicy::fromArray(['revision' => 'r1', 'allow' => [['tool' => 'search']], 'honor' => true]);

    foreach (ToolPolicy::RESERVED_TOOLS as $name) {
        expect($policy->allows(['name' => $name]))->toBeFalse()
            ->and(ToolPolicy::isPersonOnly($name))->toBeTrue();
    }
});
