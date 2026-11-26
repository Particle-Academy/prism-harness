<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationErrored;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// A resumed run's approved calls run once, across the turn that resolves them
// and the turn after it, on every route: a telemetry listener that throws can
// no longer cause a second run (core 0.155 reports a listener's failure instead
// of letting it escape), and the harness records what the resolution produced
// on the routes where core's own record lost it: a structured turn, a plain
// stream that is abandoned, or that stops on a tool's own exception, and the
// step-by-step paths when a tool's own exception stops the resolution.
//
// Telemetry stays where 0.26.7 put it: on the plain paths core resolves the
// approvals inside its own generation, so each approved call's ToolInvoked
// arrives inside that generation's open trace.

function resumeChat(?string $text, array $calls = []): array
{
    $message = ['role' => 'assistant', 'content' => $text];
    if ($calls !== []) {
        $message['tool_calls'] = array_map(fn (array $c): array => ['id' => $c[0], 'type' => 'function', 'function' => ['name' => $c[1], 'arguments' => $c[2]]], $calls);
    }

    return ['id' => 'c', 'object' => 'chat.completion', 'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $calls !== [] ? 'tool_calls' : 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]];
}

function resumeAnthropic(?string $text, array $calls = []): array
{
    $content = $calls !== []
        ? array_map(fn (array $c): array => ['type' => 'tool_use', 'id' => $c[0], 'name' => $c[1], 'input' => json_decode($c[2], true)], $calls)
        : [['type' => 'text', 'text' => $text ?? 'done']];

    return ['id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'model' => 'm', 'content' => $content,
        'stop_reason' => $calls !== [] ? 'tool_use' : 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
}

function resumeSse(string $text): string
{
    $line = fn (array $choice): string => 'data: '.json_encode(['id' => 's', 'model' => 'gpt-4o', 'choices' => [$choice]])."\n\n";

    return $line(['index' => 0, 'delta' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => null])
        .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop'])."data: [DONE]\n\n";
}

/**
 * Park a run on approvals, resume it by $route, then send(''): what ran, and
 * where each approved call's ToolInvoked landed.
 *
 * @return array{runs: array<string, int>, events: list<array{tool: string, at_dispatch: string, op: string|null}>, failures: list<string>}
 */
function resumeScenario(string $route, string $path, string $listener, string $provider = 'openai'): array
{
    $anthropic = $provider === 'anthropic';
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.providers.openai.api_format', 'chat_completions');
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism.telemetry.enabled', $listener !== 'off');
    config()->set('prism.telemetry.capture_content', false);
    config()->set('prism-harness.agent.provider', $provider);
    config()->set('prism-harness.agent.model', $anthropic ? 'm' : 'gpt-4o');
    config()->set('prism-harness.agent.max_cost_usd', null);
    $two = in_array($route, ['decide2', 'raw', 'raw stream'], true);
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', $two ? ['search', 'weather'] : ['weather']);
    if ($path === 'subagents') {
        config()->set('prism-harness.agent.modes.child', ['system_prompt' => 'A child.', 'tools' => [], 'skills' => [], 'max_steps' => 1]);
        config()->set('prism-harness.agent.modes.chat.subagents', ['run_child' => ['description' => 'Run a child.', 'mode' => 'child', 'max_steps' => 1]]);
    }
    if ($path === 'capped') {
        config()->set('prism-harness.agent.max_cost_usd', 5.0);
        config()->set('prism-harness.agent.max_output_tokens_per_call', 1000);
        config()->set('prism-harness.pricing', [$provider => [$anthropic ? 'm' : 'gpt-4o' => ['inputPerMTok' => 2.5, 'outputPerMTok' => 10.0, 'toolOverheadTokens' => 300]]]);
    }
    $runs = ['search' => 0, 'weather' => 0];
    $throwOnce = str_starts_with($route, 'raw');
    $weather = (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City');
    if ($throwOnce) {
        $weather = $weather->withoutErrorHandling();
    }
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')->using(function (string $query) use (&$runs): string {
            $runs['search']++;

            return 'found';
        }),
        $weather->using(function (string $city) use (&$runs, &$throwOnce): string {
            $runs['weather']++;
            if ($throwOnce) {
                $throwOnce = false;
                throw new LogicException('tool failed');
            }

            return 'sunny';
        }),
    ]);
    $calls = $two ? [['call_s', 'search', '{"query":"a"}'], ['call_w', 'weather', '{"city":"b"}']] : [['call_w', 'weather', '{"city":"b"}']];
    $queue = [$anthropic ? resumeAnthropic(null, $calls) : resumeChat(null, $calls)];
    Http::preventStrayRequests();
    Http::fake(function (HttpRequest $request) use (&$queue, $anthropic) {
        $next = array_shift($queue);
        if (($request->data()['stream'] ?? false) === true) {
            return Http::response(resumeSse('streamed'), 200, ['Content-Type' => 'text/event-stream']);
        }
        if ($anthropic) {
            return Http::response($next ?? resumeAnthropic('{"ok":true}'));
        }

        return Http::response($next ?? resumeChat(($request->data()['response_format'] ?? null) !== null ? '{"ok":true}' : 'done'));
    });

    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $pending = $session->send('Go.')->pendingApprovals();

    $traces = [];
    $events = [];
    $failures = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $e) use (&$traces): void {
        $traces[$e->context->traceId] = ['op' => $e->context->operation->value, 'state' => 'open'];
    });
    foreach ([GenerationCompleted::class, GenerationFailed::class, GenerationErrored::class] as $class) {
        Event::listen($class, function (object $e) use (&$traces): void {
            if (isset($traces[$e->context->traceId])) {
                $traces[$e->context->traceId]['state'] = 'closed';
            }
        });
    }
    Event::listen(ToolInvoked::class, function (ToolInvoked $e) use (&$traces, &$events, $listener): void {
        $events[] = ['tool' => $e->toolName, 'at_dispatch' => $traces[$e->context->traceId]['state'] ?? 'unknown', 'op' => $traces[$e->context->traceId]['op'] ?? null];
        if ($listener === 'throw') {
            throw new RuntimeException('listener failed');
        }
    });

    $decisions = array_map(fn ($a) => new ToolApprovalResponse($a->approvalId, true), $pending);
    $record = fn () => $session->thread()->record([new ToolResultMessage(toolApprovalResponses: $decisions)], $session->run()['id'] ?? null);
    try {
        match ($route) {
            'approve' => $session->approve($pending[0]->approvalId),
            'decide2', 'raw' => $session->decide($decisions),
            'streamDecision' => iterator_to_array($session->streamDecision($decisions), false),
            'structured' => (function () use ($record, $session): void {
                $record();
                $session->sendStructured('', new ObjectSchema('answer', 'Answer', [new BooleanSchema('ok', 'Done')], ['ok']));
            })(),
            'stream', 'raw stream' => (function () use ($record, $session): void {
                $record();
                iterator_to_array($session->stream(''), false);
            })(),
            'abandoned stream' => (function () use ($record, $session): void {
                $record();
                $stream = $session->stream('');
                foreach ($stream as $event) {
                    if ($event instanceof ToolResultEvent) {
                        break;
                    }
                }
                unset($stream, $event);
                gc_collect_cycles();
            })(),
        };
    } catch (Throwable $e) {
        $failures[] = class_basename($e);
    }
    try {
        $session->send('');
    } catch (Throwable $e) {
        $failures[] = 'resume '.class_basename($e);
    }

    return ['runs' => $runs, 'events' => $events, 'failures' => $failures];
}

