<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// G-392/G-397: which rows supersede a parked step.
//
//  - An assistant text row or a user row recorded OUTSIDE the run that owns
//    the step supersedes it: each of its calls with no result gets one
//    denial, and no later decision for it runs anything. Thread::record() is
//    outside, whatever run id it is given.
//  - The rows the harness writes inside a run, such as a structured turn's
//    own answer, never do.

const SUPERSEDING_REPLACED = 'Tool call was not run because a later step replaced it';

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $ran = &$this->ran;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran[] = $city;

                return "Sunny in {$city}";
            }),
    ]);

    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    $call = fn (string $id, string $city): array => [
        'id' => $id, 'type' => 'function_call', 'status' => 'completed',
        'arguments' => json_encode(['city' => $city]), 'call_id' => $id, 'name' => 'weather',
    ];
    Http::fakeSequence()
        ->push(['id' => 'resp_s', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [$call('p1', 'Paris'), $call('r1', 'Rome')], 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_s_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_s', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => '{"ok":true}', 'annotations' => []]]]], 'usage' => $usage]));
});

function supersedingSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return array<string, list<string>> the distinct results recorded for each city's call */
function supersedingOutcomes(): array
{
    $outcomes = [];
    foreach (supersedingSession()->thread()->storedMessages()->reorder('position')->get() as $stored) {
        $message = $stored->toPrismMessage();
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $outcomes[(string) ($result->args['city'] ?? '?')][] = (string) $result->result;
            }
        }
    }
    ksort($outcomes);

    return array_map(fn (array $results): array => array_values(array_unique($results)), $outcomes);
}

/** @return array<string, Closure(Session): ?string> the run id each row is recorded under */
function supersedingRunIds(): array
{
    return [
        'no run id' => fn (Session $session): ?string => null,
        'the parked run\'s own id' => fn (Session $session): ?string => $session->run()['id'] ?? null,
        'a made-up run id' => fn (Session $session): ?string => 'run_forged',
    ];
}

/** @return array<string, Message> */
function supersedingRows(): array
{
    return [
        'an assistant note' => new AssistantMessage('Waiting for your approval.'),
        'the user\'s follow-up' => new UserMessage('Hurry.'),
    ];
}

it('supersedes a parked step with a text or user row recorded outside its run, whatever run id it carries', function (string $row, string $runId): void {
    $pending = supersedingSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    supersedingSession()->thread()->record([supersedingRows()[$row]], supersedingRunIds()[$runId](supersedingSession()));
    supersedingSession()->decide(array_map(fn ($request): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, true), $pending));
    supersedingSession()->send('');

    $decisions = [];
    foreach (supersedingSession()->thread()->storedMessages()->get() as $stored) {
        foreach ($stored->payload['tool_approval_responses'] ?? [] as $response) {
            $decisions[] = [$response['approved'] ?? null, $response['reason'] ?? null];
        }
    }

    expect($this->ran)->toBe([])
        ->and(supersedingOutcomes())->toBe(['Paris' => [SUPERSEDING_REPLACED], 'Rome' => [SUPERSEDING_REPLACED]])
        ->and(array_values(array_unique($decisions, SORT_REGULAR)))->toBe([[false, SUPERSEDING_REPLACED]]);
})->with(array_keys(supersedingRows()))->with(array_keys(supersedingRunIds()));

it('does not supersede a parked step with a structured turn\'s own answer', function (): void {
    $pending = supersedingSession()->send('Weather in Paris and Rome?')->pendingApprovals();

    // The decisions recorded, then a structured turn (its answer is the
    // harness's own row), then the resume that acts on them.
    supersedingSession()->thread()->record([new ToolResultMessage(toolApprovalResponses: [
        new ToolApprovalResponse($pending[0]->approvalId, true),
        new ToolApprovalResponse($pending[1]->approvalId, false),
    ])], supersedingSession()->run()['id'] ?? null);
    supersedingSession()->sendStructured('', new ObjectSchema('answer', 'Answer', [new BooleanSchema('ok', 'Done')], ['ok']));
    supersedingSession()->send('');

    expect($this->ran)->toBe(['Paris'])
        ->and(supersedingOutcomes()['Paris'])->toBe(['Sunny in Paris'])
        ->and(supersedingOutcomes()['Rome'])->not->toContain(SUPERSEDING_REPLACED);
});
