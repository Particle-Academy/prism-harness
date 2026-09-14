<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Prism\Harness\Exceptions\UnmappableContent;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;

/**
 * Round-trips the content parts hanging off a UserMessage.
 *
 * This mapper writes the concrete class alongside Prism's `Media::toArray()`
 * and reads it back. Before prism v0.120.0 that array recorded where a file
 * lived but not what it WAS — an Image and a Document serialised identically —
 * so the class was the only way to rebuild the right type. v0.120.0 added a
 * `kind`, but the class is still the more precise record (a GeneratedImage is
 * of kind `image` too), and every row written before then has no kind at all.
 *
 * Rows of both ages replay: newer ones carry their bytes and no file path, and
 * older ones carry a path and often no bytes.
 */
final class ContentPartMapper
{
    /**
     * @return array{class: class-string, data: array<string, mixed>}
     */
    public static function toArray(Text|Media $part): array
    {
        return [
            'class' => $part::class,
            'data' => $part->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $part
     */
    public static function fromArray(array $part): Text|Media
    {
        $class = $part['class'] ?? null;
        /** @var array<string, mixed> $data */
        $data = $part['data'] ?? [];

        if ($class === Text::class) {
            return new Text((string) ($data['text'] ?? ''));
        }

        if (! is_string($class) || ! is_subclass_of($class, Media::class)) {
            throw UnmappableContent::unknownPartClass(is_string($class) ? $class : gettype($class));
        }

        return self::media($class, $data);
    }

    /**
     * Rebuild a media part from whichever locator was recorded.
     *
     * Ordered cheapest-first: a file id or URL is a reference the provider
     * resolves, a path is read locally, and base64 is the fallback that
     * actually carries the bytes. A part with none of them cannot be rebuilt,
     * and says so rather than returning an empty attachment.
     *
     * @param  class-string<Media>  $class
     * @param  array<string, mixed>  $data
     */
    private static function media(string $class, array $data): Media
    {
        if (is_a($class, Document::class, true)) {
            return self::document($class, $data);
        }

        $mimeType = isset($data['mime_type']) ? (string) $data['mime_type'] : null;

        $media = match (true) {
            filled($data['file_id'] ?? null) => $class::fromFileId((string) $data['file_id']),
            filled($data['url'] ?? null) => $class::fromUrl((string) $data['url'], $mimeType),
            // Rows written before prism v0.120.0 carry a path and no bytes, so a
            // path is still honoured. From v0.120.0 Prism stores the bytes and
            // no path, and such rows arrive at the base64 arm instead.
            //
            // Throws if the file is absent from the disk, so a thread written
            // against a different disk fails loudly instead of silently
            // resolving to the wrong file.
            filled($data['storage_path'] ?? null) => $class::fromStoragePath((string) $data['storage_path']),
            filled($data['local_path'] ?? null) => $class::fromLocalPath((string) $data['local_path'], $mimeType),
            filled($data['base64'] ?? null) => $class::fromBase64((string) $data['base64'], $mimeType),
            default => throw UnmappableContent::noMediaLocator($class),
        };

        return self::withFilename($media, $data);
    }

    /**
     * A document, rebuilt through Document's OWN factories.
     *
     * Not through the generic arm above, because Document's factories put the
     * TITLE where Media's put the mime type: `Document::fromUrl($url, $title)`.
     * Rebuilding with `$class::fromUrl($url, $mimeType)` named every stored url
     * document after its mime type and lost its real title, and a chunked
     * document — no id, url, path or bytes — could not be rebuilt at all.
     *
     * @param  class-string<Document>  $class
     * @param  array<string, mixed>  $data
     */
    private static function document(string $class, array $data): Document
    {
        $mimeType = isset($data['mime_type']) ? (string) $data['mime_type'] : null;
        $title = isset($data['document_title']) ? (string) $data['document_title'] : null;

        $document = match (true) {
            filled($data['file_id'] ?? null) => $class::fromFileId((string) $data['file_id'], $title),
            filled($data['url'] ?? null) => $class::fromUrl((string) $data['url'], $title),
            filled($data['storage_path'] ?? null) => $class::fromStoragePath((string) $data['storage_path'], null, $title),
            filled($data['local_path'] ?? null) => $class::fromLocalPath((string) $data['local_path'], $title),
            filled($data['base64'] ?? null) => $class::fromBase64((string) $data['base64'], $mimeType, $title),
            is_array($data['chunks'] ?? null) => $class::fromChunks(array_values(array_map(strval(...), $data['chunks'])), $title),
            default => throw UnmappableContent::noMediaLocator($class),
        };

        return self::withFilename($document, $data);
    }

    /**
     * @template T of Media
     *
     * @param  T  $media
     * @param  array<string, mixed>  $data
     * @return T
     */
    private static function withFilename(Media $media, array $data): Media
    {
        if (filled($data['filename'] ?? null)) {
            $media->as((string) $data['filename']);
        }

        return $media;
    }
}
