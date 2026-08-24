<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Prism\Harness\Exceptions\UnmappableContent;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Media\Text;

/**
 * Round-trips the content parts hanging off a UserMessage.
 *
 * Prism's own `Media::toArray()` records where a file lives but not what it
 * IS — an Image and a Document serialise to byte-identical arrays. Rehydrating
 * from that alone would quietly turn every attachment into whichever class we
 * guessed, so this mapper writes the concrete class alongside the data and
 * reads it back. That is also why the stored shape is ours rather than Prism's:
 * `toArray()` exists to feed telemetry and debug output, and is free to change
 * for presentational reasons. Persistence cannot be, so it does not ride on it.
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
        $mimeType = isset($data['mime_type']) ? (string) $data['mime_type'] : null;

        return match (true) {
            filled($data['file_id'] ?? null) => $class::fromFileId((string) $data['file_id']),
            filled($data['url'] ?? null) => $class::fromUrl((string) $data['url'], $mimeType),
            // Throws if the file is absent from the disk, so a thread written
            // against a different disk fails loudly instead of silently
            // resolving to the wrong file.
            filled($data['storage_path'] ?? null) => $class::fromStoragePath((string) $data['storage_path']),
            filled($data['local_path'] ?? null) => $class::fromLocalPath((string) $data['local_path'], $mimeType),
            filled($data['base64'] ?? null) => $class::fromBase64((string) $data['base64'], $mimeType),
            default => throw UnmappableContent::noMediaLocator($class),
        };
    }
}
