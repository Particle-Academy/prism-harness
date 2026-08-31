<?php

declare(strict_types=1);

use Prism\Harness\Models\Thread;
use Prism\Harness\Streaming\StreamRecorder;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\Participant;

it('rebuilds a turn from the events a stream emitted', function (): void {
    $recorder = new StreamRecorder;
    $recorder->observe(new TextDeltaEvent('e1', 1, 'Hello ', 'm1'));
    $recorder->observe(new TextDeltaEvent('e2', 2, 'Ada', 'm1'));

    $messages = $recorder->messages('Say hello');

    expect($recorder->text())->toBe('Hello Ada')
        // Both halves of the exchange. Recording only the answer leaves a
        // conversation that replays as a monologue.
        ->and($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[1]->content)->toBe('Hello Ada');
});

it('keeps a tool-only turn rather than dropping it', function (): void {
    // A turn with no text is still a turn: dropping it would leave any tool
    // results answering nothing.
    $recorder = new StreamRecorder;
    $recorder->observe(new StreamEndEvent('e', 1, FinishReason::Stop));

    expect($recorder->messages('go'))->toHaveCount(1);
});

it('streams events through untouched and records the turn when it ends', function (): void {
    // Prism's fake replays a text response as a stream, which is enough to
    // prove the harness yields events unchanged and records afterwards.
    Prism::fake([TextResponseFake::make()->withText('streamed answer')]);
    $ada = Participant::create(['name' => 'Ada']);

    $events = [];
    foreach (harness()->for($ada)->session('chat')->stream('go') as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();

    $thread = Thread::query()->where('scope', 'chat')->firstOrFail();

    expect($thread->storedMessages()->count())->toBeGreaterThan(0)
        // Recorded against the run that produced it, exactly as send() does.
        ->and($thread->storedMessages()->first()->run_id)->toStartWith('run_');
});

it('closes out the run when a consumer abandons the stream', function (): void {
    // The awkward part of streaming through a durable session: the lock and the
    // run are held for the whole iteration, so a disconnected browser must not
    // leave a run open forever. PHP runs a generator's finally on destruction.
    Prism::fake([TextResponseFake::make()->withText('partial answer here')]);
    $ada = Participant::create(['name' => 'Ada']);
    $session = harness()->for($ada)->session('chat');

    $stream = $session->stream('go');
    $stream->current();      // start it, consume nothing more
    unset($stream);          // consumer walks away

    $run = harness()->for($ada->fresh())->session('chat')->run();

    expect($run['status'])->not->toBe('running');
});
