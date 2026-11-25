<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// Core reports each tool it runs with a ToolInvoked telemetry event while the
// turn is still running, before the harness records what the tool returned.
// A listener that throws must never cost that record: an approved call whose
// result was lost would run again when the run resumes. The resolution's events
// are held until its result is recorded, then dispatched one by one, and a
// listener failure is reported, never thrown.

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $this->ran = ['search' => 0, 'weather' => 0];
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$ran): string {
                $ran['search']++;

                return 'The tigers game is today at 3pm in detroit';
            }),
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran['weather']++;

                return 'The weather will be 75 and sunny';
            }),
    ]);
    Http::preventStrayRequests();
    $fixture = fn (string $name): string => (string) file_get_contents(__DIR__.'/Fixtures/openai/'.$name.'.json');
    Http::fakeSequence()
        ->push($fixture('tool-calls'))
        ->push($fixture('after-tools'))
        ->push($fixture('text-reply'))
        ->push($fixture('text-reply'));
});

it('runs an approved call once when a telemetry listener throws', function (string $path): void {
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    $seen = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$seen): void {
        $seen[] = $event->toolName;
        throw new RuntimeException('listener failed');
    });

    match ($path) {
        'approve' => $session->approve($pending->approvalId),
        'streamDecision' => iterator_to_array($session->streamDecision([new ToolApprovalResponse($pending->approvalId, true)]), false),
    };
    try {
        $session->send('');
    } catch (Throwable) {
    }

    expect($this->ran['weather'])->toBe(1);
    if ($path === 'approve') {
        expect($seen)->toContain('weather');
    }
})->with(['approve', 'streamDecision']);

// One listener failure costs no other call its event.
it('still reports every approved call when the listener throws on the first', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search', 'weather']);
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals();
    expect($pending)->toHaveCount(2);
    $seen = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$seen): void {
        $seen[] = $event->toolName;
        throw new RuntimeException('listener failed');
    });

    $session->decide(array_map(fn ($approval) => new ToolApprovalResponse($approval->approvalId, true), $pending));

    expect($this->ran)->toBe(['search' => 1, 'weather' => 1])
        ->and($seen)->toBe(['search', 'weather']);
});
