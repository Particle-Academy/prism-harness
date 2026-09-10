<?php

declare(strict_types=1);

namespace Prism\Harness\Voice;

use Prism\Prism\ValueObjects\GeneratedAudio;

/**
 * What came back from a spoken turn.
 *
 * `heard` is carried separately from `text` because the two fail differently
 * and a caller needs to tell them apart. A wrong answer to the right
 * transcription is a model problem; a right answer to the wrong transcription
 * is a microphone problem, and showing the user what was heard is the only way
 * they can tell which one just happened.
 *
 * `audio` is nullable on purpose — a turn can legitimately produce no prose to
 * speak, and `empty` says the utterance itself carried nothing rather than the
 * answer doing so. Collapsing those into one null would make silence and a
 * tool-only turn indistinguishable.
 */
final readonly class VoiceReply
{
    public function __construct(
        /** What the transcriber heard. Show it — it is how a user spots a misheard turn. */
        public string $heard,
        /** The agent's answer as text. Empty on a turn that only called tools. */
        public string $text,
        /** The answer as speech, or null when there was nothing to say. */
        public ?GeneratedAudio $audio,
        /** True when nothing was heard at all: silence, a mis-fired button, a dead microphone. */
        public bool $empty,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'heard' => $this->heard,
            'text' => $this->text,
            // Base64 rather than the object, because this is what crosses a
            // wire to a browser that is going to play it.
            'audio' => $this->audio?->base64,
            'audio_type' => $this->audio?->type,
            'empty' => $this->empty,
        ];
    }
}
