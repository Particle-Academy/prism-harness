<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationErrored;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// An approved tool may run a generation of its own (an agent built as a tool,
// or a subagent). The resumed approval's own ToolInvoked is held until its
// result is recorded, but the nested generation's tool events are not the
// resolution's: on 0.26.7 they reached listeners while the nested
// generation's trace was open, in that trace, and a tracing bridge recorded
// them under it. They must still pass through live.

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $this->nested = false;
    $nested = &$this->nested;
    $inner = [
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')->using(fn (string $query): string => 'inner search'),
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')->using(fn (string $city): string => 'inner weather'),
    ];
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')->using(fn (string $query): string => 'search'),
        // The approved tool runs an agent of its own.
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$nested, $inner): string {
                $nested = true;
                try {
                    return Prism::text()->using('openai', 'gpt-4o')->withTools($inner)->withMaxSteps(2)->withPrompt('Look it up.')->asText()->text;
                } finally {
                    $nested = false;
                }
            }),
    ]);
    Http::preventStrayRequests();
    $fixture = fn (string $name): string => (string) file_get_contents(__DIR__.'/Fixtures/openai/'.$name.'.json');
    Http::fakeSequence()
        ->push($fixture('tool-calls'))
        ->push($fixture('tool-calls'))
        ->push($fixture('after-tools'))
        ->push($fixture('after-tools'))
        ->push($fixture('text-reply'))
        ->push($fixture('text-reply'));
});

it('lets a nested generation\'s tool events through live, in its own trace', function (string $path): void {
    if ($path === 'subagents') {
        config()->set('prism-harness.agent.modes.child', ['system_prompt' => 'A child.', 'tools' => [], 'skills' => [], 'max_steps' => 1]);
        config()->set('prism-harness.agent.modes.chat.subagents', ['run_child' => ['description' => 'Run a child.', 'mode' => 'child', 'max_steps' => 1]]);
    }
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals();
    expect($pending)->toHaveCount(1);

    $open = [];
    $nestedEvents = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$open): void {
        $open[$event->context->traceId] = true;
    });
    foreach ([GenerationCompleted::class, GenerationFailed::class, GenerationErrored::class] as $end) {
        Event::listen($end, function (object $event) use (&$open): void {
            unset($open[$event->context->traceId]);
        });
    }
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$open, &$nestedEvents): void {
        if ($this->nested) {
            $nestedEvents[] = [$event->toolName, isset($open[$event->context->traceId])];
        }
    });

    $session->decide([new ToolApprovalResponse($pending[0]->approvalId, true)]);

    // Both inner calls reported while the approved tool was still running,
    // inside the nested generation's open trace.
    expect($nestedEvents)->toBe([['search', true], ['weather', true]]);
})->with(['plain', 'subagents']);

// A host that wraps the resume in Event::defer() of its own gets, as on 0.26.7,
// everything the turn dispatches (the approved tool's own application events
// and its nested generation included) only when its deferral flushes, in
// order, and nothing at all if its operation fails.
it('keeps a host deferral over what the approved tool dispatches', function (string $path, bool $hostFails): void {
    if ($path === 'subagents') {
        config()->set('prism-harness.agent.modes.child', ['system_prompt' => 'A child.', 'tools' => [], 'skills' => [], 'max_steps' => 1]);
        config()->set('prism-harness.agent.modes.chat.subagents', ['run_child' => ['description' => 'Run a child.', 'mode' => 'child', 'max_steps' => 1]]);
    }
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals();
    $inside = false;
    $order = [];
    $open = [];
    Event::listen('harness.test.audit', function () use (&$inside, &$order): void {
        $order[] = ['audit', $inside];
    });
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$open, &$inside, &$order): void {
        $open[$event->context->traceId] = true;
        $order[] = ['started', $inside];
    });
    foreach ([GenerationCompleted::class, GenerationFailed::class, GenerationErrored::class] as $end) {
        Event::listen($end, function (object $event) use (&$open): void {
            unset($open[$event->context->traceId]);
        });
    }
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$open, &$inside, &$order): void {
        $order[] = [$event->toolName, $inside, isset($open[$event->context->traceId])];
    });
    app(ToolRegistry::class)->register((new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
        ->using(function (string $city): string {
            $this->nested = true;
            try {
                event('harness.test.audit', [$city]);

                return Prism::text()->using('openai', 'gpt-4o')
                    ->withTools([
                        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')->using(fn (string $query): string => 'inner search'),
                        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')->using(fn (string $city): string => 'inner weather'),
                    ])
                    ->withMaxSteps(2)->withPrompt('Look it up.')->asText()->text;
            } finally {
                $this->nested = false;
            }
        }));

    try {
        Event::defer(function () use ($session, $pending, &$inside, $hostFails): void {
            $inside = true;
            $session->decide([new ToolApprovalResponse($pending[0]->approvalId, true)]);
            $inside = false;
            if ($hostFails) {
                throw new RuntimeException('host operation failed');
            }
        });
    } catch (RuntimeException) {
    }

    // Nothing reached a listener while the host's deferral was open.
    expect(array_filter($order, fn (array $seen): bool => $seen[1] === true))->toBe([]);
    if ($hostFails) {
        expect($order)->toBe([]);

        return;
    }
    // As on 0.26.7: the nested generation's two calls inside its open trace;
    // on the plain path the approved call after its own generation started,
    // inside that open trace (the step path reports none without a host
    // telemetry context).
    $names = array_map(fn (array $seen): string => $seen[0].(count($seen) === 3 ? ($seen[2] ? ':open' : ':closed') : ''), $order);
    expect($names)->toBe($path === 'plain'
        ? ['audit', 'started', 'search:open', 'weather:open', 'started', 'weather:open']
        : ['audit', 'started', 'search:open', 'weather:open', 'started']);
})->with(['plain', 'subagents'])->with(['host flushes' => false, 'host fails' => true]);
