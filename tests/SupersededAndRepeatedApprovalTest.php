<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\Participant;

// G-392/G-397: an approval decides only the call of the step it was asked in,
// and only while that step is the one core resolves.
//
//  - A parked step followed by a later tool-call step is REPLACED: each of its
//    gated calls with no result of its own gets one denial, and a decision
//    for it runs nothing.
//  - A step whose approval requests repeat an APPROVAL id is a repeated-id
//    step: none of its gated calls runs, each gets the repeated-id denial.

const G397_REPLACED_REASON = 'Tool call was not run because a later step replaced it';

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
        ->push(['id' => 'resp_r', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [$call('p1', 'Paris'), $call('r1', 'Rome')], 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_r_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_r', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function supersededSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/**
 * A tool-call step recorded onto the thread, as a host can.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $calls  call id, city, approval id
 */
function supersededRecordStep(array $calls): void
{
    supersededSession()->thread()->record([new AssistantMessage('', array_map(
        fn (array $call): ToolCall => new ToolCall($call[0], 'weather', ['city' => $call[1]]),
        $calls,
    ), [], array_map(
        fn (array $call): ToolApprovalRequest => new ToolApprovalRequest($call[2], $call[0], ToolApprovalRequest::ARGUMENTS_AS_SENT),
        $calls,
    ))], null, ['weather']);
}

/**
 * The distinct results recorded for each city's call.
 *
 * @return array<string, list<string>>
 */
function supersededOutcomes(): array
{
    $outcomes = [];
    foreach (supersededSession()->thread()->storedMessages()->reorder('position')->get() as $stored) {
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

/** @return array<string, Closure(Session, list<ToolApprovalResponse>): mixed> */
function supersededResumes(): array
{
    return [
        'decide' => fn (Session $session, array $decisions): mixed => $session->decide($decisions),
        'streamDecision' => fn (Session $session, array $decisions): mixed => iterator_to_array($session->streamDecision($decisions), false),
        'approve' => fn (Session $session, array $decisions): mixed => $session->approve($decisions[0]->approvalId),
    ];
}

it('denies, once, every call of a parked step that a later tool-call step replaced, and runs nothing for its decisions', function (string $resume): void {
    $pending = supersededSession()->send('Weather in Paris and Rome?')->pendingApprovals();
    supersededRecordStep([['o1', 'Oslo', 'apr_oslo']]);

    supersededResumes()[$resume](supersededSession(), [
        new ToolApprovalResponse($pending[0]->approvalId, true),
        new ToolApprovalResponse($pending[1]->approvalId, true),
    ]);
    supersededSession()->send('');

    expect($this->ran)->toBe([])
        ->and(supersededOutcomes())->toBe([
            'Oslo' => ['No approval response provided'],
            'Paris' => [G397_REPLACED_REASON],
            'Rome' => [G397_REPLACED_REASON],
        ]);

    $old = [];
    foreach (supersededSession()->thread()->storedMessages()->get() as $stored) {
        foreach ($stored->payload['tool_approval_responses'] ?? [] as $response) {
            if (in_array($response['approval_id'] ?? null, [$pending[0]->approvalId, $pending[1]->approvalId], true)) {
                $old[] = [$response['approved'] ?? null, $response['reason'] ?? null];
            }
        }
    }
    expect(array_values(array_unique($old, SORT_REGULAR)))->toBe([[false, G397_REPLACED_REASON]]);
})->with(array_keys(supersededResumes()));

it('still decides the later step by its own approval', function (): void {
    $pending = supersededSession()->send('Weather in Paris and Rome?')->pendingApprovals();
    supersededRecordStep([['o1', 'Oslo', 'apr_oslo']]);

    supersededSession()->decide([
        new ToolApprovalResponse($pending[0]->approvalId, true),
        new ToolApprovalResponse('apr_oslo', true),
    ]);

    expect($this->ran)->toBe(['Oslo'])
        ->and(supersededOutcomes())->toBe([
            'Oslo' => ['Sunny in Oslo'],
            'Paris' => [G397_REPLACED_REASON],
            'Rome' => [G397_REPLACED_REASON],
        ]);
});

it('runs no call of a step whose approval requests repeat an approval id', function (string $resume): void {
    supersededSession()->send('Weather in Paris and Rome?');
    supersededRecordStep([['o1', 'Oslo', 'apr_same'], ['l1', 'Lima', 'apr_same']]);

    supersededResumes()[$resume](supersededSession(), [new ToolApprovalResponse('apr_same', true)]);
    supersededSession()->send('');

    expect($this->ran)->toBe([])
        ->and(supersededOutcomes())->toBe([
            'Lima' => [Session::REPEATED_CALL_ID_REASON],
            'Oslo' => [Session::REPEATED_CALL_ID_REASON],
            'Paris' => [G397_REPLACED_REASON],
            'Rome' => [G397_REPLACED_REASON],
        ]);
})->with(array_keys(supersededResumes()));
