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

// The step-by-step paths (a mode that declares subagents, a cost-capped run,
// and every stream) resolve a resumed run's approvals themselves. On 0.26.7
// that resolution dispatched no ToolInvoked unless the host had a telemetry
// context of its own open, so no listener ran between an approved call and
// its record. A listener that ends the process in a way PHP cannot catch (a
// time limit, running out of memory, a killed worker) loses whatever is not
// yet recorded; it must not be handed that window on these paths.
//
// The listener here stands in for that stop: it reads what is persisted at the
// moment it is called. If an approved call's event ever reaches a listener
// before the call's result is stored, the process could have ended there with
// the call run and its result lost, and the next resume would run it again.

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

function stepResolutionPath(string $path): void
{
    if ($path === 'subagents') {
        config()->set('prism-harness.agent.modes.child', ['system_prompt' => 'A child.', 'tools' => [], 'skills' => [], 'max_steps' => 1]);
        config()->set('prism-harness.agent.modes.chat.subagents', ['run_child' => ['description' => 'Run a child.', 'mode' => 'child', 'max_steps' => 1]]);
    }
    if ($path === 'capped') {
        config()->set('prism-harness.agent.max_cost_usd', 5.0);
        config()->set('prism-harness.agent.max_output_tokens_per_call', 1000);
        config()->set('prism-harness.pricing.openai.gpt-4o', ['inputPerMTok' => 2.5, 'outputPerMTok' => 10.0, 'toolOverheadTokens' => 300]);
    }
}

it('dispatches no resolution event before the approved call is recorded on the step paths', function (string $path, string $resume): void {
    stepResolutionPath($path);
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    $recordedAtDispatch = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($session, &$recordedAtDispatch): void {
        $recordedAtDispatch[] = $session->thread()->storedMessages()->where('type', 'tool_result')->get()
            ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
            ->where('tool_call_id', $event->toolCallId)->count();
    });

    match ($resume) {
        'approve' => $session->approve($pending->approvalId),
        'streamDecision' => iterator_to_array($session->streamDecision([new ToolApprovalResponse($pending->approvalId, true)]), false),
    };
    $session->send('');

    expect($this->ran['weather'])->toBe(1)
        // Every event that did reach a listener found its call recorded.
        ->and(array_filter($recordedAtDispatch, fn (int $recorded): bool => $recorded === 0))->toBe([])
        // As on 0.26.7: these paths report no resolution without a host context.
        ->and($recordedAtDispatch)->toBe([]);
})->with([
    'subagents, approve' => ['subagents', 'approve'],
    'capped, approve' => ['capped', 'approve'],
    'plain stream' => ['plain', 'streamDecision'],
    'subagents, stream' => ['subagents', 'streamDecision'],
    'capped, stream' => ['capped', 'streamDecision'],
]);

// The plain send path stands in for core's generation, which on 0.26.7 ran the
// approved call under its own telemetry context and reported it; that event is
// kept, and a listener that throws there is reported, never thrown.
it('still reports the resolution on the plain send path', function (): void {
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    $seen = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$seen): void {
        $seen[] = $event->toolName;
        throw new RuntimeException('listener failed');
    });

    $session->approve($pending->approvalId);
    $session->send('');

    expect($this->ran['weather'])->toBe(1)->and($seen)->toBe(['weather']);
});
