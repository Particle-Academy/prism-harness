<?php

declare(strict_types=1);

use Prism\Harness\Context\CompactionOutcome;
use Prism\Harness\Context\DiscardEvicted;
use Prism\Harness\Context\KeepRecentTurns;
use Prism\Harness\Context\NoCompaction;
use Prism\Harness\Context\ToolPairGuard;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\ContextRecall;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ContextRecallTool;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Participant;

/*
|--------------------------------------------------------------------------
| Compaction
|--------------------------------------------------------------------------
|
| The harness supplies the structure and the application supplies the rule, so
| what is tested here is the structure: that the default changes nothing, that
| evicted messages reach a sink rather than vanishing, and — the part that is
| not negotiable — that no strategy can produce a transcript the provider will
| refuse.
|
*/

function compactionThread(): Thread
{
    return Thread::forParticipant(Participant::create(['name' => 'Ada']), 'support');
}

function compactionSession(): Session
{
    return app(PrismHarness::class)
        ->for(Participant::create(['name' => 'Ada']))
        ->session('chat');
}

function calls(string $id, string $text = ''): AssistantMessage
{
    return new AssistantMessage($text, [new ToolCall(id: $id, name: 'lookup', arguments: [])]);
}

function answers(string $id): ToolResultMessage
{
    return new ToolResultMessage([new ToolResult($id, 'lookup', [], 'ok')]);
}

it('replays the whole conversation by default, so an upgrade changes nothing', function (): void {
    // A package that started dropping turns on upgrade would change what the
    // model answers with no line in anyone's diff.
    $messages = [new UserMessage('one'), new AssistantMessage('two'), new UserMessage('three')];

    $outcome = (new NoCompaction)->compact($messages);

    expect($outcome->kept)->toBe($messages)
        ->and($outcome->evicted)->toBe([])
        ->and($outcome->compacted())->toBeFalse();
});

it('keeps the most recent messages and evicts the rest', function (): void {
    $messages = array_map(fn (int $i): Message => new UserMessage("m{$i}"), range(1, 10));

    $outcome = (new KeepRecentTurns(3))->compact($messages);

    expect($outcome->kept)->toHaveCount(3)
        ->and($outcome->evicted)->toHaveCount(7)
        ->and($outcome->kept[0]->content)->toBe('m8')
        ->and($outcome->evicted[0]->content)->toBe('m1');
});

it('leaves a conversation shorter than the limit untouched', function (): void {
    $messages = [new UserMessage('one'), new UserMessage('two')];

    expect((new KeepRecentTurns(10))->compact($messages)->compacted())->toBeFalse();
});

