<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Support\G360;

// A run that resumes after a person approved a call runs that call before its
// next model step, and reports it with a ToolInvoked telemetry event. No
// listener may see that event before the call's result is recorded: a
// listener that ends the process in a way PHP cannot catch (a time limit, out
// of memory, a killed worker) would otherwise leave the call run and its
// result lost, and the next resume would run it again. The listeners here
// read what is stored at the moment they are called.
//
// The event is also reported INSIDE the next generation's trace, so a tracer
// that only builds tool spans under an open generation (prism-opentelemetry)
// does not drop it.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search', 'weather']);
    $this->ran = ['search' => 0, 'weather' => 0];
    $this->weatherThrows = 0;
    $ran = &$this->ran;
    $throws = &$this->weatherThrows;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$ran): string {
                $ran['search']++;

                return 'The game is at 3pm';
            }),
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->withoutErrorHandling()
            ->using(function (string $city) use (&$ran, &$throws): string {
                $ran['weather']++;
                if ($throws > 0) {
                    $throws--;
                    throw new LogicException('weather backend down');
                }

                return 'Sunny';
            }),
    ]);
});

function resolutionPath(string $path): void
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

function resolutionSchema(): ObjectSchema
{
    return new ObjectSchema('answer', 'Answer', [new BooleanSchema('ok', 'Done')], ['ok']);
}

/** How many stored tool results answer the call, right now. */
function storedResultsFor(Session $session, string $callId): int
{
    return $session->thread()->storedMessages()->where('type', 'tool_result')->get()
        ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
        ->where('tool_call_id', $callId)->count();
}

/**
 * Resume the parked run with every pending approval approved, by the named
 * route. The `recorded:` routes resume decisions that were written but never
 * resolved: what a resume leaves when it stops after the decision write and
 * before the resolution (a worker killed there, for one).
 *
 * @param  list<ToolApprovalRequest>  $pending
 */
function resumeApproved(Session $session, array $pending, string $resume): void
{
    $decisions = array_map(fn ($approval) => new ToolApprovalResponse($approval->approvalId, true), $pending);
    if (str_starts_with($resume, 'recorded:')) {
        (fn () => $this->recordDecisions($decisions))->call($session);
    }
    match ($resume) {
        'decide' => $session->decide($decisions),
        'streamDecision' => iterator_to_array($session->streamDecision($decisions), false),
        'recorded:send' => $session->send(''),
        'recorded:sendStructured' => $session->sendStructured('', resolutionSchema()),
        'recorded:stream' => iterator_to_array($session->stream(''), false),
        'recorded:streamSteps' => iterator_to_array($session->streamSteps(''), false),
    };
}

/** @return array<string, array{string, string}> */
function resolutionRoutes(): array
{
    return [
        'plain, decide' => ['plain', 'decide'],
        'plain, streamDecision' => ['plain', 'streamDecision'],
        'plain, recorded then send' => ['plain', 'recorded:send'],
        'plain, recorded then sendStructured' => ['plain', 'recorded:sendStructured'],
        'plain, recorded then stream' => ['plain', 'recorded:stream'],
        'plain, recorded then streamSteps' => ['plain', 'recorded:streamSteps'],
        'subagents, decide' => ['subagents', 'decide'],
        'subagents, streamDecision' => ['subagents', 'streamDecision'],
        'subagents, recorded then stream' => ['subagents', 'recorded:stream'],
        'capped, decide' => ['capped', 'decide'],
        'capped, streamDecision' => ['capped', 'streamDecision'],
        'capped, recorded then sendStructured' => ['capped', 'recorded:sendStructured'],
    ];
}

function scriptResume(string $resume): void
{
    G360::text($resume === 'recorded:sendStructured' ? '{"ok":true}' : 'Done.');
    G360::text($resume === 'recorded:sendStructured' ? '{"ok":true}' : 'Again.');
}

