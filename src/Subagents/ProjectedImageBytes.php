<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Prism\Providers\Anthropic\Anthropic;
use Prism\Prism\Providers\OpenAI\OpenAI;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/** @internal Inline image data is covered by the caller's imageTokenBound. */
final class ProjectedImageBytes
{
    /** @param array<string, mixed> $payload */
    public static function excluded(array $payload, TextRequest|StructuredRequest $request, Provider $provider): int
    {
        // Unknown projectors keep the complete byte bound. Never search arbitrary
        // keys or text for a value that happens to look like image data.
        if (! $provider instanceof Anthropic && ! $provider instanceof OpenAI) {
            return 0;
        }
        $images = [];
        foreach ($request->messages() as $message) {
            if (! $message instanceof UserMessage) {
                continue;
            }
            foreach ($message->additionalContent as $part) {
                if ($part instanceof Image && ! $part->isUrl() && ! $part->isFileId()) {
                    $data = $part->base64();
                    $mime = $part->mimeType();
                    if ($data !== null && $mime !== null) {
                        $images[] = ['data' => $data, 'mime' => $mime];
                    }
                }
            }
        }
        $field = $provider instanceof OpenAI && $provider->apiFormat !== 'chat_completions' ? 'input' : 'messages';
        $messages = $payload[$field] ?? null;
        if ($images === [] || ! is_array($messages)) {
            return 0;
        }

        $next = 0;
        $removed = 0;
        $bytes = 0;
        foreach ($messages as $message) {
            if (! is_array($message) || ($message['role'] ?? null) !== 'user' || ! is_array($message['content'] ?? null)) {
                continue;
            }
            foreach ($message['content'] as $block) {
                if (! is_array($block)) {
                    continue;
                }
                for ($index = $next; $index < count($images); $index++) {
                    if (self::matches($block, $images[$index], $provider)) {
                        // Use encoded bytes: base64 slashes are escaped by the
                        // same JSON encoding used for the full payload. Quotes,
                        // the data-URI prefix and every other field stay counted.
                        $bytes += strlen(json_encode($images[$index]['data'], JSON_THROW_ON_ERROR)) - 2;
                        $next = $index + 1;
                        $removed++;
                        break;
                    }
                }
            }
        }
        if ($removed > count($images)) {
            throw RunNotPermitted::callBudget('budget_input_unbounded');
        }

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array{data: string, mime: string}  $image
     */
    private static function matches(array $block, array $image, Anthropic|OpenAI $provider): bool
    {
        if ($provider instanceof Anthropic) {
            $source = $block['source'] ?? null;

            return ($block['type'] ?? null) === 'image' && is_array($source)
                && ($source['type'] ?? null) === 'base64'
                && ($source['media_type'] ?? null) === $image['mime']
                && ($source['data'] ?? null) === $image['data'];
        }
        if ($provider->apiFormat === 'chat_completions') {
            $url = $block['image_url'] ?? null;

            return ($block['type'] ?? null) === 'image_url' && is_array($url)
                && ($url['url'] ?? null) === 'data:'.$image['mime'].';base64,'.$image['data'];
        }

        return ($block['type'] ?? null) === 'input_image'
            && ($block['image_url'] ?? null) === 'data:'.$image['mime'].';base64,'.$image['data'];
    }
}
