<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use InvalidArgumentException;
use Prism\Harness\Contracts\HasErrorCode;

/**
 * A turn was offered an attachment it will not send.
 *
 * Refused, never dropped. Every rule below is one where the quiet alternative
 * was available and worse: an attachment that silently goes missing produces an
 * answer to a question nobody asked, with nothing on screen to say so.
 *
 * The TypeScript and Python harness ports raise the same four codes, and
 * prism-parity's `harness-turn-attachments` corpus pins which attachment gets
 * which in all three languages.
 */
final class UnacceptableAttachment extends InvalidArgumentException implements HasErrorCode
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function notMedia(int $index, string $type): self
    {
        return new self(
            "Attachment [{$index}] is a [{$type}], not an Image, Document, Audio or Video. A turn's "
            .'text belongs in the prompt; attachments are the media sent alongside it.',
            'attachment_not_media',
        );
    }

    /**
     * Built from a URL, or from a local or storage path.
     *
     * A URL is refused because sending it hands a provider an address to fetch,
     * and a URL that came from request input is somebody else's choice of
     * address. A path is refused because `fromLocalPath()` and
     * `fromStoragePath()` read the file in the caller's own code, before this is
     * called. That read cannot be undone from here. What refusing stops is the
     * part that turns a read into a leak: the file's contents going to a
     * third-party model, and coming back in its answer.
     *
     * For something the application itself trusts, build the media from its
     * bytes (`fromBase64()`, `fromRawContent()`) or a provider file id.
     */
    public static function byReference(int $index, string $what): self
    {
        return new self(
            "Attachment [{$index}] was built from {$what}. A turn sends media as its bytes or as a provider "
            .'file id, never as a URL or path for someone else to resolve. Read the content yourself and '
            .'attach it with fromBase64() or fromRawContent().',
            'attachment_by_reference',
        );
    }

    public static function empty(int $index): self
    {
        return new self(
            "Attachment [{$index}] carries nothing to send: no bytes, no file id and no chunks. A provider "
            .'would receive an empty part, which fails far from the code that built it.',
            'attachment_empty',
        );
    }

    /**
     * Attachments with an empty prompt.
     *
     * An empty prompt is how a run resumes after a tool approval, and on that
     * path Prism adds no user message at all. Attachments offered with one
     * would vanish with the message they were meant to ride on.
     */
    public static function withoutPrompt(): self
    {
        return new self(
            'Attachments need a prompt to travel with. An empty prompt resumes a paused run and sends no user '
            .'message, so these attachments would be dropped without a word.',
            'attachment_without_prompt',
        );
    }

    #[\Override]
    public function code(): string
    {
        return $this->errorCode;
    }
}
