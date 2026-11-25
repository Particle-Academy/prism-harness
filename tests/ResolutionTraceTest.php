<?php

declare(strict_types=1);

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationErrored;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// On 0.26.7 core resolved a resumed run's approvals inside its own generation,
// so the approved call's ToolInvoked travelled in the generation's trace and a
// tracing bridge recorded it as a tool span of that generation. The plain send
// paths now resolve before the generation (G-380), and the event must still
// land in the generation's trace: the trace rule below is prism-opentelemetry's
// (TelemetrySubscriber::onToolInvoked drops an event whose trace no
// GenerationStarted opened, and a trace closes on GenerationCompleted,
// GenerationFailed or GenerationErrored). It must also arrive only once the
// call's result is recorded, and a listener that throws must not run the call
// again.

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
});

function resolutionTraceFixtures(bool $structured): void
{
    $fixture = fn (string $name): string => (string) file_get_contents(__DIR__.'/Fixtures/openai/'.$name.'.json');
    $reply = json_decode($fixture('text-reply'), true);
    if ($structured) {
        $reply['output'][0]['content'][0]['text'] = '{"ok":true}';
    }
    Http::fakeSequence()->push($fixture('tool-calls'))->push($structured ? $reply : $fixture('after-tools'))->push($reply)->push($reply);
}

it('reports a resumed approved call inside the generation trace, after its record', function (string $resume, bool $throws, bool $hostContext): void {
    resolutionTraceFixtures($resume === 'structured');
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];

    $open = [];
    $seen = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$open): void {
        $open[$event->context->traceId] = true;
    });
    foreach ([GenerationCompleted::class, GenerationFailed::class, GenerationErrored::class] as $end) {
        Event::listen($end, function (object $event) use (&$open): void {
            unset($open[$event->context->traceId]);
        });
    }
    Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($session, &$open, &$seen, $throws): void {
        if ($event->toolName !== 'weather') {
            return;
        }
        $seen[] = [
            'in_open_generation' => isset($open[$event->context->traceId]),
            'recorded' => $session->thread()->storedMessages()->where('type', 'tool_result')->get()
                ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
                ->where('tool_call_id', $event->toolCallId)->count(),
        ];
        if ($throws) {
            throw new RuntimeException('listener failed');
        }
    });

    $host = null;
    if ($hostContext) {
        $host = new TelemetryContext(traceId: 'host-trace', operation: TelemetryOperation::Text, provider: 'host', model: 'host', startedAt: microtime(true));
        Telemetry::stack()->push($host);
    }
    try {
        match ($resume) {
            'approve' => $session->approve($pending->approvalId),
            'decide' => $session->decide([new ToolApprovalResponse($pending->approvalId, true)]),
            'structured' => (function () use ($session, $pending): void {
                $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending->approvalId, true)])], $session->run()['id'] ?? null);
                $session->sendStructured('', new ObjectSchema('answer', 'Answer', [new BooleanSchema('ok', 'Done')], ['ok']));
            })(),
        };
        $session->send('');
    } finally {
        Telemetry::end($host);
    }

    expect($this->ran['weather'])->toBe(1)
        ->and($seen)->toBe([['in_open_generation' => true, 'recorded' => 1]]);
})->with(['approve', 'decide', 'structured'])->with(['quiet' => false, 'throwing' => true])->with(['no host context' => false, 'host context' => true]);

// The buffer reads the dispatcher's deferred events; if a Laravel release
// renames them, this fails rather than the buffer silently falling back.
it('can read the events the dispatcher defers', function (): void {
    expect(app('events'))->toBeInstanceOf(Dispatcher::class)
        ->and(property_exists(Dispatcher::class, 'deferredEvents'))->toBeTrue();
});