it('records every resolved call before any listener hears of it', function (string $path, string $resume): void {
    resolutionPath($path);
    $session = G360::session();
    G360::calls([['search', ['query' => 'game']], ['weather', ['city' => 'Detroit']]]);
    $pending = $session->send('go')->pendingApprovals();
    expect($pending)->toHaveCount(2);

    $recordedAtDispatch = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($session, &$recordedAtDispatch): void {
        $recordedAtDispatch[$event->toolName] = storedResultsFor($session, $event->toolCallId);
    });
    scriptResume($resume);
    resumeApproved($session, $pending, $resume);

    expect($this->ran)->toBe(['search' => 1, 'weather' => 1])
        // Both calls are still reported, and each found its result stored.
        ->and($recordedAtDispatch)->toHaveKeys(['search', 'weather'])
        ->and(array_filter($recordedAtDispatch, fn (int $count): bool => $count === 0))->toBe([]);
})->with(resolutionRoutes());

it('runs no approved call twice when every listener throws', function (string $path, string $resume): void {
    resolutionPath($path);
    $session = G360::session();
    G360::calls([['search', ['query' => 'game']], ['weather', ['city' => 'Detroit']]]);
    $pending = $session->send('go')->pendingApprovals();
    $seen = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$seen): void {
        $seen[] = $event->toolName;
        throw new RuntimeException('listener failed');
    });
    scriptResume($resume);
    G360::text('Once more.');

    resumeApproved($session, $pending, $resume);
    try {
        $session->send('');
    } catch (Throwable) {
    }

    // One listener failure costs no other call its event, and nothing runs again.
    expect($this->ran)->toBe(['search' => 1, 'weather' => 1])
        ->and($seen)->toBe(['search', 'weather']);
})->with(resolutionRoutes());

it('reports a resumed call inside the next generation trace, as a tracer needs it', function (string $path, string $resume): void {
    resolutionPath($path);
    $session = G360::session();
    G360::calls([['search', ['query' => 'game']], ['weather', ['city' => 'Detroit']]]);
    $pending = $session->send('go')->pendingApprovals();

    // What prism-opentelemetry keeps: a ToolInvoked whose trace has a started
    // and not yet finished generation. Anything else it drops.
    $open = [];
    $started = [];
    $kept = [];
    $dropped = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$open, &$started): void {
        $open[$event->context->traceId] = true;
        $started[] = $event->context->traceId;
    });
    $close = function (object $event) use (&$open): void {
        unset($open[$event->context->traceId]);
    };
    Event::listen(GenerationCompleted::class, $close);
    Event::listen(GenerationFailed::class, $close);
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$open, &$kept, &$dropped): void {
        if (isset($open[$event->context->traceId])) {
            $kept[$event->toolName] = $event->context->traceId;
        } else {
            $dropped[] = $event->toolName;
        }
    });
    scriptResume($resume);
    resumeApproved($session, $pending, $resume);

    expect($dropped)->toBe([])
        ->and($kept)->toHaveKeys(['search', 'weather'])
        // The resumed run's first generation: the one the results were sent in.
        ->and(array_values(array_unique($kept)))->toBe([$started[0]]);
})->with(resolutionRoutes());

it('keeps the content gate as core applies it', function (bool $capture): void {
    config()->set('prism.telemetry.capture_content', $capture);
    $session = G360::session();
    G360::calls([['search', ['query' => 'game']], ['weather', ['city' => 'Detroit']]]);
    $pending = $session->send('go')->pendingApprovals();
    $seen = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$seen): void {
        $seen[$event->toolName] = [$event->toolCall?->arguments(), $event->toolResult?->result, $event->failed];
    });
    G360::text('Done.');
    resumeApproved($session, $pending, 'decide');

    expect($seen)->toBe($capture
        ? ['search' => [['query' => 'game'], 'The game is at 3pm', false], 'weather' => [['city' => 'Detroit'], 'Sunny', false]]
        : ['search' => [null, null, false], 'weather' => [null, null, false]]);
})->with([true, false]);

