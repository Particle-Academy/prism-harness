<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

// G-412: a gated tool built by a factory or a provider (an MCP server, say)
// fails to build when its approved call is resumed. The harness never builds
// a tool the mode gates before the run's own resolution, so the approved
// call is not given an outcome it did not have: the turn fails with the
// build's error, and the next resume runs the call once.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['mcp_weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['mcp_weather']);

    $this->requests = 0;
    $requests = &$this->requests;
    Http::fake(function (Request $request) use (&$requests) {
        $n = ++$requests;
        $content = $n === 1
            ? [['type' => 'tool_use', 'id' => 'toolu_paris', 'name' => 'mcp_weather', 'input' => ['city' => 'Paris']]]
            : [['type' => 'text', 'text' => 'Done '.$n.'.']];

        return Http::response(['id' => 'm'.$n, 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => $content,
            'stop_reason' => $n === 1 ? 'tool_use' : 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    });
});

function gatedBuildSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

it('keeps an approved call whose gated tool fails to build at the resume, and runs it on the next', function (string $kind): void {
    $ran = [];
    $builds = 0;
    $failNext = false;
    $build = function () use (&$ran, &$builds, &$failNext): Tool {
        $builds++;
        if ($failNext) {
            $failNext = false;

            throw new RuntimeException('The MCP server is unavailable.');
        }

        return (new Tool)->as('mcp_weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran[] = $city;

                return "Sunny in {$city}";
            });
    };
    $kind === 'factory'
        ? app(ToolRegistry::class)->registerFactory('mcp_weather', fn (Session $session): Tool => $build())
        : app(ToolRegistry::class)->registerProvider(fn (Session $session): array => [$build()]);

    $id = gatedBuildSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    // The first build at the resume fails.
    $failNext = true;
    $first = null;
    try {
        gatedBuildSession()->approve($id);
    } catch (Throwable $e) {
        $first = $e;
    }
    $outcomes = [];
    foreach (gatedBuildSession()->thread()->storedMessages()->reorder('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $result) {
            $outcomes[] = $result['result'];
        }
    }

    // The next resume builds the tool and runs the approved call.
    gatedBuildSession()->send('');

    expect($first)->toBeInstanceOf(RuntimeException::class)
        ->and($outcomes)->toBe([])
        ->and($ran)->toBe(['Paris']);
})->with(['factory', 'provider']);
