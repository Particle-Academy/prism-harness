<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Participant;

// G-392/G-397: the repeated-id guard selects the paused step by core's rule.
// Rows a host records between the park and the decision (an assistant note,
// the user's follow-up, a system row, a tool result row) do not change which
// step a decision belongs to. A step whose call ids repeat runs none of its
// gated calls, and each of them ends with exactly one outcome, the denial.

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
    $call = fn (string $city): array => [
        'id' => 'w', 'type' => 'function_call', 'status' => 'completed',
        'arguments' => json_encode(['city' => $city]), 'call_id' => 'w', 'name' => 'weather',
    ];
    Http::fakeSequence()
        ->push(['id' => 'resp_g397', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [$call('Paris'), $call('Rome')], 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_g397_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_g397', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function g397Session(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/**
 * The denial each call ends with: a text or user row recorded outside the
 * step's run supersedes the step; a system or tool result row does not.
 */
function g397Reason(string $row): string
{
    return in_array($row, ['an assistant note', "the user's follow-up"], true)
        ? 'Tool call was not run because a later step replaced it'
        : Session::REPEATED_CALL_ID_REASON;
}

/** @return array<string, Message> */
function g397Rows(): array
{
    return [
        'an assistant note' => new AssistantMessage('Waiting for your approval.'),
        'the user\'s follow-up' => new UserMessage('Hurry.'),
        'a system row' => new SystemMessage('Approvals are pending.'),
        'a tool result row' => new ToolResultMessage([new ToolResult('other', 'weather', ['city' => 'Oslo'], 'Cloudy in Oslo')]),
    ];
}

/** @return array<string, Closure(Session, ToolApprovalRequest): mixed> */
function g397Resumes(): array
{
    return [
        'decide' => fn (Session $session, ToolApprovalRequest $last): mixed => $session->decide([new ToolApprovalResponse($last->approvalId, true)]),
        'approve' => fn (Session $session, ToolApprovalRequest $last): mixed => $session->approve($last),
        'streamDecision' => fn (Session $session, ToolApprovalRequest $last): mixed => iterator_to_array($session->streamDecision([new ToolApprovalResponse($last->approvalId, true)]), false),
    ];
}

/**
 * The distinct results recorded for each city's call.
 *
 * @return array<string, list<string>>
 */
function g397Outcomes(): array
{
    $outcomes = [];
    foreach (g397Session()->thread()->storedMessages()->reorder('position')->get() as $stored) {
        $message = $stored->toPrismMessage();
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                if ($result->toolCallId === 'w') {
                    $outcomes[(string) ($result->args['city'] ?? '?')][] = (string) $result->result;
                }
            }
        }
    }
    ksort($outcomes);

    return array_map(fn (array $results): array => array_values(array_unique($results)), $outcomes);
}

it('runs no call of a repeated-id step when rows were recorded between the park and the decision', function (string $row, string $resume): void {
    $pending = g397Session()->send('Weather in Paris and Rome?')->pendingApprovals();
    expect($pending)->toHaveCount(2);

    g397Session()->thread()->record([g397Rows()[$row]]);

    g397Resumes()[$resume](g397Session(), $pending[1]);
    // Resuming again over the history changes nothing.
    g397Session()->send('');

    expect($this->ran)->toBe([])
        ->and(g397Outcomes())->toBe([
            'Paris' => [g397Reason($row)],
            'Rome' => [g397Reason($row)],
        ]);
})->with(array_keys(g397Rows()))->with(array_keys(g397Resumes()));

it('records every decision for the step as a denial when a row was recorded between', function (string $row): void {
    $pending = g397Session()->send('Weather in Paris and Rome?')->pendingApprovals();

    g397Session()->thread()->record([g397Rows()[$row]]);
    g397Session()->decide([new ToolApprovalResponse($pending[0]->approvalId, true), new ToolApprovalResponse($pending[1]->approvalId, true)]);

    $decisions = [];
    foreach (g397Session()->thread()->storedMessages()->get() as $stored) {
        foreach ($stored->payload['tool_approval_responses'] ?? [] as $response) {
            $decisions[] = [$response['approved'] ?? null, $response['reason'] ?? null];
        }
    }

    expect($decisions)->not->toBe([])
        ->and(array_values(array_unique($decisions, SORT_REGULAR)))->toBe([[false, g397Reason($row)]])
        ->and($this->ran)->toBe([]);
})->with(array_keys(g397Rows()));
