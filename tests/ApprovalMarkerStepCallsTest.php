<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Subagents\BudgetedCalls;
use Prism\Harness\Subagents\CallBudget;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\StepCalls;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

// The step-only path (a mode with subagents, or a child run) and the budgeted
// path build the pending approval requests from core's stream events. They
// must keep core's marker, or an approval on a tool with an `input` parameter
// expires on resume and the tool never runs.

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function markerStepSse(string $id, string $arguments): string
{
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_'.$id, 'type' => 'message', 'role' => 'assistant', 'model' => 'm', 'content' => [], 'stop_reason' => null, 'usage' => ['input_tokens' => 3, 'output_tokens' => 0]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => $id, 'name' => 'translate', 'input' => new stdClass]],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $arguments]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null], 'usage' => ['output_tokens' => 2]],
        ['type' => 'message_stop'],
    ];

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events));
}

function markerStepText(): array
{
    return ['id' => 'msg_done', 'type' => 'message', 'role' => 'assistant', 'model' => 'm', 'content' => [['type' => 'text', 'text' => 'Bonjour']],
        'stop_reason' => 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
}

/**
 * Pause on the gated call through one of the two paths, store the recorded
 * assistant message as JSON as the thread does, read it back, approve, resume.
 *
 * @return array{stored: array<string, mixed>, runs: int}
 */
function markerStepRun(string $path): array
{
    $runs = 0;
    $tool = (new Tool)->as('translate')->for('Translate.')->withStringParameter('input', 'Text')->requiresApproval()
        ->using(function (string $input) use (&$runs): string {
            $runs++;

            return 'Bonjour';
        });

    $run = $path === 'budgeted'
        ? RunContext::root('marker-budgeted', new RunBudget(3, 10.0, maxOutputTokensPerCall: 10), [
            'anthropic' => ['m' => ['inputPerMTok' => 1, 'outputPerMTok' => 1, 'toolOverheadTokens' => 286]],
        ])
        : RunContext::root('marker-step', new RunBudget(3));
    $calls = fn (): StepCalls|BudgetedCalls => $path === 'budgeted'
        ? new BudgetedCalls(CallBudget::forRun($run, 'anthropic', 'm', []))
        : new StepCalls;

    Http::fakeSequence()
        ->push(markerStepSse('toolu_1', '{"input":"Hello"}'), 200, ['Content-Type' => 'text/event-stream'])
        ->push(markerStepText());

    $recorded = [];
    $record = function (array $messages) use (&$recorded): void {
        $recorded = [...$recorded, ...$messages];
    };
    $generation = Prism::text()->using('anthropic', 'm', ['api_key' => 'test-only'])->withTools([$tool])->withMessages([new UserMessage('Translate hello.')]);
    iterator_to_array($calls()->stream($generation, $run, $record, static function (): void {}), false);

    $assistant = collect($recorded)->first(fn ($m): bool => $m instanceof AssistantMessage && $m->toolApprovalRequests !== []);
    $stored = json_decode((string) json_encode($assistant?->toolApprovalRequests[0]->toArray() ?? []), true);
    $request = ToolApprovalRequest::fromArray($stored);

    $resume = Prism::text()->using('anthropic', 'm', ['api_key' => 'test-only'])->withTools([$tool])->withMessages([
        new UserMessage('Translate hello.'),
        new AssistantMessage('', $assistant?->toolCalls ?? [], [], [$request]),
        new ToolResultMessage([], [new ToolApprovalResponse($request->approvalId, true)]),
    ]);
    $resume->asText();

    return ['stored' => $stored, 'runs' => $runs];
}

it('keeps the marker on the step-only and budgeted stream paths, so the approved call runs', function (string $path): void {
    $outcome = markerStepRun($path);

    expect($outcome['stored'])->toHaveKey('arguments_format')
        ->and($outcome['runs'])->toBe(1);
})->with(['step-only', 'budgeted']);
