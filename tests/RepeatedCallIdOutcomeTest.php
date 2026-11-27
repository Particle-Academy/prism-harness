<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Steps\RunStep;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// A step whose call ids repeat runs none of its gated calls (G-392), and each
// gated call still ends with exactly ONE outcome: the denial. A result names
// its call only by id, so an ungated call that ran under the same id must not
// make the gated call read as done. Results are matched to calls by position.
// What a decision stream reports is what was recorded.

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather', 'clock']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $ran = &$this->ran;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran[] = $city;

                return "Sunny in {$city}";
            }),
        (new Tool)->as('clock')->for('Clock.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran[] = "clock {$city}";

                return "Noon in {$city}";
            }),
    ]);
});

/**
 * One Responses step, then a text reply for every later request.
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $calls  id, tool, city
 */
function g392OutcomeFake(array $calls): void
{
    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];

    Http::fakeSequence()
        ->push(['id' => 'resp_g392o', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => array_map(fn (array $call): array => [
                'id' => $call[0], 'type' => 'function_call', 'status' => 'completed',
                'arguments' => json_encode(['city' => $call[2]]), 'call_id' => $call[0], 'name' => $call[1],
            ], $calls), 'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_g392o_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_g392o', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
}

function g392OutcomeSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/**
 * Every distinct recorded result, by the tool and city of its call. Core
 * writes a resolved step's earlier results again on the row that resolves
 * it, so a result repeated unchanged is one outcome.
 *
 * @return array<string, list<string>>
 */
function g392Outcomes(): array
{
    $outcomes = [];
    foreach (g392OutcomeSession()->thread()->storedMessages()->reorder('position')->get() as $stored) {
        $message = $stored->toPrismMessage();
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $outcomes[$result->toolName.' '.($result->args['city'] ?? '?')][] = (string) $result->result;
            }
        }
    }

    return array_map(fn (array $results): array => array_values(array_unique($results)), $outcomes);
}

/** @return array<string, Closure(Session, list<ToolApprovalResponse>): mixed> */
function g392OutcomeResumes(): array
{
    return [
        'decide' => fn (Session $session, array $decisions): mixed => $session->decide($decisions),
        'streamDecision' => fn (Session $session, array $decisions): mixed => iterator_to_array($session->streamDecision($decisions), false),
        'approve' => fn (Session $session, array $decisions): mixed => $session->approve($decisions[0]->approvalId),
        'a new message' => fn (Session $session, array $decisions): mixed => $session->send('Never mind.'),
        'an empty resume' => fn (Session $session, array $decisions): mixed => $session->send(''),
        'a stream resume' => fn (Session $session, array $decisions): mixed => iterator_to_array($session->stream(''), false),
    ];
}

it('gives a gated call that shares its id with an ungated call that ran exactly one outcome, the denial', function (string $resume): void {
    g392OutcomeFake([['w', 'weather', 'Paris'], ['w', 'clock', 'Paris']]);

    $pending = g392OutcomeSession()->send('Weather and time in Paris?')->pendingApprovals();

    expect($pending)->toHaveCount(1)
        ->and($this->ran)->toBe(['clock Paris']);

    g392OutcomeResumes()[$resume](g392OutcomeSession(), [new ToolApprovalResponse($pending[0]->approvalId, true)]);
    // Resuming again over the history changes nothing.
    g392OutcomeSession()->send('');

    expect($this->ran)->toBe(['clock Paris'])
        ->and(g392Outcomes())->toBe([
            'clock Paris' => ['Noon in Paris'],
            'weather Paris' => [Session::REPEATED_CALL_ID_REASON],
        ]);
})->with(array_keys(g392OutcomeResumes()));

it('matches results to calls by position when an ungated call comes first', function (): void {
    g392OutcomeFake([['w', 'clock', 'Rome'], ['w', 'weather', 'Paris'], ['', 'weather', 'Oslo'], ['', 'clock', 'Oslo']]);

    $pending = g392OutcomeSession()->send('Go.')->pendingApprovals();

    g392OutcomeSession()->decide(array_map(fn ($request): ToolApprovalResponse => new ToolApprovalResponse($request->approvalId, true), $pending));

    expect($this->ran)->toBe(['clock Rome', 'clock Oslo'])
        ->and(g392Outcomes())->toBe([
            'clock Rome' => ['Noon in Rome'],
            'clock Oslo' => ['Noon in Oslo'],
            'weather Paris' => [Session::REPEATED_CALL_ID_REASON],
            'weather Oslo' => [Session::REPEATED_CALL_ID_REASON],
        ]);
});

it('reports the decisions a decision stream recorded, not the ones it was sent', function (): void {
    g392OutcomeFake([['w', 'weather', 'Paris'], ['w', 'clock', 'Paris']]);

    $pending = g392OutcomeSession()->send('Weather and time in Paris?')->pendingApprovals();

    $recorded = [];
    foreach (g392OutcomeSession()->streamDecision([new ToolApprovalResponse($pending[0]->approvalId, true)]) as $step) {
        if ($step->type === RunStep::DECISION_RECORDED) {
            $recorded[] = [$step->data['approvalId'], $step->data['verdict'], $step->data['reason']];
        }
    }

    expect($recorded)->toBe([[$pending[0]->approvalId, 'denied', Session::REPEATED_CALL_ID_REASON]])
        ->and($this->ran)->toBe(['clock Paris']);
});

it('leaves a distinct-id step decided call by call', function (): void {
    g392OutcomeFake([['w1', 'weather', 'Paris'], ['c1', 'clock', 'Paris']]);

    $pending = g392OutcomeSession()->send('Weather and time in Paris?')->pendingApprovals();

    g392OutcomeSession()->decide([new ToolApprovalResponse($pending[0]->approvalId, true)]);

    expect($this->ran)->toBe(['clock Paris', 'Paris'])
        ->and(g392Outcomes())->toBe([
            'clock Paris' => ['Noon in Paris'],
            'weather Paris' => ['Sunny in Paris'],
        ]);
});