it('runs each approved call once across the resolving turn and the next', function (string $route, string $path, string $listener): void {
    $result = resumeScenario($route, $path, $listener);

    // A tool that throws its own exception runs again on the next resume (it
    // has no result); every call resolved before it runs once.
    $expected = str_starts_with($route, 'raw') ? ['search' => 1, 'weather' => 2] : ($route === 'decide2' ? ['search' => 1, 'weather' => 1] : ['search' => 0, 'weather' => 1]);
    expect($result['runs'])->toBe($expected);
})->with(['approve', 'decide2', 'streamDecision', 'structured', 'stream', 'abandoned stream', 'raw stream'])
    ->with(['plain', 'subagents', 'capped'])
    ->with(['quiet listener' => 'quiet', 'throwing listener' => 'throw', 'telemetry off' => 'off']);

it('runs the earlier call once when a tool throws during a step-path resolution', function (string $path, string $listener): void {
    expect(resumeScenario('raw', $path, $listener)['runs'])->toBe(['search' => 1, 'weather' => 2]);
})->with(['subagents', 'capped'])->with(['quiet listener' => 'quiet', 'throwing listener' => 'throw', 'telemetry off' => 'off']);

// The structured turn on a provider that resolves approvals inside it.
it('runs an approved call once across a structured resume', function (string $path, string $listener): void {
    expect(resumeScenario('structured', $path, $listener, 'anthropic')['runs'])->toBe(['search' => 0, 'weather' => 1]);
})->with(['plain', 'subagents', 'capped'])->with(['quiet listener' => 'quiet', 'throwing listener' => 'throw', 'telemetry off' => 'off']);

// Where 0.26.7 put the telemetry: inside the generation that resolved the call,
// while its trace is open.
it('reports a resumed approved call inside the generation that resolves it', function (string $route, string $provider, string $listener): void {
    $result = resumeScenario($route, 'plain', $listener, $provider);

    expect($result['events'])->not->toBe([]);
    foreach ($result['events'] as $event) {
        expect($event['at_dispatch'])->toBe('open')
            ->and($event['op'])->toBe($route === 'stream' ? 'stream' : ($route === 'structured' ? 'structured' : 'text'));
    }
})->with([
    'approve' => ['approve', 'openai'],
    'decide, two approvals' => ['decide2', 'openai'],
    'plain stream' => ['stream', 'openai'],
    'structured' => ['structured', 'anthropic'],
])->with(['quiet listener' => 'quiet', 'throwing listener' => 'throw']);
