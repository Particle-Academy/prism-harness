<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Harness\Enums\CancelOutcome;
use Prism\Harness\Events\RunCancelRequested;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunLedger;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;
use Tests\Support\G360;

// G-381: which run tree a run belongs to is decided once, when the run
// begins, from facts this runtime holds: the live parent run the runner
// started the child from, or the references the session saved for a turn the
// runner did not start. Nothing a host states (a root run id, a parent run
// id, a ledger) can put a run into somebody else's tree. Every test drives
// the public session API over Prism's real OpenAI handler on faked HTTP, with
// stray requests refused.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', []);
    config()->set('prism-harness.agent.modes.op_runner', [
        'system_prompt' => 'You run one Op.', 'tools' => ['search'], 'skills' => [], 'max_steps' => 6,
        'subagents' => ['dig' => ['description' => 'Dig.', 'mode' => 'deep_runner', 'max_steps' => 3]],
    ]);
    config()->set('prism-harness.agent.modes.deep_runner', [
        'system_prompt' => 'You dig.', 'tools' => ['search'], 'skills' => [], 'max_steps' => 4,
    ]);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_op' => ['description' => 'Run an Op.', 'mode' => 'op_runner', 'max_steps' => 5],
    ]);

    /** @var list<Closure(): void> */
    $this->during = [];
    $during = &$this->during;
    $this->searches = 0;
    $searches = &$this->searches;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$during, &$searches): string {
                $searches++;
                $next = array_shift($during);
                if ($next !== null) {
                    $next();
                }

                return 'Found it';
            }),
    ]);
    $this->cancels = [];
    $cancels = &$this->cancels;
    Event::listen(RunCancelRequested::class, function (RunCancelRequested $event) use (&$cancels): void {
        $cancels[] = $event->sessionKey;
    });
});

function g381Session(string $name, string $scope = 'chat'): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => $name]))->session($scope);
}

/** @return array{session: string, run: string} */
function g381TreeOf(Session $root, string $runId): array
{
    return ['session' => $root->key(), 'run' => $runId];
}

function g381TreeRecord(Session $root, string $runId): mixed
{
    return $root->planStore()->get($root->key().':run-tree:'.$runId);
}

// A host context naming the run id of somebody else's live run, as its root
// run id, must not put the runs under it into that run's tree: not the tree
// a child saves, not the budget it spends, not the tree a cancel marks.
it('keeps a host context that names another person\'s live run out of that run\'s tree', function (): void {
    $ada = g381Session('Ada');
    $adaRun = null;
    $this->during[] = function () use (&$adaRun): void {
        $adaRun = g381Session('Ada')->run()['id'] ?? null;
        expect($adaRun)->toBeString();
        // Bob's turn, under a context the host built with Ada's run id.
        $forged = new RunContext(RunLedger::start($adaRun), new RunBudget(20), parentRunId: $adaRun);
        g381Session('Bob')->send('go', null, [], $forged);
    };
    $this->during[] = function (): void {
        // Inside Bob's child: cancel it from another process.
        $child = g381Session('Bob', 'chat::sub::run_op');
        expect($child->cancel((string) ($child->run()['id'] ?? '')))->toBe(CancelOutcome::Ok);
    };
    G360::calls([['search', ['query' => 'ada']]]);
    G360::calls([['run_op', ['task' => 'Bob task']]]);
    G360::calls([['search', ['query' => 'bob']]]);
    G360::text('Done.');
    G360::text('Done.');
    G360::text('Done.');
    $ada->send('go');

    $bobChild = g381Session('Bob', 'chat::sub::run_op');
    expect($adaRun)->toBeString()
        ->and($this->searches)->toBe(2)
        ->and($bobChild->childTree())->toBeNull()
        ->and(g381TreeRecord($ada, $adaRun))->toBeNull()
        ->and(app(RunTrees::class)->treeCancelled($ada, g381TreeOf($ada, $adaRun)))->toBeFalse()
        ->and(g381Session('Ada')->run()['status'])->toBe('completed');
});

// A direct call to the runner names a parent run id: it is only a run of this
// runtime's tree when the context it is handed is that run's own.
it('keeps a child the runner is handed a forged parent run id for out of that run\'s tree', function (): void {
    $ada = g381Session('Ada');
    $adaChildRun = null;
    $this->during[] = function () use (&$adaChildRun): void {
        $adaChildRun = g381Session('Ada', 'chat::sub::run_op')->run()['id'] ?? null;
        expect($adaChildRun)->toBeString();
        G360::text('Bob child done.');
        app(SubagentRunner::class)->run(new Subagent('run_op', 'Run an Op.', 'op_runner', new RunBudget(3)),
            g381Session('Bob'), RunContext::root('host_root', new RunBudget(20)), $adaChildRun, 'Bob task');
    };
    G360::calls([['run_op', ['task' => 'Ada task']]]);
    G360::calls([['search', ['query' => 'ada']]]);
    G360::text('Ada child done.');
    G360::text('Ada done.');
    $ada->send('go');

    $adaTree = g381Session('Ada', 'chat::sub::run_op')->childTree();
    expect($adaChildRun)->toBeString()
        ->and($adaTree)->not->toBeNull()
        ->and(g381Session('Bob', 'chat::sub::run_op')->childTree())->toBeNull();
});