it('refuses a keep count that would evict the turn being answered', function (): void {
    // Reads as a config typo rather than an intention, and is refused where the
    // value is rather than as an empty transcript at the provider.
    expect(fn (): mixed => new KeepRecentTurns(0))->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| The invariant no strategy may break
|--------------------------------------------------------------------------
*/

it('evicts a tool result whose call was cut away', function (): void {
    // The cut that produces "tool_use ids were found without tool_result blocks
    // immediately after" — a provider rejection of the WHOLE request, arriving
    // several messages after the compaction that caused it.
    $outcome = new CompactionOutcome(
        kept: [answers('call-1'), new UserMessage('and then?')],
        evicted: [calls('call-1')],
    );

    $guarded = (new ToolPairGuard)->enforce($outcome);

    expect($guarded->kept)->toHaveCount(1)
        ->and($guarded->kept[0])->toBeInstanceOf(UserMessage::class)
        ->and($guarded->evicted)->toHaveCount(2);
});

it('evicts a tool call whose result was cut away', function (): void {
    // The other direction: a question the model asked and can no longer see
    // answered.
    $outcome = new CompactionOutcome(
        kept: [calls('call-1'), new UserMessage('and then?')],
        evicted: [answers('call-1')],
    );

    $guarded = (new ToolPairGuard)->enforce($outcome);

    expect($guarded->kept)->toHaveCount(1)
        ->and($guarded->kept[0])->toBeInstanceOf(UserMessage::class);
});

it('keeps what the assistant SAID when only some of its calls survive', function (): void {
    // What it said is part of the conversation independently of the calls it
    // made, so a partially-orphaned assistant message is trimmed, not dropped.
    $message = new AssistantMessage('Looking those up.', [
        new ToolCall(id: 'call-1', name: 'lookup', arguments: []),
        new ToolCall(id: 'call-2', name: 'lookup', arguments: []),
    ]);

    $outcome = new CompactionOutcome(kept: [$message, answers('call-1')]);

    $guarded = (new ToolPairGuard)->enforce($outcome);

    expect($guarded->kept[0]->content)->toBe('Looking those up.')
        ->and($guarded->kept[0]->toolCalls)->toHaveCount(1)
        ->and($guarded->kept[0]->toolCalls[0]->id)->toBe('call-1');
});

it('leaves a correctly paired transcript alone', function (): void {
    // The guard must be inert on valid input. One that rewrote a correct
    // transcript would be a second source of change nobody asked for.
    $messages = [new UserMessage('go'), calls('call-1'), answers('call-1')];

    $guarded = (new ToolPairGuard)->enforce(CompactionOutcome::untouched($messages));

    expect($guarded->kept)->toBe($messages)
        ->and($guarded->evicted)->toBe([]);
});

it('holds the invariant for a strategy that cuts blindly through a tool exchange', function (): void {
    // The real shape of the bug: a message-counting strategy knows nothing
    // about tool pairing, and the cut lands wherever the count happens to fall.
    // This is why the guard is central and not a note in the contract.
    $messages = [
        new UserMessage('audit everything'),
        calls('call-1'), answers('call-1'),
        calls('call-2'), answers('call-2'),
        calls('call-3'), answers('call-3'),
    ];

    // Keeping 4 cuts between call-2 and its result.
    $guarded = (new ToolPairGuard)->enforce((new KeepRecentTurns(4))->compact($messages));

    $callIds = [];
    $resultIds = [];

    foreach ($guarded->kept as $message) {
        if ($message instanceof AssistantMessage) {
            foreach ($message->toolCalls as $call) {
                $callIds[] = $call->id;
            }
        }

        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $resultIds[] = $result->toolCallId;
            }
        }
    }

    sort($callIds);
    sort($resultIds);

    expect($callIds)->toBe($resultIds);
});

/*
|--------------------------------------------------------------------------
| Eviction reaches a sink
|--------------------------------------------------------------------------
*/

it('hands evicted messages to the sink, which is what separates compaction from loss', function (): void {
    // Captured on the sink itself rather than into a by-reference closure
    // variable: a promoted constructor property cannot bind by reference, so
    // the first version of this test captured nothing and would have passed the
    // moment the assertion was loosened.
    $sink = new class implements EvictionSink
    {
        /** @var list<array{messages: list<mixed>, scope: string}> */
        public array $captured = [];

        public function store(array $messages, string $scope): void
        {
            $this->captured[] = ['messages' => $messages, 'scope' => $scope];
        }
    };

    app()->instance(EvictionSink::class, $sink);

    app()->instance(CompactionStrategy::class, new KeepRecentTurns(1));

    $thread = compactionThread();
    $thread->record([new UserMessage('one'), new UserMessage('two'), new UserMessage('three')]);

    iterator_to_array($thread->messages());

    expect($sink->captured)->toHaveCount(1)
        ->and($sink->captured[0]['messages'])->toHaveCount(2)
        ->and($sink->captured[0]['scope'])->toBe((string) $thread->getKey());
});

