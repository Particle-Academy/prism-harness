<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Enums\CancelOutcome;
use Prism\Harness\Events\RunCancelRequested;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Stores\DatabaseSessionStore;
use Prism\Harness\Subagents\ChildBinding;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunLedger;
use Prism\Harness\Subagents\RunTrees;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
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

// A root that resumes after a person approved its call runs under a new run
// id, and it stays in the tree its parked run began in: the subagents it
// delegated before the approval are in that tree, so a cancel of the resumed
// root stops them, and a cancel of one of them stops the resumed root.
function g381ParkedTreeAfterRootResume(): array
{
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search']);
    config()->set('prism-harness.agent.modes.op_runner.requires_approval', ['search']);
    G360::calls([['run_op', ['task' => 'Run the Op']]]);
    G360::calls([['search', ['query' => 'child']]]);
    G360::calls([['search', ['query' => 'root']]]);
    $first = g381Session('Ada')->send('go')->pendingApprovals()[0];
    $firstRun = (string) (g381Session('Ada')->run()['id'] ?? '');
    G360::calls([['search', ['query' => 'root again']]]);
    $second = g381Session('Ada')->approve($first->approvalId)->pendingApprovals()[0];
    $secondRun = (string) (g381Session('Ada')->run()['id'] ?? '');
    expect($secondRun)->not->toBe($firstRun);

    return [$second, $secondRun];
}

it('stops the subagents of a resumed root when the resumed root is cancelled', function (): void {
    [, $secondRun] = g381ParkedTreeAfterRootResume();
    $child = g381Session('Ada', 'chat::sub::run_op');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1);
    $searches = $this->searches;

    expect(g381Session('Ada')->cancel($secondRun))->toBe(CancelOutcome::Ok)
        ->and($child->approvalStatus($pending[0]))->toBe('cancelled')
        ->and(fn () => $child->approve($pending[0]))->toThrow(ApprovalNotPending::class)
        ->and($this->searches)->toBe($searches);
});

it('stops a resumed root when a subagent it delegated before the approval is cancelled', function (): void {
    [$second] = g381ParkedTreeAfterRootResume();
    $child = g381Session('Ada', 'chat::sub::run_op');
    $searches = $this->searches;

    expect($child->cancel((string) ($child->run()['id'] ?? '')))->toBe(CancelOutcome::Ok)
        ->and(g381Session('Ada')->approvalStatus($second->approvalId))->toBe('cancelled')
        ->and(fn () => g381Session('Ada')->approve($second->approvalId))->toThrow(ApprovalNotPending::class)
        ->and($this->searches)->toBe($searches);
});

// A root that resumes into a tree that already has a budget record holds
// what the tree has left and is narrowed to it, like any other turn that
// continues the tree: the tree's allowance holds across the approval pause.
/** @return array{0: Session, 1: list<string>, 2: string} */
function g381ResumedRootDelegatesAgain(int $maxSteps): array
{
    config()->set('prism-harness.agent.modes.chat.max_steps', $maxSteps);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search']);
    config()->set('prism-harness.agent.modes.op_runner.requires_approval', ['search']);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'run_op' => ['description' => 'Run an Op.', 'mode' => 'op_runner', 'max_steps' => 5],
        'scout' => ['description' => 'Scout.', 'mode' => 'deep_runner', 'max_steps' => 3],
    ]);
    G360::calls([['run_op', ['task' => 'Run the Op']]]);
    G360::calls([['search', ['query' => 'child']]]);
    G360::calls([['search', ['query' => 'root']]]);
    $first = g381Session('Ada')->send('go')->pendingApprovals()[0];
    $firstRun = (string) (g381Session('Ada')->run()['id'] ?? '');
    G360::calls([['scout', ['task' => 'Scout']]]);
    G360::text('Scouted.');
    G360::calls([['search', ['query' => 'root again']]]);
    G360::text('Spare.');
    try {
        g381Session('Ada')->approve($first->approvalId);
    } catch (RunNotPermitted) {
    }
    $child = g381Session('Ada', 'chat::sub::run_op');

    return [$child, $child->thread()->pendingApprovalIds(), $firstRun];
}

it('leaves a parked subagent what its tree has left after the root resumed, delegated and parked again', function (): void {
    [$child, $pending] = g381ResumedRootDelegatesAgain(20);
    expect($pending)->toHaveCount(1);
    $searches = $this->searches;
    G360::text('Child done.');
    $child->approve($pending[0]);

    expect($this->searches)->toBe($searches + 1)
        ->and($child->run()['status'])->toBe('completed');
});

it('holds a resumed root to its tree record, so the tree never spends past its allowance', function (): void {
    [$child, $pending, $firstRun] = g381ResumedRootDelegatesAgain(6);
    $record = g381TreeRecord(g381Session('Ada'), $firstRun);
    expect($record)->toBeArray()
        ->and($record['steps'])->toBeLessThanOrEqual($record['max_steps'])
        ->and($record['steps'])->toBe($record['max_steps']);
    $searches = $this->searches;
    $requests = count(Http::recorded());

    // Spent, the tree refuses the parked child before anything is recorded.
    expect(fn () => $child->approve($pending[0]))->toThrow(RunNotPermitted::class)
        ->and($child->thread()->pendingApprovalIds())->toBe($pending)
        ->and($this->searches)->toBe($searches)
        ->and(count(Http::recorded()))->toBe($requests);
});

