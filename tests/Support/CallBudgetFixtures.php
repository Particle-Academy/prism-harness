<?php

declare(strict_types=1);

use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Structured\Step as StructuredStep;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\Text\Step;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\Participant;

function callBudgetSession(string $scope = 'budget'): Session
{
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'budget-model');
    config()->set('prism-harness.agent.modes.chat.tools', ['tick']);
    $tools = app(ToolRegistry::class);
    $tool = (new Tool)->as('tick')->for('Count')->using(fn (): string => 'done');
    if (in_array('tick', $tools->names(), true)) {
        $tools->replace($tool, 'legacy');
    } else {
        $tools->register($tool);
    }

    return app(PrismHarness::class)->for(Participant::firstOrCreate(['name' => 'Budget']))->session($scope);
}

/** Tests replacing the baseline fixture must identify its predecessor explicitly. */
function replaceCallBudgetTool(Tool $tool): void
{
    app(ToolRegistry::class)->replace($tool, 'legacy');
}

function callBudgetContext(float $cap = 0.17, ?int $maxOutput = 10): RunContext
{
    // Synthetic rates isolate admission: each call reserves $0.10 while its
    // reported actual cost is $0.04. The third call cannot fit under $0.17.
    return RunContext::root('budget-root', new RunBudget(10, $cap, maxOutputTokensPerCall: $maxOutput), [
        'anthropic' => ['budget-model' => ['inputPerMTok' => 0, 'outputPerMTok' => 10000, 'toolOverheadTokens' => 286]],
    ]);
}

function callBudgetSchema(): ObjectSchema
{
    return new ObjectSchema('answer', 'Answer', [new BooleanSchema('ok', 'Done')], ['ok']);
}

/** @return list<TextResponse|StructuredResponse> */
function callBudgetResponses(string $shape, bool $finish = false, array $costs = [0.04, 0.04, 0.04]): array
{
    $messages = [new UserMessage('go')];
    $responses = [];
    foreach ($costs as $index => $cost) {
        $terminal = $finish && $index === array_key_last($costs);
        $text = $terminal && $shape === 'structured' ? '{"ok":true}' : ($terminal ? 'done' : 'step '.($index + 1));
        $calls = $terminal ? [] : [new ToolCall('call-'.($index + 1), 'tick', [])];
        $results = $terminal ? [] : [new ToolResult('call-'.($index + 1), 'tick', [], 'result '.($index + 1))];
        $reason = $terminal ? FinishReason::Stop : FinishReason::ToolCalls;
        $usage = new Usage(3, 2, cost: $cost);
        $meta = new Meta('call-'.($index + 1), 'budget-model');
        $before = $messages;
        $messages[] = new AssistantMessage($text, $calls);
        if ($results !== []) {
            $messages[] = new ToolResultMessage($results);
        }
        if ($shape === 'structured') {
            $step = new StructuredStep(text: $text, finishReason: $reason, usage: $usage, meta: $meta,
                messages: $messages, systemPrompts: [], structured: $terminal ? ['ok' => true] : [], toolCalls: $calls, toolResults: $results);
            $responses[] = new StructuredResponse(collect([$step]), $text, $terminal ? ['ok' => true] : [],
                $reason, $usage, $meta, $calls, toolResults: $results);
        } else {
            $step = new Step($text, $reason, $calls, $results, [], $usage, $meta, $before, []);
            $responses[] = new TextResponse(collect([$step]), $text, $reason, $calls, $results, $usage, $meta, collect($messages));
        }
    }

    return $responses;
}

function callBudgetRun(string $shape, Session $session, ?RunContext $context, array $attachments = []): void
{
    if ($shape === 'structured') {
        $session->sendStructured('go', callBudgetSchema(), additionalContent: $attachments, context: $context);
    } elseif ($shape === 'stream') {
        foreach ($session->stream('go', additionalContent: $attachments, context: $context) as $event) {
        }
    } else {
        $session->send('go', additionalContent: $attachments, context: $context);
    }
}