it('does not take the turn down when a sink fails', function (): void {
    // A degraded conversation beats no conversation: the model can still answer
    // from what remains in the window.
    // The sink RECORDS that it was reached before throwing. An earlier version
    // asserted only on the window, which is unchanged whether the sink is
    // called or not -- so it passed while a bug meant the sink was never
    // reached at all. A test for "a failing sink is survivable" has to prove
    // the failure happened.
    $sink = new class implements EvictionSink
    {
        public bool $reached = false;

        public function store(array $messages, string $scope): void
        {
            $this->reached = true;

            throw new RuntimeException('the sink is down');
        }
    };

    app()->instance(EvictionSink::class, $sink);

    app()->instance(CompactionStrategy::class, new KeepRecentTurns(1));

    $thread = compactionThread();
    $thread->record([new UserMessage('one'), new UserMessage('two')]);

    expect(iterator_to_array($thread->messages()))->toHaveCount(1)
        ->and($sink->reached)->toBeTrue();
});

it('shortens the view and never the storage', function (): void {
    // The property that makes the decision reversible: change the strategy and
    // the next turn sees a different window over the same unaltered history.
    app()->instance(EvictionSink::class, new DiscardEvicted);
    app()->instance(CompactionStrategy::class, new KeepRecentTurns(1));

    $thread = compactionThread();
    $thread->record([new UserMessage('one'), new UserMessage('two'), new UserMessage('three')]);

    expect(iterator_to_array($thread->messages()))->toHaveCount(1)
        ->and($thread->storedMessages()->count())->toBe(3);

    app()->instance(CompactionStrategy::class, new NoCompaction);

    expect(iterator_to_array($thread->messages()))->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| Recall — the other half of eviction
|--------------------------------------------------------------------------
*/

it('does not offer a recall tool when nothing can answer it', function (): void {
    // The property worth protecting. A recall tool with nothing behind it
    // answers everything with "nothing found", and an agent reads that as THE
    // DETAIL DOES NOT EXIST rather than as I CANNOT CHECK — a stronger and more
    // wrong conclusion than not having the tool at all.
    expect(app()->bound(ContextRecall::class))->toBeFalse()
        ->and(fn (): mixed => app(ToolRegistry::class)->resolve(['recall_context'], null))
        ->not->toThrow(LogicException::class);

    expect(app(ToolRegistry::class)->resolve(['recall_context'], null))->toBe([]);
});

it('reports a broken lookup as unknown rather than as nothing found', function (): void {
    // The distinction decides what the agent does next: told "nothing found" it
    // proceeds as though the detail does not exist; told the lookup broke, it
    // knows its own uncertainty is unresolved.
    $tool = (new ContextRecallTool(new class implements ContextRecall
    {
        public function recall(string $query, string $scope, int $budget): string
        {
            throw new RuntimeException('the index is down');
        }
    }))->forSession(compactionSession());

    expect($tool->handle('anything'))->toContain('unknown');
});

it('says nothing was found rather than erroring on an empty result', function (): void {
    // "I looked and there was nothing" is a useful answer; an exception is not.
    $tool = (new ContextRecallTool(new class implements ContextRecall
    {
        public function recall(string $query, string $scope, int $budget): string
        {
            return '';
        }
    }))->forSession(compactionSession());

    expect($tool->handle('anything'))->toContain('Nothing relevant');
});

it('passes the conversation scope and the budget to the implementation', function (): void {
    // The scope must be the same value the sink was given, or recall searches a
    // different conversation than the one that evicted the messages.
    $seen = new class implements ContextRecall
    {
        public string $scope = '';

        public int $budget = 0;

        public function recall(string $query, string $scope, int $budget): string
        {
            $this->scope = $scope;
            $this->budget = $budget;

            return 'found it';
        }
    };

    $session = compactionSession();
    $tool = (new ContextRecallTool($seen, 250))->forSession($session);

    expect($tool->handle('what was the total?'))->toBe('found it')
        ->and($seen->scope)->toBe($session->key())
        ->and($seen->budget)->toBe(250);
});
