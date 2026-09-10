<?php

declare(strict_types=1);

use Prism\Harness\Exceptions\UnmappableContent;
use Prism\Harness\Models\Thread;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Contracts\Thread as ThreadContract;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Participant;

function participant(string $name = 'Ada'): Participant
{
    return Participant::create(['name' => $name]);
}

it('satisfies the Prism thread contract', function (): void {
    expect(new Thread)->toBeInstanceOf(ThreadContract::class);
});

it('records and replays a conversation in order', function (): void {
    $thread = Thread::forParticipant(participant(), 'support');

    $thread->record([
        new SystemMessage('You are terse.'),
        new UserMessage('What is the capital of France?'),
        new AssistantMessage('Paris.'),
    ]);

    $messages = iterator_to_array($thread->messages(), false);

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(SystemMessage::class)
        ->and($messages[0]->content)->toBe('You are terse.')
        ->and($messages[1])->toBeInstanceOf(UserMessage::class)
        ->and($messages[1]->text())->toBe('What is the capital of France?')
        ->and($messages[2])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[2]->content)->toBe('Paris.');
});

it('does not duplicate user text across save and load cycles', function (): void {
    // UserMessage's constructor appends Text($content) to additionalContent.
    // Storing that copy and passing it back would append a second one, and
    // text() concatenates parts — so text would double on every round trip.
    $thread = Thread::forParticipant(participant(), 'support');
    $thread->record([new UserMessage('Hello')]);

    $once = iterator_to_array($thread->messages(), false)[0];
    expect($once->text())->toBe('Hello')
        ->and($once->additionalContent)->toHaveCount(1);

    // Re-record what we read back: the shape must survive a second cycle too,
    // which is where a compounding bug would show.
    $second = Thread::forParticipant(participant('Grace'), 'support');
    $second->record([$once]);

    $twice = iterator_to_array($second->messages(), false)[0];
    expect($twice->text())->toBe('Hello')
        ->and($twice->additionalContent)->toHaveCount(1);
});

it('survives a tool call and its result, so a run can resume mid loop', function (): void {
    $thread = Thread::forParticipant(participant(), 'coding');

    $thread->record([
        new UserMessage('What is the weather in Paris?'),
        new AssistantMessage('', [new ToolCall('call_1', 'weather', ['city' => 'Paris'])]),
        new ToolResultMessage([
            new ToolResult('call_1', 'weather', ['city' => 'Paris'], 'Sunny, 24C'),
        ]),
    ]);

    $messages = iterator_to_array($thread->messages(), false);

    expect($messages)->toHaveCount(3)
        ->and($messages[1]->toolCalls[0]->id)->toBe('call_1')
        ->and($messages[1]->toolCalls[0]->name)->toBe('weather')
        ->and($messages[1]->toolCalls[0]->arguments)->toBe(['city' => 'Paris'])
        ->and($messages[2])->toBeInstanceOf(ToolResultMessage::class)
        ->and($messages[2]->toolResults[0]->toolCallId)->toBe('call_1')
        ->and($messages[2]->toolResults[0]->result)->toBe('Sunny, 24C')
        ->and($messages[2]->toolResults[0]->args)->toBe(['city' => 'Paris']);
});

it('appends rather than replacing when recording twice', function (): void {
    $thread = Thread::forParticipant(participant(), 'support');

    $thread->record([new UserMessage('First.')]);
    $thread->record([new AssistantMessage('Second.')]);

    $messages = iterator_to_array($thread->messages(), false);

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->text())->toBe('First.')
        ->and($messages[1]->content)->toBe('Second.');
});

it('is addressed by participant and scope together', function (): void {
    $ada = participant('Ada');

    $support = Thread::forParticipant($ada, 'support');
    $coding = Thread::forParticipant($ada, 'coding');

    // Same participant, different scope: two conversations, not one.
    expect($support->id)->not->toBe($coding->id);

    $support->record([new UserMessage('Billing question.')]);
    $coding->record([new UserMessage('Refactor this.')]);

    expect(iterator_to_array($support->messages(), false))->toHaveCount(1)
        ->and(iterator_to_array($coding->messages(), false)[0]->text())->toBe('Refactor this.');
});

it('resolves the same thread for the same address', function (): void {
    $ada = participant('Ada');

    $first = Thread::forParticipant($ada, 'support');
    $first->record([new UserMessage('Hello')]);

    // A fresh worker resolving the same address must land on the same
    // conversation rather than starting a new one.
    $resolved = Thread::forParticipant($ada->fresh(), 'support');

    expect($resolved->id)->toBe($first->id)
        ->and(iterator_to_array($resolved->messages(), false))->toHaveCount(1);
});

it('keeps one participant out of another participant thread', function (): void {
    $ada = participant('Ada');
    $grace = participant('Grace');

    Thread::forParticipant($ada, 'support')->record([new UserMessage('Ada speaking.')]);
    $graceThread = Thread::forParticipant($grace, 'support');

    expect(iterator_to_array($graceThread->messages(), false))->toBeEmpty()
        ->and(Thread::query()->addressedTo($ada)->count())->toBe(1)
        ->and(Thread::query()->addressedTo($ada, 'coding')->count())->toBe(0);
});

