<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Voice\VoiceExchange;
use Prism\Prism\ValueObjects\Media\Audio;
use RuntimeException;

/**
 * Thrown when a spoken turn is handed audio the harness would have to DEREFERENCE.
 *
 * Prism's media objects carry their content several ways. Only some of them are
 * inert. `fromBase64()` and `fromRawContent()` already hold the bytes, and
 * `fromFileId()` is a reference the PROVIDER resolves — nothing local happens.
 * `fromLocalPath()`, `fromStoragePath()` and `fromUrl()` are different: sending
 * one means this process reads a file or makes an outbound request, on behalf
 * of whoever supplied the string.
 *
 * That matters here because the obvious integration and the dangerous one are
 * one method name apart:
 *
 *     $voice->transcribe(Audio::fromBase64($request->string('audio'), 'audio/webm'));  // inert
 *     $voice->transcribe(Audio::fromLocalPath($request->string('path')));              // uploads that file
 *     $voice->transcribe(Audio::fromUrl($request->string('url')));                     // SSRF
 *
 * A microphone in a browser produces base64. A host reaching for one of the
 * others with request-derived input has built something it did not mean to,
 * and nothing in the signature would have made it pause.
 *
 * ## What the refusal stops, stated exactly
 *
 * The two cases are NOT the same, and the difference was measured:
 *
 * - **A URL is stopped outright.** `fromUrl()` is lazy — nothing is fetched
 *   until the request is built — so refusing here means the request is never
 *   made. This is SSRF prevention in the ordinary sense.
 * - **A path is stopped one step LATE.** `fromLocalPath()` and
 *   `fromStoragePath()` read the file inside the constructor, in the host's own
 *   code, before this class is ever called. The read has already happened; it
 *   cannot be prevented from here and this exception does not claim to.
 *
 * What refusing a path stops is what comes next, which is the part that turns a
 * read into a breach: the bytes being uploaded to a third-party transcription
 * provider, and the file's contents being returned to the caller as text. An
 * earlier draft of this class called it "arbitrary file read", which was wrong
 * about the mechanism and would have let an operator think a guard existed
 * where it does not. The guard is worth having and is not that.
 *
 * So the harness REFUSES BY DEFAULT rather than documenting around it. Reading
 * a recording the application itself stored is a legitimate thing to want, so
 * it stays possible behind {@see VoiceExchange::$allowReferencedAudio} — a flag
 * that is an assertion about where the audio came from, and is therefore off
 * until someone makes it.
 *
 * ## Do not use `hasBase64()` for this check
 *
 * The obvious predicate was a trap, and the check here is `isUrl()`/`isFile()`
 * because of it. {@see Audio::hasBase64()} used to delegate to
 * `hasRawContent()` — "can bytes be obtained" rather than "are bytes in hand" —
 * and so returned TRUE for a URL nobody had fetched. A guard written with it
 * admitted every case it was meant to refuse, which is what the first draft of
 * this one did.
 *
 * Reported and fixed upstream (Particle-Academy/prism#40), so a current Prism
 * answers it correctly. The predicate here stays `isUrl()`/`isFile()` anyway:
 * this package supports a range of Prism versions, and a security guard should
 * not silently change meaning with the resolved version of a dependency.
 */
final class UnsafeAudioSource extends RuntimeException implements HasErrorCode
{
    #[\Override]
    public function code(): string
    {
        return 'unsafe_audio_source';
    }

    public static function notInline(string $kind): self
    {
        return new self(
            "A spoken turn was handed audio carried as {$kind}, which the harness will not dereference by "
            ."default.\n\n"
            .'Inline audio — `Audio::fromBase64()`, which is what a browser microphone produces — is inert: the '
            .'bytes are already in hand and nothing reads a file or makes a request to resolve them. A path or a '
            .'URL is not. A URL is fetched when the request is built, so one taken from request input is a '
            .'server-side fetch of whatever it names. A path was already read when the Audio was constructed, so '
            ."refusing it here stops the bytes being uploaded to a provider and read back to you as text.\n\n"
            .'If this audio comes from somewhere you control — a recording your own application wrote — pass '
            .'`allowReferencedAudio: true` when constructing the VoiceExchange. That flag is an assertion about '
            .'the provenance of the audio, so it is off by default.'
        );
    }
}
