<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentResponse;
use Prism\Harness\Events\ToolApprovalAsked;
use Prism\Harness\Flow\PrismLlmClient;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\Participant;

// Driven through Session::send() and Prism's real OpenAI handler over faked
// HTTP. The rule lives in core, so this needs a core that has it.
$coreHasTheRule = method_exists(ToolCall::class, 'hasMalformedArguments');
$skipReason = 'Needs a particle-academy/prism release that reports unparseable tool arguments.';

beforeEach(function (): void {
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['transfer']);

    $this->checks = 0;
    $this->runs = 0;
    $checks = &$this->checks;
    $runs = &$this->runs;

    app(ToolRegistry::class)->register(
        (new Tool)->as('transfer')->for('Transfer.')->withStringParameter('amount', 'Amount', false)
            ->requiresApproval(function (array $arguments) use (&$checks): bool {
                $checks++;

                return (int) ($arguments['amount'] ?? 0) > 100;
            })
            ->using(function (string ...$arguments) use (&$runs): string {
                $runs++;

                return 'sent';
            })
    );
});

function fakeTransferCall(string $arguments): void
{
    $call = [
        'id' => 'fc_1',
        'type' => 'function_call',
        'status' => 'completed',
        'arguments' => $arguments,
        'call_id' => 'call_1',
        'name' => 'transfer',
    ];
    $usage = ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2];

    Http::fakeSequence()
        ->push(['id' => 'resp_1', 'status' => 'completed', 'model' => 'gpt-4o', 'output' => [$call], 'usage' => $usage])
        ->push((string) file_get_contents(__DIR__.'/Fixtures/openai/text-reply.json'));
}

function sendToTransfer(): AgentResponse
{
    return app(PrismHarness::class)
        ->for(Participant::query()->firstOrCreate(['name' => 'Ada']))
        ->session('chat')
        ->send('Send it.');
}

/** @return array<string, mixed> */
function followUpItem(string $type): array
{
    expect(Http::recorded())->toHaveCount(2);

    $body = json_decode((string) Http::recorded()[1][0]->body(), true);

    return collect($body['input'])->firstWhere('type', $type) ?? [];
}

it('reaches no approval check and no handler on unparseable arguments', function (string $arguments): void {
    fakeTransferCall($arguments);

    $response = sendToTransfer();

    expect($this->checks)->toBe(0)
        ->and($this->runs)->toBe(0)
        ->and($response->awaitingApproval())->toBeFalse()
        ->and(followUpItem('function_call_output')['output'])->toContain('Tool call arguments for tool transfer')
        ->and(followUpItem('function_call')['arguments'])->toBe('{}');
})->with([
    'truncated JSON' => ['{"amount": 5'],
    'a number' => ['5'],
    'a list' => ['[1,2]'],
])->skip(! $coreHasTheRule, $skipReason);

it('asks nobody when the mode gates the tool', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['transfer']);
    Event::fake([ToolApprovalAsked::class]);
    fakeTransferCall('{"amount": 5');

    $response = sendToTransfer();

    expect($response->awaitingApproval())->toBeFalse()
        ->and($response->pendingApprovals())->toBe([])
        ->and($this->checks)->toBe(0)
        ->and($this->runs)->toBe(0);
    Event::assertNotDispatched(ToolApprovalAsked::class);
})->skip(! $coreHasTheRule, $skipReason);

it('streams a malformed call as a failed step without crashing', function (string $arguments): void {
    config()->set('prism.providers.openai.api_format', 'chat_completions');
    $line = fn (array $choice): string => 'data: '.json_encode(['id' => 'c1', 'model' => 'gpt-4o', 'choices' => [$choice]])."\n\n";
    Http::fakeSequence()
        ->push($line(['index' => 0, 'delta' => ['role' => 'assistant', 'tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'transfer', 'arguments' => $arguments]]]], 'finish_reason' => null])
            .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'tool_calls'])."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream'])
        ->push($line(['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'done'], 'finish_reason' => null])
            .$line(['index' => 0, 'delta' => (object) [], 'finish_reason' => 'stop'])."data: [DONE]\n\n", 200, ['Content-Type' => 'text/event-stream']);

    $steps = [];
    $crash = null;

    try {
        foreach (app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat')->streamSteps('Send it.') as $step) {
            $steps[] = $step;
        }
    } catch (Throwable $failure) {
        $crash = $failure::class.': '.$failure->getMessage();
    }

    expect($crash)->toBeNull()
        ->and($this->checks)->toBe(0)
        ->and($this->runs)->toBe(0)
        ->and($steps)->not->toBe([]);
})->with([
    'truncated JSON' => ['{"amount": 5'],
    'a number' => ['5'],
])->skip(! $coreHasTheRule, $skipReason);

function flowCompletion(string $arguments): array
{
    fakeTransferCall($arguments);

    return (new PrismLlmClient('openai', 'gpt-4o', null, ['transfer' => app(ToolRegistry::class)->resolve(['transfer'])['transfer']]))
        ->complete('Send it.', ['tools' => ['transfer']]);
}

it('does not hand a workflow an unparseable call to invoke', function (): void {
    $result = flowCompletion('{"amount": 5');

    expect($result['tool_calls'] ?? [])->toBe([])
        ->and($this->runs)->toBe(0);
});

it('does not hand a workflow a non-object call to invoke', function (string $arguments): void {
    $result = flowCompletion($arguments);

    expect($result['tool_calls'] ?? [])->toBe([])
        ->and($this->runs)->toBe(0);
})->with(['a number' => ['5'], 'a list' => ['[1,2]']])->skip(! $coreHasTheRule, $skipReason);

it('still hands a workflow a well-formed call', function (): void {
    $result = flowCompletion('{"amount":"5"}');

    expect($result['tool_calls'][0]['name'] ?? null)->toBe('transfer')
        ->and($result['tool_calls'][0]['arguments'] ?? null)->toBe(['amount' => '5']);
});

it('still checks and runs a well-formed call', function (): void {
    fakeTransferCall('{"amount":"5"}');

    sendToTransfer();

    expect($this->checks)->toBe(1)->and($this->runs)->toBe(1);
});
