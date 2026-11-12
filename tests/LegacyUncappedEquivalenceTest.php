<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

it('preserves ordinary uncapped wire bytes and generation telemetry grouping', function (string $shape): void {
    config()->set('prism.providers.anthropic.api_key', 'synthetic-test-key');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);
    config()->set('prism-harness.agent.max_cost_usd', null);
    config()->set('prism-harness.agent.modes.chat.system_prompt', 'Synthetic ordinary session.');
    config()->set('prism-harness.agent.modes.chat.skills', []);
    config()->set('prism-harness.agent.modes.chat.subagents', []);
    config()->set('prism-harness.agent.modes.chat.max_steps', 6);
    $session = callBudgetSession('legacy-equivalence-'.$shape);
    $events = [];
    $traces = [];
    Event::listen([GenerationStarted::class, GenerationCompleted::class, StepCompleted::class, ToolInvoked::class],
        function (object $event) use (&$events, &$traces): void {
            $context = get_object_vars($event->context);
            $id = $context['traceId'];
            $traces[$id] ??= count($traces);
            $context['traceId'] = $traces[$id];
            // Only fresh identifiers and measured clock values vary by invocation.
            unset($context['startedAt']);
            $payload = get_object_vars($event);
            unset($payload['context'], $payload['durationMs']);
            $events[] = ['type' => $event::class, 'context' => $context, 'payload' => $payload];
        });
    $legs = [];
    foreach (['core', 'harness'] as $leg) {
        $events = [];
        $traces = [];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $sequence = Http::fakeSequence();
        foreach ([1, 2, 3] as $index) {
            $sequence->push($shape === 'stream' ? budgetSseReply($index, $index === 3) : budgetHttpReply($index, $index === 3),
                200, $shape === 'stream' ? ['Content-Type' => 'text/event-stream'] : []);
        }
        if ($leg === 'core') {
            $generation = ($shape === 'structured' ? Prism::structured()->withSchema(callBudgetSchema()) : Prism::text())
                ->using('anthropic', 'budget-model')
                ->withMessages([new UserMessage('go')])
                ->withSystemPrompt('Synthetic ordinary session.')
                ->withTools(array_values(app(ToolRegistry::class)->resolve(['tick'], $session)))
                ->withMaxSteps(6)->withTelemetryMetadata(sessionId: $session->key());
            if ($shape === 'stream') {
                iterator_to_array($generation->asStream());
            } elseif ($shape === 'structured') {
                $generation->asStructured();
            } else {
                $generation->asText();
            }
        } else {
            callBudgetRun($shape, $session, null);
        }
        Http::assertSentCount(3);
        expect(array_column($events, 'type'))->toContain(GenerationStarted::class, GenerationCompleted::class)
            ->and(count(array_filter($events, fn (array $event): bool => $event['type'] === GenerationStarted::class)))->toBe(1)
            ->and(count(array_filter($events, fn (array $event): bool => $event['type'] === GenerationCompleted::class)))->toBe(1)
            ->and($traces)->toHaveCount(1);
        $legs[] = ['wire' => Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->all(),
            'telemetry' => $events];
    }
    expect(json_encode($legs[1], JSON_THROW_ON_ERROR))->toBe(json_encode($legs[0], JSON_THROW_ON_ERROR));
})->with(['send', 'structured', 'stream']);

it('keeps a nonbinding shared tree equivalent to an ordinary uncapped transcript and wire sequence', function (string $shape): void {
    config()->set('prism.providers.anthropic.api_key', 'synthetic-test-key');
    config()->set('prism-harness.agent.max_cost_usd', null);
    config()->set('prism-harness.agent.modes.chat.skills', []);
    config()->set('prism-harness.agent.modes.chat.subagents', []);
    config()->set('prism-harness.agent.modes.chat.max_steps', 6);
    $legs = [];
    foreach ([false, true] as $shared) {
        $session = callBudgetSession('nonbinding-'.($shared ? 'tree' : 'ordinary').'-'.$shape);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $sequence = Http::fakeSequence();
        foreach ([1, 2, 3] as $index) {
            $sequence->push($shape === 'stream' ? budgetSseReply($index, $index === 3) : budgetHttpReply($index, $index === 3),
                200, $shape === 'stream' ? ['Content-Type' => 'text/event-stream'] : []);
        }
        $context = $shared ? RunContext::root('synthetic-nonbinding', new RunBudget(6)) : null;
        callBudgetRun($shape, $session, $context);
        Http::assertSentCount(3);
        $legs[] = ['wire' => Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->all(),
            'transcript' => $session->thread()->storedMessages()->orderBy('position')->get()
                ->map(fn ($message): array => ['type' => $message->type, 'payload' => $message->payload])->all()];
        if ($context !== null) {
            expect($context->ledger->steps())->toBe(3);
        }
    }
    expect(json_encode($legs[1], JSON_THROW_ON_ERROR))->toBe(json_encode($legs[0], JSON_THROW_ON_ERROR));
})->with(['send', 'structured', 'stream']);
