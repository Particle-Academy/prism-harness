<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;
use Tests\Support\G360;

// A root that delegated and then parked on its own approval at its LAST step
// has nothing left to spend, in its own budget or in its tree's record. Acting
// on the person's decision takes no model step, so it cannot overspend: the
// decision is accepted and acted on (the approved call runs once and its
// result is recorded), no model step follows, and the run stops on its step
// budget with the tree record within its limit. The same rule as the ports.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 3);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search']);
    config()->set('prism-harness.agent.modes.helper', ['system_prompt' => 'Help.', 'tools' => [], 'skills' => [], 'max_steps' => 2]);
    config()->set('prism-harness.agent.modes.chat.subagents', ['run_op' => ['description' => 'Run an Op.', 'mode' => 'helper', 'max_steps' => 2]]);
    $this->searches = [];
    $searches = &$this->searches;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$searches): string {
                $searches[] = $query;

                return 'Found it';
            }),
    ]);
});

function spentTreeRoot(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return array{steps: int, max_steps: int}|null */
function spentTreeRecord(string $runId): ?array
{
    $root = spentTreeRoot();

    return $root->planStore()->get($root->key().':run-tree:'.$runId);
}

function resumeSpentTree(string $route, string $approvalId): ?RunNotPermitted
{
    try {
        match ($route) {
            'approve' => spentTreeRoot()->approve($approvalId),
            'decide' => spentTreeRoot()->decide([new ToolApprovalResponse($approvalId, true)]),
            'streamDecision' => iterator_to_array(spentTreeRoot()->streamDecision([new ToolApprovalResponse($approvalId, true)]), false),
        };
    } catch (RunNotPermitted $refused) {
        return $refused;
    }

    return null;
}

function storedSearchResults(string $callId): int
{
    return spentTreeRoot()->thread()->storedMessages()->where('type', 'tool_result')->get()
        ->flatMap(fn ($row): array => $row->payload['tool_results'] ?? [])
        ->where('tool_call_id', $callId)->count();
}

it('acts on a decision a root parked on after delegating, with its tree spent', function (string $route): void {
    G360::calls([['run_op', ['task' => 'Run the Op']]]);
    G360::text('Child done.');
    G360::calls([['search', ['query' => 'root']]]);
    $pending = spentTreeRoot()->send('go')->pendingApprovals()[0];
    $runId = (string) (spentTreeRoot()->run()['id'] ?? '');
    $record = spentTreeRecord($runId);
    expect($record['steps'])->toBe($record['max_steps']);
    $requests = count(Http::recorded());

    $refused = resumeSpentTree($route, $pending->approvalId);

    $after = spentTreeRecord($runId);
    expect($this->searches)->toBe(['root'])
        ->and(storedSearchResults($pending->toolCallId))->toBe(1)
        ->and(count(Http::recorded()))->toBe($requests)
        ->and($refused)->toBeInstanceOf(RunNotPermitted::class)
        ->and($after['steps'])->toBeLessThanOrEqual($after['max_steps'])
        ->and(spentTreeRoot()->thread()->pendingApprovalIds())->toBe([]);

    // Nothing is left to act on: a later resume runs nothing again.
    try {
        spentTreeRoot()->send('');
    } catch (RunNotPermitted) {
    }
    expect($this->searches)->toBe(['root'])->and(count(Http::recorded()))->toBe($requests);
})->with(['approve', 'decide', 'streamDecision']);

it('acts on a decision a resumed root parked on at its last step after delegating again', function (string $route): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 4);
    G360::calls([['search', ['query' => 'first']]]);
    $first = spentTreeRoot()->send('go')->pendingApprovals()[0];
    $firstRun = (string) (spentTreeRoot()->run()['id'] ?? '');
    G360::calls([['run_op', ['task' => 'Run the Op']]]);
    G360::text('Child done.');
    G360::calls([['search', ['query' => 'second']]]);
    $second = spentTreeRoot()->approve($first->approvalId)->pendingApprovals()[0];
    // The resumed root stays in the tree its parked run began, and its
    // delegation opened that tree's record.
    $runId = spentTreeRecord($firstRun) !== null ? $firstRun : (string) (spentTreeRoot()->run()['id'] ?? '');
    $record = spentTreeRecord($runId);
    expect($this->searches)->toBe(['first'])
        ->and($record['steps'])->toBe($record['max_steps']);
    $requests = count(Http::recorded());

    $refused = resumeSpentTree($route, $second->approvalId);

    $after = spentTreeRecord($runId);
    expect($this->searches)->toBe(['first', 'second'])
        ->and(storedSearchResults($second->toolCallId))->toBe(1)
        ->and(count(Http::recorded()))->toBe($requests)
        ->and($refused)->toBeInstanceOf(RunNotPermitted::class)
        ->and($after['steps'])->toBeLessThanOrEqual($after['max_steps']);
})->with(['approve', 'decide', 'streamDecision']);

// A child continuing a spent tree acts on its decision too (G-387): acting
// takes no model step, so the spent tree grants it 0 steps, not a refusal.
// The approved call runs once, nothing asks the model, and the tree stays
// within its allowance. (Before G-387 the child's decision was refused here.)
it('acts on a parked child decision with no model step when its tree is spent', function (): void {
    config()->set('prism-harness.agent.modes.helper.tools', ['search']);
    config()->set('prism-harness.agent.modes.helper.requires_approval', ['search']);
    G360::calls([['run_op', ['task' => 'Run the Op']]]);
    G360::calls([['search', ['query' => 'child']]]);
    G360::calls([['search', ['query' => 'root']]]);
    spentTreeRoot()->send('go');
    $runId = (string) (spentTreeRoot()->run()['id'] ?? '');
    $record = spentTreeRecord($runId);
    $child = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat::sub::run_op');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1)->and($record['steps'])->toBe($record['max_steps']);

    $requests = count(Http::recorded());

    expect(fn () => $child->approve($pending[0]))->toThrow(RunNotPermitted::class, 'step budget exhausted')
        ->and($child->thread()->pendingApprovalIds())->toBe([])
        ->and($this->searches)->toBe(['child'])
        ->and(count(Http::recorded()))->toBe($requests)
        ->and(spentTreeRecord($runId)['steps'])->toBe($record['max_steps']);
});
