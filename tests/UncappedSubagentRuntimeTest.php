<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\Subagent;
use Tests\Fixtures\Participant;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

/** @return array<string, mixed> */
function uncappedDelegationReply(int $index, bool $delegate = false): array
{
    return [
        'id' => 'uncapped-'.$index, 'type' => 'message', 'role' => 'assistant', 'model' => 'budget-model',
        'content' => $delegate
            ? [['type' => 'tool_use', 'id' => 'delegate-call', 'name' => 'run_child', 'input' => ['task' => 'A synthetic child task.']]]
            : [['type' => 'text', 'text' => $index === 2 ? 'synthetic child answer' : 'synthetic parent answer']],
        'stop_reason' => $delegate ? 'tool_use' : 'end_turn', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
    ];
}

/** @param array<string, mixed> $reply */
function uncappedDelegationSse(array $reply): string
{
    $events = [['type' => 'message_start', 'message' => [...$reply, 'content' => [], 'stop_reason' => null,
        'usage' => ['input_tokens' => 3, 'output_tokens' => 0]]]];
    foreach ($reply['content'] as $index => $block) {
        $tool = $block['type'] === 'tool_use';
        $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => $tool
            ? [...$block, 'input' => new stdClass] : ['type' => 'text', 'text' => '']];
        $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => $tool
            ? ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'], JSON_THROW_ON_ERROR)]
            : ['type' => 'text_delta', 'text' => $block['text']]];
        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $reply['stop_reason'], 'stop_sequence' => null],
        'usage' => ['output_tokens' => 2]];
    $events[] = ['type' => 'message_stop'];

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events));
}

beforeEach(function (): void {
    config()->set('prism.providers.anthropic.api_key', 'synthetic-test-key');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'budget-model');
    config()->set('prism-harness.agent.max_cost_usd', null);
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.skills', []);
    config()->set('prism-harness.agent.modes.chat.max_steps', 8);
    config()->set('prism-harness.agent.modes.child', [
        'system_prompt' => 'A synthetic child.', 'tools' => [], 'skills' => [], 'max_steps' => 2,
    ]);
    Http::preventStrayRequests();
});

it('offers and executes a declared subagent through the public uncapped runtime', function (string $shape): void {
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_child' => ['description' => 'Run a synthetic child.', 'mode' => 'child', 'max_steps' => 2],
    ]);
    $requests = [];
    Http::fake(function (HttpRequest $wire) use (&$requests) {
        $body = $wire->data();
        $requests[] = $body;
        $index = count($requests);
        if ($index === 1) {
            expect(array_column($body['tools'] ?? [], 'name'))->toContain('run_child');
        }
        $reply = uncappedDelegationReply($index, $index === 1);

        return Http::response(($body['stream'] ?? false) ? uncappedDelegationSse($reply) : $reply,
            200, ($body['stream'] ?? false) ? ['Content-Type' => 'text/event-stream'] : []);
    });
    $scope = 'uncapped-parent-'.$shape;
    $session = app(PrismHarness::class)->for(Participant::create(['name' => 'Synthetic parent']))->session($scope);
    if ($shape === 'stream') {
        expect(iterator_to_array($session->stream('Delegate the synthetic task.')))->not->toBeEmpty();
    } else {
        expect($session->send('Delegate the synthetic task.')->text())->toBe('synthetic parent answer');
    }
    Http::assertSentCount(3);
    $child = Thread::query()->where('scope', $scope.'::sub::run_child')->firstOrFail();
    expect($child->parent_thread_id)->toBe($session->thread()->getKey())
        ->and($child->storedMessages()->where('type', 'assistant')->firstOrFail()->payload['content'])->toBe('synthetic child answer')
        ->and(json_encode($requests[2], JSON_THROW_ON_ERROR))->toContain('synthetic child answer')
        ->and($session->run()['status'])->toBe('completed');
})->with(['send', 'stream']);

it('gives a public child only two steps after four of six tree steps were spent', function (): void {
    $session = callBudgetSession('uncapped-child');
    $root = RunContext::root('synthetic-root', new RunBudget(6));
    $root->ledger->recordSteps(4);
    $child = $root->forChild(new Subagent('child', 'Synthetic child', 'chat', new RunBudget(4)), 'synthetic-parent', null);
    $sequence = Http::fakeSequence();
    foreach ([1, 2, 3, 4] as $index) {
        $sequence->push(budgetHttpReply($index, $index === 4));
    }

    $session->send('Use only the remaining tree steps.', context: $child);

    expect(['child_http_requests' => Http::recorded()->count(), 'total_tree_steps' => $root->ledger->steps()])
        ->toBe(['child_http_requests' => 2, 'total_tree_steps' => 6]);
});

it('counts the public uncapped parents own steps in its shared ledger', function (string $shape): void {
    $session = callBudgetSession('uncapped-own-'.$shape);
    $context = RunContext::root('synthetic-root-'.$shape, new RunBudget(6));
    $sequence = Http::fakeSequence();
    foreach ([1, 2, 3] as $index) {
        $sequence->push($shape === 'stream' ? budgetSseReply($index, $index === 3) : budgetHttpReply($index, $index === 3),
            200, $shape === 'stream' ? ['Content-Type' => 'text/event-stream'] : []);
    }
    if ($shape === 'stream') {
        iterator_to_array($session->stream('Count the whole run.', context: $context));
    } else {
        $session->send('Count the whole run.', context: $context);
    }

    expect(['provider_calls' => Http::recorded()->count(), 'tree_steps' => $context->ledger->steps()])
        ->toBe(['provider_calls' => 3, 'tree_steps' => 3]);
})->with(['send', 'stream']);

it('counts parent steps before delegation and stops the whole six-step tree', function (): void {
    config()->set('prism-harness.agent.modes.child.tools', ['tick']);
    config()->set('prism-harness.agent.modes.child.max_steps', 4);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_child' => ['description' => 'Run a synthetic child.', 'mode' => 'child', 'max_steps' => 4],
    ]);
    $session = callBudgetSession('uncapped-live-tree');
    $context = RunContext::root('synthetic-live-tree', new RunBudget(6));
    $parentCalls = 0;
    $childCalls = 0;
    $spentAtChild = null;
    $spentAtChildRequest = null;
    Event::listen(RunStarted::class, function (RunStarted $event) use (&$spentAtChild, $context): void {
        if ($event->mode === 'child') {
            $spentAtChild = $context->ledger->steps();
        }
    });
    Http::fake(function (HttpRequest $wire) use (&$parentCalls, &$childCalls, &$spentAtChildRequest, $context) {
        $body = $wire->data();
        if (str_contains(json_encode($body['system'] ?? [], JSON_THROW_ON_ERROR), 'A synthetic child.')) {
            // The request boundary includes the child's own first reservation.
            $spentAtChildRequest ??= $context->ledger->steps();
            $childCalls++;

            return Http::response(budgetHttpReply($childCalls, $childCalls === 4));
        }
        $parentCalls++;

        return Http::response($parentCalls < 4 ? budgetHttpReply($parentCalls)
            : uncappedDelegationReply($parentCalls, $parentCalls === 4));
    });

    $session->send('Delegate after three ordinary steps.', context: $context);

    expect($childCalls)->toBeGreaterThan(0);
    expect(['parent_calls' => $parentCalls, 'child_calls' => $childCalls,
        'steps_at_child_start' => $spentAtChild, 'total_tree_steps' => $context->ledger->steps()])
        ->toBe(['parent_calls' => 4, 'child_calls' => 2, 'steps_at_child_start' => 4, 'total_tree_steps' => 6]);
    expect($spentAtChildRequest)->toBe(5);
});
