<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Prism\Contracts\DescribesProjectedPayload;
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
        return self::analyse($payload, $request, $provider)['excludedBytes'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{excludedBytes: int, diagnostics: list<CallBudgetDiagnostic>}
     */
    public static function analyse(array $payload, TextRequest|StructuredRequest $request, Provider $provider): array
    {
        $inlineImages = [];
        foreach ($request->messages() as $message) {
            if (! $message instanceof UserMessage) {
                continue;
            }
            foreach ($message->additionalContent as $part) {
                if ($part instanceof Image && ! $part->isUrl() && ! $part->isFileId()) {
                    $inlineImages[] = $part;
                }
            }
        }
        if ($inlineImages === []) {
            return ['excludedBytes' => 0, 'diagnostics' => []];
        }
        $format = self::format($provider);
        if ($format === null) {
            return ['excludedBytes' => 0, 'diagnostics' => [CallBudgetDiagnostic::ProjectedImageFormatUnknown]];
        }
        $images = [];
        foreach ($inlineImages as $image) {
            $data = $image->base64();
            $mime = $image->mimeType();
            if ($data !== null && $mime !== null) {
                $images[] = ['data' => $data, 'mime' => $mime];
            }
        }
        if ($images === []) {
            return ['excludedBytes' => 0, 'diagnostics' => []];
        }
        // The format only locates candidate blocks. Every discounted value must
        // still match a charged inline image at an exact semantic user position.
        $field = $format === 'openai-responses' ? 'input' : 'messages';
        $messages = $payload[$field] ?? null;
        if (! is_array($messages)) {
            return ['excludedBytes' => 0, 'diagnostics' => []];
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
                    if (self::matches($block, $images[$index], $format)) {
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

        return ['excludedBytes' => $bytes, 'diagnostics' => []];
    }

    private static function format(Provider $provider): ?string
    {
        // Optional across core ^0.143 || ^0.144: instanceof neither autoloads
        // nor errors when this interface is absent. RequireChecker allows this
        // exact optional symbol; the lowest-dependency tests prove the fallback.
        if ($provider instanceof DescribesProjectedPayload) {
            $format = $provider->projectedPayloadFormat();

            // An explicit unknown/null declaration must never inherit a class
            // guess. Older core providers without this contract keep the legacy path.
            return in_array($format, ['anthropic-messages', 'openai-responses', 'openai-chat-completions'], true) ? $format : null;
        }
        if ($provider instanceof Anthropic) {
            return 'anthropic-messages';
        }
        if ($provider instanceof OpenAI) {
            return $provider->apiFormat === 'chat_completions' ? 'openai-chat-completions' : 'openai-responses';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array{data: string, mime: string}  $image
     */
    private static function matches(array $block, array $image, string $format): bool
    {
        if ($format === 'anthropic-messages') {
            $source = $block['source'] ?? null;

            return ($block['type'] ?? null) === 'image' && is_array($source)
                && ($source['type'] ?? null) === 'base64'
                && ($source['media_type'] ?? null) === $image['mime']
                && ($source['data'] ?? null) === $image['data'];
        }
        if ($format === 'openai-chat-completions') {
            $url = $block['image_url'] ?? null;

            return ($block['type'] ?? null) === 'image_url' && is_array($url)
                && ($url['url'] ?? null) === 'data:'.$image['mime'].';base64,'.$image['data'];
        }

        return ($block['type'] ?? null) === 'input_image'
            && ($block['image_url'] ?? null) === 'data:'.$image['mime'].';base64,'.$image['data'];
    }
}
