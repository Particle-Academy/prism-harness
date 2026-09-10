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
 *     $voice->transcribe(Audio::fromLocalPath($request->string('path')));              // file read
 *     $voice->transcribe(Audio::fromUrl($request->string('url')));                     // SSRF
 *
 * A microphone in a browser produces base64. A host reaching for one of the
 * others with request-derived input has built arbitrary file read or a
 * server-side fetch, and nothing in the signature would have made them pause.
 *
 * So the harness REFUSES BY DEFAULT rather than documenting around it. Reading
 * a recording the application itself stored is a legitimate thing to want, so
 * it stays possible behind {@see VoiceExchange::$allowReferencedAudio} — a flag
 * that is an assertion about where the audio came from, and is therefore off
 * until someone makes it.
 *
 * ## Do not use `hasBase64()` for this check
 *
 * The obvious predicate is a trap. {@see Audio::hasBase64()} delegates to
 * `hasRawContent()`, which returns TRUE for a URL that has never been fetched
 * and for a path that has never been read — it answers "can bytes be obtained",
 * not "are bytes already in hand". A guard written with it admits every case it
 * was meant to refuse. The check is `isUrl()` and `isFile()`, asked in the
 * negative, and this paragraph exists because the first draft of that guard got
 * it wrong.
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
            ."URL is not. One built from request input is arbitrary file read or a server-side fetch.\n\n"
            .'If this audio comes from somewhere you control — a recording your own application wrote — pass '
            .'`allowReferencedAudio: true` when constructing the VoiceExchange. That flag is an assertion about '
            .'the provenance of the audio, so it is off by default.'
        );
    }
}