// A tool built withoutErrorHandling() throws its own exception out of the
// resolution. A call resolved before it has already run; its result is
// recorded before the exception leaves, so the next resume runs only the call
// that threw.
it('records the calls resolved before a tool throws, so the next resume does not run them again', function (string $path, string $resume): void {
    resolutionPath($path);
    $session = G360::session();
    G360::calls([['search', ['query' => 'game']], ['weather', ['city' => 'Detroit']]]);
    $pending = $session->send('go')->pendingApprovals();
    $this->weatherThrows = 1;
    $events = [];
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use (&$events): void {
        $events[] = [$event->toolName, $event->failed, $event->failure];
    });
    scriptResume($resume);

    $threw = null;
    try {
        resumeApproved($session, $pending, $resume);
    } catch (LogicException $failure) {
        $threw = $failure->getMessage();
    }
    $ranAtThrow = $this->ran;
    $storedAtThrow = storedResultsFor($session, 'fc_1');
    $eventsAtThrow = $events;

    try {
        $session->send('');
    } catch (Throwable) {
    }

    // Only the call that threw runs again.
    expect($this->ran)->toBe(['search' => 1, 'weather' => 2])
        ->and($threw)->toBe('weather backend down')
        ->and($ranAtThrow)->toBe(['search' => 1, 'weather' => 1])
        ->and($storedAtThrow)->toBe(1)
        // Both reported, the throw as core reports it.
        ->and($eventsAtThrow)->toBe([['search', false, null], ['weather', true, 'exception:LogicException']]);
})->with([
    'plain, decide' => ['plain', 'decide'],
    'plain, streamDecision' => ['plain', 'streamDecision'],
    'plain, recorded then stream' => ['plain', 'recorded:stream'],
    'plain, recorded then sendStructured' => ['plain', 'recorded:sendStructured'],
    'subagents, decide' => ['subagents', 'decide'],
    'capped, decide' => ['capped', 'decide'],
    'capped, streamDecision' => ['capped', 'streamDecision'],
]);

// A stream dropped after the resolution's results and before its generation
// starts: the reports still go out, after the record, under a context of their
// own; no later generation of the process takes them.
it('reports a resolution no generation followed, after its record', function (): void {
    $session = G360::session();
    G360::calls([['search', ['query' => 'game']], ['weather', ['city' => 'Detroit']]]);
    $pending = $session->send('go')->pendingApprovals();
    (fn () => $this->recordDecisions(array_map(fn ($approval) => new ToolApprovalResponse($approval->approvalId, true), $pending)))->call($session);
    $started = [];
    $seen = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$started): void {
        $started[] = $event->context->traceId;
    });
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($session, &$seen): void {
        $seen[$event->toolName] = [storedResultsFor($session, $event->toolCallId), $event->context->traceId];
    });

    foreach ($session->stream('') as $event) {
        break;
    }
    expect($this->ran)->toBe(['search' => 1, 'weather' => 1])
        ->and($seen)->toHaveKeys(['search', 'weather'])
        ->and($seen['search'][0])->toBe(1)->and($seen['weather'][0])->toBe(1)
        ->and($started)->toBe([]);

    // The next turn's generation is its own; it takes nothing from the dropped one.
    G360::text('Done.');
    $seen = [];
    $session->send('next');
    expect($seen)->toBe([])->and($this->ran)->toBe(['search' => 1, 'weather' => 1]);
});

it('reports nothing with telemetry off, and still runs each approved call once', function (): void {
    config()->set('prism.telemetry.enabled', false);
    $session = G360::session();
    G360::calls([['search', ['query' => 'game']], ['weather', ['city' => 'Detroit']]]);
    $pending = $session->send('go')->pendingApprovals();
    $seen = 0;
    Event::listen(ToolInvoked::class, function () use (&$seen): void {
        $seen++;
    });
    G360::text('Done.');
    G360::text('Again.');
    resumeApproved($session, $pending, 'decide');
    $session->send('');

    expect($seen)->toBe(0)->and($this->ran)->toBe(['search' => 1, 'weather' => 1]);
});
