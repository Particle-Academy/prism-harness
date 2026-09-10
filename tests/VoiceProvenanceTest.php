<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\UnsafeAudioSource;
use Prism\Harness\PrismHarness;
use Prism\Harness\Voice\VoiceExchange;
use Prism\Prism\Audio\TextResponse as TranscriptResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Audio;
use Tests\Fixtures\Participant;

/*
|--------------------------------------------------------------------------
| Where the audio came from, and where it ends up
|--------------------------------------------------------------------------
|
| Two findings from the v0.8.0 pre-publish audit, filed as #11 and #12.
|
| The first has a fix: a referenced Audio is refused, because the safe
| construction and the arbitrary-file-read one are one method name apart.
|
| The second does not, and the tests below are how that claim stays honest.
| They MEASURE where a failed call leaves the recording rather than asserting
| the class is careful, so a Prism that starts carrying the payload deeper
| fails here instead of passing quietly.
|
*/

/**
 * Can `$needle` be reached from `$value` by walking arrays and object
 * properties — which is what an error reporter does to a stack trace?
 *
 * Reflection, not `json_encode`, because a trace holds resources and closures
 * that stop a serialiser dead, and because the interesting properties are
 * protected. Flare and Sentry both walk frame arguments this way.
 */
function reachesFrom(mixed $value, string $needle, int $depth = 0): bool
{
    if ($depth > 8) {
        return false;
    }

    if (is_string($value)) {
        return str_contains($value, $needle);
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            if (reachesFrom($item, $needle, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    if (! is_object($value)) {
        return false;
    }

    foreach ((new ReflectionObject($value))->getProperties() as $property) {
        if ($property->isStatic() || ! $property->isInitialized($value)) {
            continue;
        }

        if (reachesFrom($property->getValue($value), $needle, $depth + 1)) {
            return true;
        }
    }

    return false;
}

it('refuses audio it would have to fetch', function (): void {
    // SSRF, in one method name. The harness never sees the URL — Prism fetches
    // it when the request is built — so this is the last place it can be
    // stopped.
    expect(fn (): string => (new VoiceExchange)->transcribe(Audio::fromUrl('http://169.254.169.254/latest/meta-data/')))
        ->toThrow(UnsafeAudioSource::class);
});

it('refuses audio it would have to read off disk', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'voice').'.wav';
    file_put_contents($path, 'not really audio');

    try {
        expect(fn (): string => (new VoiceExchange)->transcribe(Audio::fromLocalPath($path, 'audio/wav')))
            ->toThrow(UnsafeAudioSource::class);
    } finally {
        @unlink($path);
    }
});

it('carries a code so a caller can branch without reading English', function (): void {
    // Decision 0004: the sentence is outside the contract, the code is in it.
    try {
        (new VoiceExchange)->transcribe(Audio::fromUrl('https://example.test/a.wav'));
        throw new RuntimeException('expected a refusal');
    } catch (UnsafeAudioSource $refusal) {
        expect($refusal->code())->toBe('unsafe_audio_source');
    }
});

it('does NOT use hasBase64() to decide, because that admits a URL', function (): void {
    // The trap this guard was written around, pinned so nobody "simplifies"
    // the predicate back to the obvious one. `hasBase64()` answers "can bytes
    // be obtained", not "are bytes in hand".
    expect(Audio::fromUrl('https://example.test/a.wav')->hasBase64())->toBeTrue();
});

it('accepts inline audio, which is what a microphone produces', function (): void {
    $fake = Prism::fake([new TranscriptResponse(text: 'hello')]);

    $heard = (new VoiceExchange)->transcribe(Audio::fromBase64(base64_encode('bytes'), 'audio/webm'));

    expect($heard)->toBe('hello');
    $fake->assertCallCount(1);
});

it('lets a caller who owns the recording opt back in', function (): void {
    // Transcribing a file the application itself wrote is legitimate. The flag
    // is the assertion that this is that case — which only the caller can make.
    $path = tempnam(sys_get_temp_dir(), 'voice').'.wav';
    file_put_contents($path, 'not really audio');
    $fake = Prism::fake([new TranscriptResponse(text: 'from disk')]);

    try {
        $heard = (new VoiceExchange(allowReferencedAudio: true))
            ->transcribe(Audio::fromLocalPath($path, 'audio/wav'));

        expect($heard)->toBe('from disk');
        $fake->assertCallCount(1);
    } finally {
        @unlink($path);
    }
});

it('leaves the recording only in the harness OWN frames when a provider fails', function (): void {
    // #12. The claim in VoiceExchange's docblock is that this exposure cannot
    // be closed inside the package, because the frames holding the payload are
    // this class's own entry points — and a method taking an Audio has the
    // Audio in its arguments, whatever it does with it afterwards. Both halves
    // of that are measured here rather than asserted.
    $restore = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    if (ini_get('zend.exception_ignore_args') !== '0') {
        throw new RuntimeException(
            'This test needs frame arguments captured to see anything at all, and this PHP would not let '
            .'them be turned on at runtime. It is not reporting that the exposure is gone.'
        );
    }

    config()->set('prism.providers.openai.api_key', 'sk-not-a-real-key');
    Http::fake(['*' => Http::response('{"error":{"message":"nope"}}', 500)]);

    $marker = 'MARKERBYTESMARKERBYTES';
    $audio = Audio::fromBase64(base64_encode($marker.str_repeat('x', 64)), 'audio/webm');

    try {
        (new VoiceExchange)->transcribe($audio);
        throw new RuntimeException('expected the provider failure to propagate');
    } catch (Throwable $failure) {
        $reaching = [];

        foreach ($failure->getTrace() as $frame) {
            if (reachesFrom($frame['args'] ?? [], $marker)) {
                $reaching[] = ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'];
            }
        }

        // POSITIVE CONTROL. If this is empty the scanner is broken or the ini
        // did not take, and every assertion below would pass vacuously.
        expect($reaching)->not->toBeEmpty();

        // The whole finding. Nothing BELOW this class holds it: speech-to-text
        // attaches a stream rather than a base64 string, so Prism's frames are
        // clean and a tidier rethrow — which would be constructed inside the
        // frame below — buys exactly nothing.
        expect($reaching)->toBe([VoiceExchange::class.'->transcribe']);
    } finally {
        ini_set('zend.exception_ignore_args', $restore === false ? '1' : $restore);
    }
});

it('does not gain a frame that is not the harness own when a whole turn fails', function (): void {
    // The same measurement down the exchange() path, which adds a frame of its
    // own — so the claim is "our frames", not "one frame".
    $restore = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    config()->set('prism.providers.openai.api_key', 'sk-not-a-real-key');
    Http::fake(['*' => Http::response('{"error":{"message":"nope"}}', 500)]);

    $marker = 'MARKERBYTESMARKERBYTES';
    $session = app(PrismHarness::class)->for(Participant::create(['name' => 'Ada']))->session('voice-trace');

    try {
        (new VoiceExchange)->exchange(
            $session,
            Audio::fromBase64(base64_encode($marker.str_repeat('x', 64)), 'audio/webm'),
        );
        throw new RuntimeException('expected the provider failure to propagate');
    } catch (Throwable $failure) {
        $reaching = [];

        foreach ($failure->getTrace() as $frame) {
            if (reachesFrom($frame['args'] ?? [], $marker)) {
                $reaching[] = ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'];
            }
        }

        expect($reaching)->not->toBeEmpty()
            ->and($reaching)->toBe([
                VoiceExchange::class.'->transcribe',
                VoiceExchange::class.'->exchange',
            ]);
    } finally {
        ini_set('zend.exception_ignore_args', $restore === false ? '1' : $restore);
    }
});