// A resume the spent tree refuses still begins a run, and the session keeps
// the trees of its newest runs only. The run the thread is parked on is never
// dropped, so retrying the refused resume never escapes the tree.
it('keeps refusing a resume of a spent tree however often it is retried', function (): void {
    g381ResumedRootDelegatesAgain(6);
    $root = g381Session('Ada');
    expect($root->thread()->pausedApprovalIds())->toHaveCount(1);
    $searches = $this->searches;
    $requests = count(Http::recorded());
    $refusals = 0;
    for ($try = 0; $try < 23; $try++) {
        try {
            g381Session('Ada')->send('');
        } catch (RunNotPermitted) {
            $refusals++;
        }
    }

    expect($refusals)->toBe(23)
        ->and($this->searches)->toBe($searches)
        ->and(count(Http::recorded()))->toBe($requests);
});

// A run's tree is kept under a key of its own: starting a run never rewrites
// the session's durable record, where a host keeps capabilities it may revoke
// from another process while the run starts.
it('never rewrites the session durable record when a run starts', function (): void {
    $puts = [];
    $durable = new class(DB::connection(), $puts) extends DatabaseSessionStore
    {
        /** @param list<string> $puts */
        public function __construct(ConnectionInterface $connection, private array &$puts)
        {
            parent::__construct($connection);
        }

        public function put(string $key, array $payload, ?int $ttlSeconds = null): void
        {
            $this->puts[] = $key;
            parent::put($key, $payload, $ttlSeconds);
        }
    };
    $session = new Session(Participant::query()->firstOrCreate(['name' => 'Ada']), 'chat',
        new DatabaseSessionStore(DB::connection()), $durable,
        runtime: app(AgentRuntime::class));
    G360::text('Done.');
    $session->send('go');

    expect(array_filter($puts, fn (string $key): bool => str_ends_with($key, ':durable')))->toBe([])
        ->and(array_filter($puts, fn (string $key): bool => str_ends_with($key, ':run-trees')))->not->toBe([]);
});

// A stream resume of a parked root keeps the parked run's own id: it is held
// to its tree's record all the same, so what it spends is charged there.
it('holds a root resumed on the stream path to its tree record too', function (): void {
    config()->set('prism-harness.agent.modes.chat.max_steps', 6);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search']);
    config()->set('prism-harness.agent.modes.op_runner.requires_approval', ['search']);
    G360::calls([['run_op', ['task' => 'Run the Op']]]);
    G360::calls([['search', ['query' => 'child']]]);
    G360::calls([['search', ['query' => 'root']]]);
    $root = g381Session('Ada')->send('go')->pendingApprovals()[0];
    $rootRun = (string) (g381Session('Ada')->run()['id'] ?? '');
    $child = g381Session('Ada', 'chat::sub::run_op');
    G360::text('Child done.');
    $child->approve($child->thread()->pendingApprovalIds()[0]);
    $before = g381TreeRecord(g381Session('Ada'), $rootRun)['steps'];

    G360::text('Root done.');
    iterator_to_array(g381Session('Ada')->streamDecision([new ToolApprovalResponse($root->approvalId, true)]), false);
    $record = g381TreeRecord(g381Session('Ada'), $rootRun);

    expect($record['steps'])->toBe($before + 1)
        ->and($record['steps'])->toBeLessThanOrEqual($record['max_steps']);
});

// A child's binding, depth and tree are saved under a key of their own, so
// saving them never rewrites the session's durable record, where a host keeps
// capabilities another process may change while the child starts.
it('saves a child binding without rewriting the session durable record', function (): void {
    $puts = [];
    $durable = new class(DB::connection(), $puts) extends DatabaseSessionStore
    {
        /** @param list<string> $puts */
        public function __construct(ConnectionInterface $connection, private array &$puts)
        {
            parent::__construct($connection);
        }

        public function put(string $key, array $payload, ?int $ttlSeconds = null): void
        {
            $this->puts[] = $key;
            parent::put($key, $payload, $ttlSeconds);
        }
    };
    $session = new Session(Participant::query()->firstOrCreate(['name' => 'Ada']), 'chat::sub::run_op',
        new DatabaseSessionStore(DB::connection()), $durable,
        runtime: app(AgentRuntime::class));
    $session->usingCapability('reports', ['on' => true]);
    $puts = [];

    $session->rememberChildBinding(new ChildBinding('openai', 'gpt-4o'), 2, null, ['session' => 'root', 'run' => 'run_root']);

    expect(array_values(array_filter($puts, fn (string $key): bool => str_ends_with($key, ':durable'))))->toBe([])
        ->and($session->childTree())->toBe(['session' => 'root', 'run' => 'run_root'])
        ->and($session->childDepth())->toBe(2)
        ->and($session->capability('reports'))->toBe(['on' => true]);
});
