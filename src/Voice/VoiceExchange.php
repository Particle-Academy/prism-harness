<?php

declare(strict_types=1);

namespace Prism\Harness\Voice;

use Prism\Harness\Contracts\EvictionSink;
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
}
