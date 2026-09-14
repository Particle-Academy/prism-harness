<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentResponse;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// Approvals driven through Prism's REAL OpenAI handler over faked HTTP. Every
// approval test before these used Prism::fake() with a Pause finish, and a real
// handler differs in all the places that mattered: it finishes with ToolCalls,
// its response messages are what the thread records, and it rewrites the tool
// result messages when a run resumes. Through a real handler, approve() never
// ran the approved tool.

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);

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

    $fixture = fn (string $name): string => (string) file_get_contents(__DIR__.'/Fixtures/openai/'.$name.'.json');
    Http::fakeSequence()
        ->push($fixture('tool-calls'))
        ->push($fixture('after-tools'))
        ->push($fixture('text-reply'));
});

function approvalSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

function askAboutTheGame(): AgentResponse
{
    return approvalSession()->send('What time is the game, and do I need a coat?');
}

/** @return list<string> */
function storedTypes(): array
{
    return Thread::query()->firstOrFail()->storedMessages()->orderBy('position')->pluck('type')->all();
}

/** @return array<string, string> */
function toolOutputsSent(int $request): array
{
    return collect(json_decode((string) Http::recorded()[$request][0]->body(), true)['input'])
        ->where('type', 'function_call_output')
        ->pluck('output', 'call_id')
        ->all();
}

it('reports a run stopped on a gated tool as awaiting approval, and keeps what it already did', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $response = askAboutTheGame();

    expect($response->awaitingApproval())->toBeTrue()
        ->and($response->pendingApprovals())->toHaveCount(1)
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 0])
        ->and(storedTypes())->toBe(['user', 'assistant', 'tool_result'])
        ->and(Thread::query()->firstOrFail()->storedMessages()->where('type', 'assistant')->first()->payload['tool_approval_requests'][0]['approval_id'])
        ->toBe($response->pendingApprovals()[0]->approvalId);
});

it('runs an approved call once, sends every call its output, and records the turn once', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $pending = askAboutTheGame()->pendingApprovals()[0];

    $resumed = approvalSession()->approve($pending);

    expect($this->ran)->toBe(['search' => 1, 'weather' => 1])
        ->and($resumed->awaitingApproval())->toBeFalse()
        ->and(toolOutputsSent(1))->toBe([
            'call_AZkZynIOpPQwJ4fyZETY4eTP' => 'The tigers game is today at 3pm in detroit',
            'call_huHZrdh8RFUy8a8Uqbb2qV1x' => 'The weather will be 75 and sunny',
        ])
        // The step's results, the decision, the merged results, the reply.
        // Before the fix the resume recorded the user and assistant rows again.
        ->and(storedTypes())->toBe(['user', 'assistant', 'tool_result', 'tool_result', 'tool_result', 'assistant']);
});

it('sends a denied call the reason, and does not run it', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    $pending = askAboutTheGame()->pendingApprovals()[0];

    approvalSession()->deny($pending, 'not today');

    expect($this->ran)->toBe(['search' => 1, 'weather' => 0])
        ->and(toolOutputsSent(1)['call_huHZrdh8RFUy8a8Uqbb2qV1x'])->toBe('not today');
});

it('replays each tool output once on the next turn', function (): void {
    // All three tool result rows stay stored. Replayed as three messages, the
    // search output went twice, and Anthropic would get an empty user turn.
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    approvalSession()->approve(askAboutTheGame()->pendingApprovals()[0]);

    approvalSession()->send('Thanks');

    $outputs = collect(json_decode((string) Http::recorded()[2][0]->body(), true)['input'])
        ->where('type', 'function_call_output');

    expect($outputs)->toHaveCount(2)
        ->and($outputs->pluck('call_id')->unique())->toHaveCount(2)
        // And the approved call did not run again.
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 1]);
});

it('answers several pending approvals together with decide()', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search', 'weather']);
    $response = askAboutTheGame();

    expect($response->pendingApprovals())->toHaveCount(2);

    approvalSession()->decide(array_map(
        fn (ToolApprovalRequest $pending): ToolApprovalResponse => new ToolApprovalResponse($pending->approvalId, true),
        $response->pendingApprovals(),
    ));

    expect($this->ran)->toBe(['search' => 1, 'weather' => 1]);
});

it('refuses the calls it has no answer for when one of several is approved alone', function (): void {
    // Prism denies by default, so approve() on the first of two resumes and the
    // second is refused. That is the reason decide() exists; this pins it.
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search', 'weather']);
    $response = askAboutTheGame();

    approvalSession()->approve($response->pendingApprovals()[0]);

    expect(array_sum($this->ran))->toBe(1)
        ->and(array_values(toolOutputsSent(1)))->toContain('No approval response provided');
});
