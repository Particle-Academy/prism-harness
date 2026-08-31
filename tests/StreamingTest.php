<?php

declare(strict_types=1);

use Prism\Harness\Models\Thread;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Tests\Fixtures\Participant;

it('records the transcript core assembled, not one rebuilt here', function (): void {
    // THE point of this feature. A streamed turn and a sent turn must record the
    // same conversation, because a thread is replayed to a model as context: a
    // message assembled slightly wrong never surfaces as an error, only later as
    // a model that remembers things differently than they happened.
    //
    // Prism's StreamCollector does the assembly, so what lands here are real
    // message objects from the same code the non-streaming path uses — not
    // deltas this package concatenated and hoped were equivalent.
    Prism::fake([TextResponseFake::make()->withText('streamed answer')]);
    $ada = Participant::create(['name' => 'Ada']);

    foreach (harness()->for($ada)->session('chat')->stream('go') as $event) {
        // consume
    }

    $stored = Thread::query()->where('scope', 'chat')->firstOrFail()->storedMessages()->get();

    expect($stored->pluck('type')->all())->toContain('user')
        // The user's own turn is recorded too: a conversation holding only the
        // answer replays as a monologue.
        ->and($stored->first()->payload['content'])->toContain('go')
        // Attributed to the run that wrote it, exactly as send() does.
        ->and($stored->first()->run_id)->toStartWith('run_');
});

it('streams events through untouched', function (): void {
    // A consuming application renders these payloads directly, so the harness
    // must not reshape them on the way past.
    Prism::fake([TextResponseFake::make()->withText('streamed answer')]);
    $ada = Participant::create(['name' => 'Ada']);

    $events = [];
    foreach (harness()->for($ada)->session('chat')->stream('go') as $event) {
        $events[] = $event;
    }

    expect($events)->not->toBeEmpty();
});

it('closes out the run when a consumer abandons the stream', function (): void {
    // The awkward part of streaming through a durable session: the lock and the
    // run are held for the whole iteration, so a disconnected browser must not
    // leave a run open forever. PHP runs a generator's finally on destruction.
    //
    // The collector's callback only fires on StreamEnd, so an abandoned stream
    // contributes no assistant message rather than a half-invented one — a
    // partial turn kept, and honest about how far it got.
    Prism::fake([TextResponseFake::make()->withText('partial answer here')]);
    $ada = Participant::create(['name' => 'Ada']);
    $session = harness()->for($ada)->session('chat');

    $stream = $session->stream('go');
    $stream->current();
    unset($stream);

    $run = harness()->for($ada->fresh())->session('chat')->run();

    expect($run['status'])->not->toBe('running');
});