it('feeds a Prism request, composing history with a new prompt', function (): void {
    $thread = Thread::forParticipant(participant(), 'support');
    $thread->record([
        new UserMessage('What is the capital of France?'),
        new AssistantMessage('Paris.'),
    ]);

    $request = (new PendingRequest)
        ->using(Provider::OpenAI, 'gpt-4')
        ->withThread($thread)
        ->withPrompt('And its population?')
        ->toRequest();

    expect($request->messages())->toHaveCount(3)
        ->and($request->messages()[2]->text())->toBe('And its population?');
});

it('reads history lazily', function (): void {
    $thread = Thread::forParticipant(participant(), 'support');
    $thread->record([new UserMessage('One.'), new UserMessage('Two.')]);

    // A Generator, so a long history pages out of the database rather than
    // hydrating every row before Prism sees the first one.
    expect($thread->messages())->toBeInstanceOf(Generator::class);
});

it('deletes its messages with the thread', function (): void {
    $thread = Thread::forParticipant(participant(), 'support');
    $thread->record([new UserMessage('Hello')]);

    $thread->delete();

    expect(DB::table('harness_thread_messages')->count())->toBe(0);
});

it('writes a whole turn or none of it', function (): void {
    // Issue #2. `record()` was a read-modify-write with no transaction, so a
    // multi-step exchange could half-write: a tool call stored with its result
    // missing replays to the model as an unanswered question rather than as an
    // error anyone can see.
    //
    // The RACE this fixes cannot be reproduced here — the suite runs SQLite
    // in-memory, where `lockForUpdate` is a no-op and there is no second
    // connection to contend with. The guarantee lives in the database. What is
    // testable is the atomicity, which is the half that stops a partial turn.
    $thread = Thread::forParticipant(
        Participant::create(['name' => 'Ada']),
        'chat',
    );
    $thread->record([new UserMessage('first')]);

    $unmappable = new class implements Message {};

    try {
        $thread->record([
            new UserMessage('second'),
            $unmappable,
        ]);
        $this->fail('Expected the unmappable message to be refused.');
    } catch (UnmappableContent) {
        // expected
    }

    // The good message from the failed turn must not survive on its own.
    expect($thread->fresh()->storedMessages()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Starting a new conversation without losing the old one
|--------------------------------------------------------------------------
|
| A thread is addressed by participant and scope, so a scope held exactly ONE
| conversation for ever, and any application offering "new chat" had to mint a
| scope per conversation — defeating the addressing that lets a restarted worker
| find the same session again.
|
| `retire()` is the seam. These pin the part that must never regress: the old
| conversation is still there afterwards.
|
*/

it('hands back the same thread until one is retired', function (): void {
    $ada = participant();

    expect(Thread::forParticipant($ada, 'support')->getKey())
        ->toBe(Thread::forParticipant($ada, 'support')->getKey());
});

it('starts a fresh thread at the same address after retirement', function (): void {
    $ada = participant();

    $first = Thread::forParticipant($ada, 'support');
    $first->record([new UserMessage('the old conversation')]);
    $first->retire();

    $second = Thread::forParticipant($ada, 'support');

    expect($second->getKey())->not->toBe($first->getKey())
        ->and($second->scope)->toBe('support')
        ->and(iterator_to_array($second->messages()))->toBe([]);
});

it('KEEPS every message of the retired conversation', function (): void {
    // The whole reason this is a retirement and not a delete. "Clear my
    // context" and "erase my history" are different requests, and only one of
    // them is recoverable.
    $ada = participant();

    $first = Thread::forParticipant($ada, 'support');
    $first->record([new UserMessage('remember this')]);
    $first->retire();

    Thread::forParticipant($ada, 'support')->record([new UserMessage('a new start')]);

    $reloaded = Thread::find($first->getKey());

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->isRetired())->toBeTrue()
        ->and($reloaded->storedMessages()->count())->toBe(1);
});

it('does not retire the conversations of other scopes or other people', function (): void {
    $ada = participant('Ada');
    $grace = participant('Grace');

    $adaSupport = Thread::forParticipant($ada, 'support');
    $adaCoding = Thread::forParticipant($ada, 'coding');
    $graceSupport = Thread::forParticipant($grace, 'support');

    $adaSupport->retire();

    expect(Thread::forParticipant($ada, 'coding')->getKey())->toBe($adaCoding->getKey())
        ->and(Thread::forParticipant($grace, 'support')->getKey())->toBe($graceSupport->getKey())
        ->and(Thread::forParticipant($ada, 'support')->getKey())->not->toBe($adaSupport->getKey());
});

it('keeps the original timestamp when retired twice', function (): void {
    // The column answers "when did this conversation end", not "when was the
    // button last pressed".
    $thread = Thread::forParticipant(participant(), 'support');

    $thread->retire();
    $first = $thread->retired_at;

    $thread->retire();

    expect($thread->retired_at->equalTo($first))->toBeTrue();
});