// A child of a child belongs to the root's tree, two levels down.
it('gives a child of a child the root\'s tree and depth two', function (): void {
    $root = g381Session('Ada');
    G360::calls([['run_op', ['task' => 'Run the Op']]]);
    G360::calls([['dig', ['task' => 'Dig']]]);
    G360::text('Dug.');
    G360::text('Op done.');
    G360::text('Root done.');
    $root->send('go');
    $rootRun = (string) (g381Session('Ada')->run()['id'] ?? '');
    $grandchild = g381Session('Ada', 'chat::sub::run_op::sub::dig');

    expect($grandchild->childTree())->toBe(g381TreeOf($root, $rootRun))
        ->and(g381Session('Ada', 'chat::sub::run_op')->childTree())->toBe(g381TreeOf($root, $rootRun))
        ->and($grandchild->childDepth())->toBe(2)
        ->and(g381Session('Ada', 'chat::sub::run_op')->childDepth())->toBe(1);
});

// A child the runner starts again runs at the depth the runner starts it at,
// never at a deeper one it was started at before: the saved depth is for turns
// the runner did not start.
it('runs a child the runner starts again at the runner\'s depth, not an older deeper one', function (): void {
    // First under a host context two levels down: the child is at depth 3.
    G360::calls([['run_op', ['task' => 'Deep']]]);
    G360::text('Op done.');
    G360::text('Root done.');
    g381Session('Ada')->send('go', null, [], new RunContext(RunLedger::start('host_deep'), new RunBudget(20), depth: 2));
    expect(g381Session('Ada', 'chat::sub::run_op')->childDepth())->toBe(3);

    // Then from a plain root: the child is at depth 1 and may delegate.
    G360::calls([['run_op', ['task' => 'Shallow']]]);
    G360::calls([['dig', ['task' => 'Dig']]]);
    G360::text('Dug.');
    G360::text('Op done.');
    G360::text('Root done.');
    g381Session('Ada')->send('again');
    $grandchild = g381Session('Ada', 'chat::sub::run_op::sub::dig');

    expect(g381Session('Ada', 'chat::sub::run_op')->childDepth())->toBe(1)
        ->and($grandchild->childDepth())->toBe(2)
        ->and(count(iterator_to_array($grandchild->thread()->messages(), false)))->toBeGreaterThan(0);
});

// A host context on a child resumed outside the runner may put it deeper,
// never shallower, than the depth the runner started it at.
it('holds a host context on a child to at least the depth it was started at', function (): void {
    config()->set('prism-harness.agent.modes.op_runner.requires_approval', ['search']);
    G360::calls([['run_op', ['task' => 'Deep']]]);
    G360::calls([['search', ['query' => 'x']]]);
    G360::text('Root done.');
    // The child is started three deep, one below the limit.
    g381Session('Ada')->send('go', null, [], new RunContext(RunLedger::start('host_deep'), new RunBudget(20), depth: RunContext::MAX_DEPTH - 2));
    $child = g381Session('Ada', 'chat::sub::run_op');
    expect($child->childDepth())->toBe(RunContext::MAX_DEPTH - 1);
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1);

    // The host answers with a context at depth 0; a delegation is still refused.
    G360::calls([['dig', ['task' => 'Dig']]]);
    G360::text('Child done.');
    $child->approve($pending[0], true, null, RunContext::root('host_answer', new RunBudget(20)));

    expect(count(iterator_to_array(g381Session('Ada', 'chat::sub::run_op::sub::dig')->thread()->messages(), false)))->toBe(0)
        ->and(G360::threadText())->toContain('maximum depth');
})->skip(fn (): bool => ! method_exists(G360::class, 'threadText'));

// A cancel of a child marks the tree it is in, which reaches a root parked on
// an approval of its own; a host context's run on another session is never in it.
it('cancels the parked root of a child\'s tree, and nothing in a host tree beside it', function (): void {
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search']);
    config()->set('prism-harness.agent.modes.op_runner.requires_approval', ['search']);
    G360::calls([['run_op', ['task' => 'Run the Op']]]);
    G360::calls([['search', ['query' => 'child']]]);
    G360::calls([['search', ['query' => 'root']]]);
    $root = g381Session('Ada');
    $rootApproval = $root->send('go')->pendingApprovals()[0];
    // Bob, under a host context, delegates too.
    G360::calls([['run_op', ['task' => 'Bob']]]);
    G360::calls([['search', ['query' => 'bob child']]]);
    G360::text('Bob done.');
    g381Session('Bob')->send('go', null, [], RunContext::root((string) (g381Session('Ada')->run()['id'] ?? ''), new RunBudget(20)));

    $child = g381Session('Ada', 'chat::sub::run_op');
    expect($child->cancel((string) ($child->run()['id'] ?? '')))->toBe(CancelOutcome::Ok);
    $bobChild = g381Session('Bob', 'chat::sub::run_op');

    expect(g381Session('Ada')->approvalStatus($rootApproval->approvalId))->toBe('cancelled')
        ->and($bobChild->approvalStatus($bobChild->thread()->pendingApprovalIds()[0]))->toBe('pending');
});
