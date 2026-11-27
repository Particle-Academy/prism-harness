<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// A decision for an approval whose call already has an outcome is not
// recorded: the call is no longer pending, and a stored "approved" for a call
// that was denied (or a later decision for a call that already ran) would
// report something that did not happen. Calls are paired with their outcome
// as the reader of unresolved calls pairs them.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 3);

    $this->ran = 0;
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([(new Tool)->as('weather')->for('Weather.')->using(function () use (&$ran): string {
        $ran++;

        return 'sunny';
    })]);
    $n = 0;
    Http::fake(function (Request $request) use (&$n) {
        $n++;
        $content = $n === 1 ? [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'weather', 'input' => new stdClass]] : [['type' => 'text', 'text' => 'Done.']];
        if ((json_decode((string) $request->body(), true)['stream'] ?? false) === true) {
            $nl = chr(10);
            $events = [['type' => 'message_start', 'message' => ['id' => 'm'.$n, 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Done.']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => null], 'usage' => ['output_tokens' => 1]],
                ['type' => 'message_stop']];

            return Http::response(implode('', array_map(fn (array $e): string => 'event: '.$e['type'].$nl.'data: '.json_encode($e).$nl.$nl, $events)), 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response(['id' => 'm'.$n, 'type' => 'message', 'role' => 'assistant', 'model' => 'x', 'content' => $content,
            'stop_reason' => $n === 1 ? 'tool_use' : 'end_turn', 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    });
});

function decisionAfterOutcomeSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<bool> every decision row stored for `$approvalId`, in order (a result row repeating it is not a decision) */
function decisionAfterOutcomeStored(string $approvalId): array
{
    $decisions = [];
    foreach (decisionAfterOutcomeSession()->thread()->storedMessages()->reorder('position')->get() as $row) {
        if (($row->payload['tool_results'] ?? []) !== []) {
            continue;
        }
        foreach ($row->payload['tool_approval_responses'] ?? [] as $decision) {
            if (($decision['approval_id'] ?? null) === $approvalId) {
                $decisions[] = $decision['approved'];
            }
        }
    }

    return $decisions;
}

/** @return array<string, Closure(string, bool): list<string>> each path, returning what a stream reported */
function decisionAfterOutcomePaths(): array
{
    return [
        'approve or deny' => function (string $id, bool $approved): array {
            decisionAfterOutcomeSession()->approve($id, $approved);

            return [];
        },
        'decide' => function (string $id, bool $approved): array {
            decisionAfterOutcomeSession()->decide([new ToolApprovalResponse($id, $approved)]);

            return [];
        },
        'streamDecision' => function (string $id, bool $approved): array {
            $reported = [];
            foreach (decisionAfterOutcomeSession()->streamDecision([new ToolApprovalResponse($id, $approved)]) as $step) {
                if ($step->type === 'decision_recorded') {
                    $reported[] = json_encode($step->data);
                }
            }

            return $reported;
        },
    ];
}

it('records no approval for a call an empty resume already closed', function (string $path): void {
    $id = decisionAfterOutcomeSession()->send('Weather?')->pendingApprovals()[0]->approvalId;
    // An empty resume with no decision: the call's outcome is the default denial.
    decisionAfterOutcomeSession()->send('');

    $reported = decisionAfterOutcomePaths()[$path]($id, true);

    expect(decisionAfterOutcomeStored($id))->toBe([])
        ->and($reported)->toBe([])
        ->and($this->ran)->toBe(0);
})->with(array_keys(decisionAfterOutcomePaths()));

it('records no later decision for a call that already ran', function (string $path): void {
    $id = decisionAfterOutcomeSession()->send('Weather?')->pendingApprovals()[0]->approvalId;
    decisionAfterOutcomeSession()->approve($id);

    $reported = decisionAfterOutcomePaths()[$path]($id, false);

    expect(decisionAfterOutcomeStored($id))->toBe([true])
        ->and($reported)->toBe([])
        ->and($this->ran)->toBe(1);
})->with(array_keys(decisionAfterOutcomePaths()));

it('still records a decision for a call with no outcome', function (string $path): void {
    $id = decisionAfterOutcomeSession()->send('Weather?')->pendingApprovals()[0]->approvalId;

    decisionAfterOutcomePaths()[$path]($id, true);

    expect(decisionAfterOutcomeStored($id))->toBe([true])
        ->and($this->ran)->toBe(1);
})->with(array_keys(decisionAfterOutcomePaths()));
