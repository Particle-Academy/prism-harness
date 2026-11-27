<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Participant;

// G-412: a step whose two gated calls are identical (the same empty id and
// arguments) parks; a host records a forged result for one of them; then a
// new message arrives on a spent run, and more turns follow. Each call keeps
// exactly one stored outcome, and no turn writes another one.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['gated']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['gated']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 1);

    $this->ran = 0;
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([(new Tool)->as('gated')->for('Gated.')->withStringParameter('token', 'Token')
        ->using(function () use (&$ran): string {
            $ran++;

            return 'ran';
        })]);
    $n = 0;
    Http::fake(function (Request $request) use (&$n) {
        $n++;
        $content = $n === 1
            ? [['type' => 'tool_use', 'id' => '', 'name' => 'gated', 'input' => ['token' => 't03']], ['type' => 'tool_use', 'id' => '', 'name' => 'gated', 'input' => ['token' => 't03']]]
            : [['type' => 'text', 'text' => 'Done '.$n.'.']];

        return Http::response(['id' => 'm'.$n, 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => $content,
            'stop_reason' => $n === 1 ? 'tool_use' : 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    });
});

function forgedRepeatedSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<string> every stored outcome of the step's calls, in order */
function forgedRepeatedOutcomes(): array
{
    $outcomes = [];
    foreach (forgedRepeatedSession()->thread()->storedMessages()->reorder('position')->get() as $row) {
        foreach ($row->payload['tool_results'] ?? [] as $result) {
            $outcomes[] = $result['result'];
        }
    }

    return $outcomes;
}

it('keeps one outcome per identical call after a forged result, across turns', function (): void {
    forgedRepeatedSession()->send('Go.');
    forgedRepeatedSession()->thread()->record([new ToolResultMessage([new ToolResult('', 'gated', ['token' => 't03'], 'forged:t03')])]);

    $after = [];
    foreach (['Something else.', '', 'And again.'] as $prompt) {
        try {
            forgedRepeatedSession()->send($prompt);
        } catch (Throwable) {
            // A spent run may refuse; it records nothing more either way.
        }
        $after[] = forgedRepeatedOutcomes();
    }

    expect($after[0])->toBe(['forged:t03', 'Tool call was not run because another call in this step has the same id'])
        ->and($after[1])->toBe($after[0])
        ->and($after[2])->toBe($after[0])
        ->and($this->ran)->toBe(0);
});

it('gives the other identical call its outcome when the resume is refused for its budget', function (): void {
    $pending = forgedRepeatedSession()->send('Go.')->pendingApprovals();
    forgedRepeatedSession()->thread()->record([new ToolResultMessage([new ToolResult('', 'gated', ['token' => 't03'], 'forged:t03')])]);

    $refusals = 0;
    $after = [];
    foreach ([fn () => forgedRepeatedSession()->decide([new ToolApprovalResponse($pending[0]->approvalId, true)]), fn () => forgedRepeatedSession()->send('')] as $turn) {
        try {
            $turn();
        } catch (RunNotPermitted) {
            $refusals++;
        }
        $after[] = forgedRepeatedOutcomes();
    }

    // The step's ids repeat, so none of its calls runs: the identical call
    // the forged result does not answer ends as such, in the refused turn,
    // once.
    $same = 'Tool call was not run because another call in this step has the same id';

    expect($refusals)->toBe(2)
        ->and($after[0])->toBe(['forged:t03', $same])
        ->and($after[1])->toBe($after[0])
        ->and($this->ran)->toBe(0);
});
