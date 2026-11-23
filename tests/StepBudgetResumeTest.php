<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentResponse;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// The step limit belongs to the RUN. A run that pauses for a person and is
// resumed is the same run, so every resume continues the same count rather than
// starting a new one. Driven through Prism's real provider handlers over faked
// HTTP, entered through Session::send() / approve() / streamDecision(), with a model that asks for a gated tool on every step.

const STEP_BUDGET_MAX = 3;
const STEP_BUDGET_APPROVALS = 6;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.providers.anthropic.api_key', 'synthetic-offline');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.max_steps', STEP_BUDGET_MAX);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = 0;
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')
            ->using(function () use (&$ran): string {
                $ran++;

                return 'sunny';
            }),
    ]);

    $this->modelCalls = 0;
    $calls = &$this->modelCalls;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;
        if (str_contains($request->url(), 'anthropic')) {
            // Streamed when the request asks for it, as a real handler does.
            return ($request->data()['stream'] ?? false) === true
                ? Http::response(anthropicToolUseSse('toolu_'.$calls), 200, ['Content-Type' => 'text/event-stream'])
                : Http::response(['id' => 'msg_'.$calls, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-test',
                    'content' => [['type' => 'tool_use', 'id' => 'toolu_'.$calls, 'name' => 'weather', 'input' => new stdClass]],
                    'stop_reason' => 'tool_use', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
        }

        return Http::response([
            'id' => 'resp_'.$calls,
            'object' => 'response',
            'created_at' => 1741989983,
            'status' => 'completed',
            'model' => 'gpt-4o-2024-08-06',
            'output' => [[
                'id' => 'fc_'.$calls,
                'type' => 'function_call',
                'status' => 'completed',
                'arguments' => '{}',
                'call_id' => 'call_'.$calls,
                'name' => 'weather',
            ]],
            'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2],
        ]);
    });
});

/** One Anthropic streamed step asking for the gated tool. */
function anthropicToolUseSse(string $id): string
{
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_'.$id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-test',
            'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => 'weather', 'input' => new stdClass]],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null], 'usage' => ['output_tokens' => 1]],
        ['type' => 'message_stop'],
    ];
    $newline = chr(10);

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type'].$newline.'data: '.json_encode($event).$newline.$newline, $events));
}

function stepBudgetSession(bool $fresh = false): Session
{
    if ($fresh) {
        // A different worker: nothing held in memory survives, only the stores.
        app()->forgetInstance(PrismHarness::class);
        app()->forgetInstance(AgentRuntime::class);
    }

    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** The approval id waiting at the transcript tail, if any. */
function stepBudgetPending(): ?string
{
    $row = stepBudgetSession()->thread()->storedMessages()->where('type', 'assistant')->reorder('position', 'desc')->first();

    return $row?->payload['tool_approval_requests'][0]['approval_id'] ?? null;
}

/**
 * Approve every pause, up to STEP_BUDGET_APPROVALS times. Returns the last
 * response, or the refusal that ended the run.
 */
function approveRepeatedly(bool $fresh): AgentResponse|RunNotPermitted
{
    $response = stepBudgetSession($fresh)->send('What is the weather?');
    for ($index = 0; $index < STEP_BUDGET_APPROVALS && $response->awaitingApproval(); $index++) {
        try {
            $response = stepBudgetSession($fresh)->approve($response->pendingApprovals()[0]);
        } catch (RunNotPermitted $refused) {
            return $refused;
        }
    }

    return $response;
}

it('never takes more than max_steps model steps across repeated approvals', function (): void {
    $last = approveRepeatedly(fresh: false);

    expect($this->modelCalls)->toBe(STEP_BUDGET_MAX)
        ->and($last)->toBeInstanceOf(RunNotPermitted::class)
        ->and($last->getMessage())->toContain('step budget exhausted (3 of 3 used)');
});

it('never takes more than max_steps model steps across approvals resumed from persisted state', function (): void {
    $last = approveRepeatedly(fresh: true);

    expect($this->modelCalls)->toBe(STEP_BUDGET_MAX)
        ->and($last)->toBeInstanceOf(RunNotPermitted::class);
});

it('holds through streamDecision() resumes too', function (): void {
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-test');
    // Parked by send(): a plain streamed turn only parks on newer cores, and
    // the resume below always takes the step path, which every core supports.
    stepBudgetSession(true)->send('What is the weather?');
    $pending = stepBudgetPending();
    expect($pending)->toBeString();
    $refused = null;

    for ($index = 0; $index < STEP_BUDGET_APPROVALS && $pending !== null; $index++) {
        try {
            foreach (stepBudgetSession(true)->streamDecision([new ToolApprovalResponse($pending, true)]) as $step) {
                // drained
            }
        } catch (RunNotPermitted $failure) {
            $refused = $failure;

            break;
        }
        $next = stepBudgetPending();
        $pending = $next === $pending ? null : $next;
    }

    expect($this->modelCalls)->toBe(STEP_BUDGET_MAX)
        ->and($refused)->toBeInstanceOf(RunNotPermitted::class);
});

it('gives a new prompt after the budget is spent a fresh budget', function (): void {
    approveRepeatedly(fresh: false);

    $next = stepBudgetSession()->send('Another task');

    expect($next->awaitingApproval())->toBeTrue()
        ->and($this->modelCalls)->toBe(STEP_BUDGET_MAX + 1);
});
