<?php

declare(strict_types=1);

namespace Prism\Harness\Voice;

use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\Exceptions\UnsafeAudioSource;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Media\Audio;

/**
 * One spoken turn: hear it, answer it, say the answer back.
 *
 * ## What was missing, and what was not
 *
 * **Prism already does both directions.** `Prism::audio()->withInput($audio)
 * ->asText()` transcribes and `->withVoice(...)->asAudio()` synthesises, across
 * OpenAI, ElevenLabs, Gemini, Groq, Mistral and Replicate. Nothing needed
 * adding there, and nothing was.
 *
 * What the harness could not do was hold a spoken conversation, because
 * {@see Session::send()} takes a string. So every application wanting voice had
 * to write the same glue — transcribe, send, synthesise — and each one invented
 * its own answer to the question below.
 *
 * ## The thread stores TEXT, and that is the design
 *
 * A transcript is what replays to a model, what a human reads back, and what
 * compaction and recall operate on. Storing audio in the thread would make the
 * conversation unreplayable by anything that is not the original provider, and
 * would put minutes of PCM in a table designed for messages.
 *
 * So the audio is transcribed and the TEXT is recorded, exactly as if it had
 * been typed.
 *
 * **Known limitation: the thread does not record that a turn was spoken.**
 * `Thread::record()` takes messages and a run id, with no per-message
 * provenance, so a transcription is indistinguishable from something typed once
 * it is stored. That matters — a misheard word reads as a user who said
 * something odd — and the honest fix is a provenance field on the message
 * rather than something bolted on here. Until then, {@see VoiceReply::$heard}
 * carries it for the length of the turn and a caller that needs it durably must
 * store it themselves.
 *
 * **Keep the audio if you need it.** The harness does not, and cannot decide
 * for you whether a recording is evidence or a privacy liability. If it is
 * evidence, store it where you store evidence — the same division of labour as
 * an {@see EvictionSink}.
 *
 * ## The caller owns the audio's PROVENANCE, and the harness checks it
 *
 * `Audio` can be built from inline bytes, from a path on disk, or from a URL,
 * and the three are one method name apart. A host that reaches for the second
 * or third with request-derived input has built arbitrary file read or a
 * server-side fetch. So a referenced source is REFUSED here rather than
 * documented around — see {@see UnsafeAudioSource}, and
 * {@see self::$allowReferencedAudio} for the opt-in.
 *
 * ## Known exposure: a failed turn puts the recording in a stack trace
 *
 * When a provider call fails, the exception's trace holds this class's own
 * frames, and their argument is the `Audio` — which by then is holding the
 * decoded bytes as well as the base64 it was given. Under
 * `zend.exception_ignore_args=0` PHP records frame arguments, so **an error
 * reporter that walks them can put a voice recording in a log**: biometric PII,
 * somewhere redaction is not looking, on the path that fires when something is
 * already going wrong.
 *
 * **This cannot be fixed here, and that was measured rather than assumed.**
 * Prism's own frames are clean — speech-to-text attaches a stream, not a
 * base64 string, and nothing below this class holds the payload. The frames
 * that do are this class's own, because a method taking an `Audio` has the
 * `Audio` in its arguments whatever it does with it next. Catching the error
 * to rethrow something tidier does not help either — the replacement is
 * constructed inside that same frame and inherits the same arguments. All of
 * that is pinned by a test, so a future Prism that starts carrying the payload
 * deeper fails the suite rather than passing quietly.
 *
 * What DOES fix it is outside the package, and an operator can do either:
 *
 * - `zend.exception_ignore_args=1` in php.ini — strips frame arguments
 *   entirely. This is what `php.ini-production` ships; a PHP with no ini file
 *   at all has it OFF, so "we did not change it" is not the safe answer.
 * - Scrub `Prism\Prism\ValueObjects\Media\Audio` in the error reporter.
 *   Flare and Sentry both walk frame arguments by reflection, which reaches the
 *   protected `rawContent` as well as the public `base64`.
 *
 * ## Turn-based, not a live duplex stream
 *
 * This is press-to-talk: one utterance in, one answer out. A continuously open
 * bidirectional stream with interruption and barge-in is a different product
 * built on a provider's realtime socket, and pretending this is that would be
 * the more expensive mistake — a caller would discover the difference from
 * latency rather than from the type.
 */
