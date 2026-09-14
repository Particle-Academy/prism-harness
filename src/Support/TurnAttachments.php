<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Prism\Harness\Exceptions\UnacceptableAttachment;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Video;

/**
 * Which media a turn will carry, decided before a run starts.
 *
 * Asked before the run begins, so a refused attachment costs no run row, no
 * events and no budget: it is a mistake in the call, not a failed run.
 */
final class TurnAttachments
{
    /**
     * @param  array<array-key, mixed>  $attachments
     * @return list<Image|Document|Audio|Video>
     *
     * @throws UnacceptableAttachment
     */
    public static function admit(string $prompt, array $attachments): array
    {
        if ($attachments === []) {
            return [];
        }

        if ($prompt === '') {
            throw UnacceptableAttachment::withoutPrompt();
        }

        $admitted = [];

        foreach (array_values($attachments) as $index => $attachment) {
            if (! $attachment instanceof Image && ! $attachment instanceof Document
                && ! $attachment instanceof Audio && ! $attachment instanceof Video) {
                throw UnacceptableAttachment::notMedia($index, get_debug_type($attachment));
            }

            // isUrl()/isFile(), NOT hasBase64() or hasRawContent(). A media that
            // was fetched after being built from a URL holds bytes AND a URL, and
            // providers that accept URLs send the URL. The question is where the
            // media came from, not whether bytes happen to be in hand.
            if ($attachment->isUrl()) {
                throw UnacceptableAttachment::byReference($index, 'a URL');
            }

            if ($attachment->isFile()) {
                throw UnacceptableAttachment::byReference($index, 'a file path');
            }

            if (! self::carriesContent($attachment)) {
                throw UnacceptableAttachment::empty($index);
            }

            $admitted[] = $attachment;
        }

        return $admitted;
    }

    /**
     * Bytes, a provider file id, or chunks.
     *
     * The bytes are read with `rawContent()` rather than asked of
     * `hasRawContent()`, which answers true for `fromBase64('')`, and an empty
     * string is not an attachment. Neither call makes a request: URL and path
     * media were refused before this, and since prism v0.120.0 reading media
     * never fetches.
     */
    private static function carriesContent(Media $attachment): bool
    {
        // isFileId() answers true for fromFileId(''), which names no file.
        if ($attachment->isFileId() && $attachment->fileId() !== '') {
            return true;
        }

        $bytes = $attachment->rawContent();

        if ($bytes !== null && $bytes !== '') {
            return true;
        }

        // Non-empty: `fromChunks([])` answers isChunks() and carries nothing.
        return $attachment instanceof Document && $attachment->isChunks() && $attachment->chunks() !== [];
    }
}
