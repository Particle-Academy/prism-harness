<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// A tool built withoutErrorHandling() throws its own exception out of the
// resolution of a resumed run's approvals. A call resolved before it has
// already run by then; its result must be recorded before the exception
// leaves, or the next resume runs that call again.

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search', 'weather']);
    $this->ran = ['search' => 0, 'weather' => 0];
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$ran): string {
                $ran['search']++;

                return 'The tigers game is today at 3pm in detroit';
            }),
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->withoutErrorHandling()
            ->using(function (string $city) use (&$ran): string {
                $ran['weather']++;
                if ($ran['weather'] === 1) {
                    throw new LogicException('weather backend down');
                }

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

it('records the calls resolved before a tool throws, so the next resume does not run them again', function (string $path, string $resume): void {
    if ($path === 'subagents') {
        config()->set('prism-harness.agent.modes.child', ['system_prompt' => 'A child.', 'tools' => [], 'skills' => [], 'max_steps' => 1]);
        config()->set('prism-harness.agent.modes.chat.subagents', ['run_child' => ['description' => 'Run a child.', 'mode' => 'child', 'max_steps' => 1]]);
    }
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals();
    expect($pending)->toHaveCount(2);
    $decisions = array_map(fn ($approval) => new ToolApprovalResponse($approval->approvalId, true), $pending);

    $threw = null;
    try {
        match ($resume) {
            'decide' => $session->decide($decisions),
            'streamDecision' => iterator_to_array($session->streamDecision($decisions), false),
        };
    } catch (LogicException $failure) {
        $threw = $failure->getMessage();
    }
    expect($threw)->toBe('weather backend down')
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 1]);

    try {
        $session->send('');
    } catch (Throwable) {
    }

    $searchResults = $session->thread()->storedMessages()->where('type', 'tool_result')->get()
        ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
        ->where('tool_name', 'search')->count();
    // Only the call that threw is resolved again.
    expect($this->ran)->toBe(['search' => 1, 'weather' => 2])->and($searchResults)->toBeGreaterThanOrEqual(1);
})->with([
    'plain, decide' => ['plain', 'decide'],
    'plain stream' => ['plain', 'streamDecision'],
    'subagents, decide' => ['subagents', 'decide'],
]);
