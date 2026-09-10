<?php

declare(strict_types=1);

use Prism\Harness\PrismHarness;
use Prism\Harness\Voice\VoiceExchange;
use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\TextResponse as TranscriptResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\Participant;

/*
|--------------------------------------------------------------------------
| A spoken turn
|--------------------------------------------------------------------------
|
| Prism already did both directions; the harness could not hold a spoken
| conversation because Session::send() takes a string. These pin the glue, and
| in particular the two decisions a caller would otherwise have to make for
| themselves and get wrong.
|
*/

function utterance(): Audio
{
    return Audio::fromBase64(base64_encode('not really audio'), 'audio/webm');
}

function voiceSession(string $scope = 'voice')
{
    return app(PrismHarness::class)->for(Participant::create(['name' => 'Ada']))->session($scope);
}

it('transcribes an utterance without spending a model turn', function (): void {
    $fake = Prism::fake([new TranscriptResponse(text: '  what is the budget?  ')]);

    $heard = (new VoiceExchange)->transcribe(utterance());

    // Trimmed, because a provider's leading space becomes a thread message.
    expect($heard)->toBe('what is the budget?');
    $fake->assertCallCount(1);
});

it('speaks a reply', function (): void {
    Prism::fake([new AudioResponse(audio: new GeneratedAudio(base64_encode('spoken'), 'audio/mpeg'))]);

    $audio = (new VoiceExchange)->speak('the budget is sixty words');

    expect($audio->base64)->toBe(base64_encode('spoken'))
        ->and($audio->type)->toBe('audio/mpeg');
});

it('runs a whole spoken turn and records the TRANSCRIPT in the thread', function (): void {
    Prism::fake([
        new TranscriptResponse(text: 'what is the budget?'),
        TextResponseFake::make()->withText('Sixty words.')->withMessages(collect([
            new UserMessage('what is the budget?'),
            new AssistantMessage('Sixty words.'),
        ])),
        new AudioResponse(audio: new GeneratedAudio(base64_encode('spoken'), 'audio/mpeg')),
    ]);

    $session = voiceSession();
    $reply = (new VoiceExchange)->exchange($session, utterance());

    expect($reply->heard)->toBe('what is the budget?')
        ->and($reply->text)->toBe('Sixty words.')
        ->and($reply->audio)->not->toBeNull()
        ->and($reply->empty)->toBeFalse();

    // TEXT, not audio. A transcript is what replays to a model, what a human
    // reads back, and what compaction operates on. Minutes of PCM in the
    // message table would be unreplayable by anything but the original
    // provider.
    $stored = $session->thread()->storedMessages()->pluck('payload')->map(
        fn ($payload): string => is_string($payload) ? $payload : json_encode($payload),
    )->implode(' ');

    expect($stored)->toContain('what is the budget?');
});

it('does NOT spend a turn when it heard nothing', function (): void {
    // Silence, a mis-fired button, a dead microphone. Sending "" would record
    // an empty user message in the thread for ever and bill a turn answering
    // nothing.
    $fake = Prism::fake([new TranscriptResponse(text: '   ')]);

    $session = voiceSession();
    $reply = (new VoiceExchange)->exchange($session, utterance());

    expect($reply->empty)->toBeTrue()
        ->and($reply->heard)->toBe('')
        ->and($reply->audio)->toBeNull()
        ->and(iterator_to_array($session->thread()->messages()))->toBe([]);

    // One call: the transcription. No send, no synthesis.
    $fake->assertCallCount(1);
});

it('does not synthesise silence when a turn produced no prose', function (): void {
    // A tool-only turn legitimately says nothing. Asking a TTS provider to read
    // an empty string is a billed request for a silent file.
    Prism::fake([
        new TranscriptResponse(text: 'run the probe'),
        TextResponseFake::make()->withText(''),
    ]);

    $reply = (new VoiceExchange)->exchange(voiceSession(), utterance());

    expect($reply->heard)->toBe('run the probe')
        ->and($reply->text)->toBe('')
        ->and($reply->audio)->toBeNull()
        ->and($reply->empty)->toBeFalse();
});

it('carries what was HEARD separately from what was answered', function (): void {
    // The two fail differently: a wrong answer to the right transcription is a
    // model problem, a right answer to the wrong transcription is a microphone
    // problem, and only showing both lets anyone tell which.
    Prism::fake([
        new TranscriptResponse(text: 'what is the budget?'),
        TextResponseFake::make()->withText('Sixty words.'),
        new AudioResponse(audio: new GeneratedAudio(base64_encode('spoken'), 'audio/mpeg')),
    ]);

    $array = (new VoiceExchange)->exchange(voiceSession(), utterance())->toArray();

    expect($array['heard'])->toBe('what is the budget?')
        ->and($array['text'])->toBe('Sixty words.')
        ->and($array['audio'])->toBe(base64_encode('spoken'))
        ->and($array['audio_type'])->toBe('audio/mpeg');
});
