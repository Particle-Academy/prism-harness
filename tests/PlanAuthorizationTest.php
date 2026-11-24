<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Support\G360;

// G-360 follow-up: a host call policy that forbids a plan tool is asked
// BEFORE anything is recorded. A forbidden draft writes no plan and leaves the
// live-plan slot free; a forbidden change or approval pause changes nothing.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.plans', true);
    config()->set('prism-harness.plans.enabled', true);
    config()->set('prism-harness.agent.authorize_tools', true);
    app(ToolRegistry::class)->register((new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
        ->using(fn (string $city): string => 'Sunny'));

    $this->forbidden = [];
    $forbidden = &$this->forbidden;
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY, function ($user, $session, Tool $tool, array $args) use (&$forbidden): bool {
        return ! in_array($tool->name(), $forbidden, true);
    });
});

function g360bDraft(): array
{
    return ['goal' => 'Plan the trip', 'steps' => G360::steps(2)];
}

it('records nothing and keeps the live-plan slot free when the host forbids plan_draft', function (): void {
    $this->forbidden = ['plan_draft'];
    G360::calls([['plan_draft', g360bDraft()]]);
    G360::text('Refused.');
    $response = G360::session()->send('Plan the trip');

    expect($response->pendingApprovals())->toBe([])
        ->and(G360::session()->plan()->current())->toBeNull();

    // Allowed again, the next draft is not refused as "a plan is already open".
    $this->forbidden = [];
    G360::calls([['plan_draft', g360bDraft()]]);
    $pending = G360::session()->send('Plan the trip')->pendingApprovals();
    expect($pending)->toHaveCount(1)
        ->and(G360::session()->plan()->current()?->status())->toBe('proposed');
});

it('records no change when the host forbids plan_propose_change', function (): void {
    G360::calls([['plan_draft', g360bDraft()]]);
    $pending = G360::session()->send('Plan the trip')->pendingApprovals()[0];
    G360::text('ok');
    G360::session()->approve($pending->approvalId);
    $plan = G360::session()->plan()->current()->toArray();
    $before = $plan['revision'];

    $this->forbidden = ['plan_propose_change'];
    G360::calls([['plan_propose_change', ['reason' => 'better', 'base_revision' => $before,
        'steps' => [['id' => $plan['steps'][0]['id']], ['title' => 'New', 'instruction' => 'Do it.', 'owner' => 'agent']]]]]);
    G360::text('Refused.');
    $response = G360::session()->send('Change it');

    $after = G360::session()->plan()->current()->toArray();
    expect($response->pendingApprovals())->toBe([])
        ->and($after['pendingChange'])->toBeNull()
        ->and($after['status'])->toBe('active')
        ->and($after['counters']['changes'])->toBe(0);
});

it('inserts no person step when the host forbids plan_pause_for_person', function (): void {
    G360::calls([['plan_draft', g360bDraft()]]);
    $pending = G360::session()->send('Plan the trip')->pendingApprovals()[0];
    G360::text('ok');
    G360::session()->approve($pending->approvalId);

    $this->forbidden = ['plan_pause_for_person'];
    G360::calls([['plan_pause_for_person', ['kind' => 'approve', 'question' => 'May I book it?']]]);
    G360::text('Refused.');
    G360::session()->plan()->advance();

    $plan = G360::session()->plan()->current()->toArray();
    expect(array_column($plan['steps'], 'owner'))->toBe(['agent', 'agent'])
        ->and($plan['steps'][0]['pauses'])->toBe(0);
});