final readonly class VoiceExchange
{
    public function __construct(
        private string $transcribeModel = 'whisper-1',
        private string $speakModel = 'tts-1',
        private string $voice = 'alloy',
        private Provider $transcribeProvider = Provider::OpenAI,
        private Provider $speakProvider = Provider::OpenAI,
        /**
         * Permit audio the harness must dereference — a path, a disk, a URL.
         *
         * An assertion about where the audio came from, which only the caller
         * can make. Off by default because the unsafe construction looks
         * exactly like the safe one at the call site.
         */
        private bool $allowReferencedAudio = false,
    ) {}

    /**
     * Hear an utterance. Returns what was said.
     *
     * Separate from {@see self::exchange()} because transcription alone is a
     * legitimate use — a dictation box, a caption track, a confirm-before-send
     * flow. Charging a model turn for it because the API only offered a round
     * trip would be a bad seam.
     */
    public function transcribe(Audio $utterance): string
    {
        $this->refuseReferencedAudio($utterance);

        return trim(Prism::audio()
            ->using($this->transcribeProvider, $this->transcribeModel)
            ->withInput($utterance)
            ->asText()
            ->text);
    }

    /**
     * Say something. Returns the audio.
     */
    public function speak(string $text): GeneratedAudio
    {
        return Prism::audio()
            ->using($this->speakProvider, $this->speakModel)
            ->withInput($text)
            ->withVoice($this->voice)
            ->asAudio()
            ->audio;
    }

    /**
     * A whole spoken turn against a durable session.
     *
     * @param  list<string>|null  $toolNames  As {@see Session::send()} — a spoken
     *                                        turn is an ordinary turn, and gets
     *                                        the same tools as a typed one.
     */
    public function exchange(Session $session, Audio $utterance, ?array $toolNames = null): VoiceReply
    {
        $heard = $this->transcribe($utterance);

        // AN EMPTY TRANSCRIPT IS NOT A TURN. Silence, a mis-fired button, a
        // microphone that captured nothing — sending "" to the model would
        // record an empty user message in the thread for ever and spend a turn
        // answering nothing. Reported so the caller can say "I didn't catch
        // that" rather than guessing why the agent replied strangely.
        if ($heard === '') {
            return new VoiceReply(heard: '', text: '', audio: null, empty: true);
        }

        $response = $session->send($heard, $toolNames);
        $text = trim($response->text());

        return new VoiceReply(
            heard: $heard,
            text: $text,
            // Synthesised only when there is something to say. A tool-only turn
            // can legitimately produce no prose, and asking a TTS provider to
            // read an empty string is a billed request for a silent file.
            audio: $text === '' ? null : $this->speak($text),
            empty: false,
        );
    }

    /**
     * Refuse audio that would have to be fetched or read to be sent.
     *
     * Asked HERE rather than at the edge, because the edge is the host
     * application and this is the last place that can see the value object
     * before it becomes a provider request.
     *
     * The predicate is deliberately `isUrl()`/`isFile()` and not the
     * obvious-looking `hasBase64()`, which returns true for a URL nobody has
     * fetched. {@see UnsafeAudioSource} has the detail; the short version is
     * that the tempting check admits everything it was written to refuse.
     */
    private function refuseReferencedAudio(Audio $utterance): void
    {
        if ($this->allowReferencedAudio) {
            return;
        }

        if ($utterance->isUrl()) {
            throw UnsafeAudioSource::notInline('a URL');
        }

        // Covers `fromLocalPath()` and `fromStoragePath()` alike. A disk is not
        // safer than a path for being configured — an S3 disk makes the read an
        // outbound request, which is the thing being refused.
        if ($utterance->isFile()) {
            throw UnsafeAudioSource::notInline('a file path');
        }
    }
}
