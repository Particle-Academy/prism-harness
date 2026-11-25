<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;
use Tests\Support\G360;

// A call a person approved runs when the run resumes, before its next model
// step. Core reports every tool it runs with a ToolInvoked telemetry event,
// and the harness, which runs that resolution itself so it can persist the
// result and check the run's budget and cancel flag before the next request,
// keeps reporting it on every path: once the result is recorded, inside the
// trace of the generation the result is sent in. The resolution opens no
// generation of its own.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $this->ran = 0;
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran++;

                return 'Sunny';
            }),
    ]);
});

it('reports the approved call it runs on resume with a ToolInvoked event', function (string $path): void {
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    G360::calls([['weather', ['city' => 'Detroit']]]);
    $pending = $session->send('go')->pendingApprovals()[0];

    $invoked = [];
    $generations = 0;
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$invoked): void {
        $invoked[] = ['tool' => $event->toolName, 'failed' => $event->failed];
    });
    Event::listen(GenerationStarted::class, function () use (&$generations): void {
        $generations++;
    });
    G360::text('Done.');
    match ($path) {
        'approve' => $session->approve($pending->approvalId),
        'streamDecision' => iterator_to_array($session->streamDecision([new ToolApprovalResponse($pending->approvalId, true)]), false),
    };

    expect($this->ran)->toBe(1)
        ->and($invoked)->toBe([['tool' => 'weather', 'failed' => false]])
        // The resolution opens no generation of its own: one model step, one generation.
        ->and($generations)->toBe(1)
        ->and(Telemetry::current())->toBeNull();
})->with(['approve', 'streamDecision']);

// A ToolInvoked listener that throws must not stop the approved call's result
// from being recorded, or the next resume would run the call again.
it('records an approved call before reporting it, so a throwing listener never runs it twice', function (): void {
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    G360::calls([['weather', ['city' => 'Detroit']]]);
    $pending = $session->send('go')->pendingApprovals()[0];
    Event::listen(ToolInvoked::class, function (): void {
        throw new RuntimeException('listener exploded');
    });
    G360::text('Done.');
    G360::text('Again.');

    try {
        $session->approve($pending->approvalId);
    } catch (RuntimeException) {
    }
    try {
        $session->send('');
    } catch (Throwable) {
    }

    expect($this->ran)->toBe(1);
});
