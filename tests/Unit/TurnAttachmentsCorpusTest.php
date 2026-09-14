<?php

declare(strict_types=1);

use Prism\Harness\Exceptions\UnacceptableAttachment;
use Prism\Harness\Support\TurnAttachments;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Text;

/**
 * The cross-language turn-attachments corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file proves the corpus has not drifted
 * from the code it records. Without it, both ports would be asserting against a
 * snapshot of admission rules this package had since changed, and stay green
 * while an attachment one language refuses went out from another.
 */
function turnAttachmentsCorpus(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/../Fixtures/harness-turn-attachments.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

/** @param array<string, mixed> $spec */
function corpusAttachment(array $spec): mixed
{
    if ($spec['$'] === 'Text') {
        return new Text($spec['text']);
    }

    if ($spec['$'] === 'String') {
        return $spec['value'];
    }

    $class = $spec['$'] === 'Document' ? Document::class : Image::class;
    $title = $spec['title'] ?? null;

    return match ($spec['from']) {
        'base64' => $class::fromBase64($spec['base64'], $spec['mimeType'] ?? null),
        'url' => $class::fromUrl($spec['url']),
        'urlWithBytes' => new $class($spec['url'], $spec['base64']),
        'localPath' => (function () use ($class, $spec) {
            $file = tempnam(sys_get_temp_dir(), 'att');
            file_put_contents($file, $spec['bytes']);

            try {
                return $class::fromLocalPath($file, $spec['mimeType']);
            } finally {
                @unlink($file);
            }
        })(),
        'fileId' => $class === Document::class ? Document::fromFileId($spec['fileId'], $title) : Image::fromFileId($spec['fileId']),
        'chunks' => Document::fromChunks($spec['chunks'], $title),
        'text' => Document::fromText($spec['text'], $title),
        'nothing' => new $class,
    };
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(turnAttachmentsCorpus()['cases'])->toHaveCount(18);
});

it('gives every row the verdict the corpus records for the reference', function (array $case): void {
    try {
        TurnAttachments::admit($case['prompt'], array_map(corpusAttachment(...), $case['attachments']));
        $verdict = 'admitted';
    } catch (UnacceptableAttachment $refused) {
        $verdict = $refused->code();
    }

    expect($verdict)->toBe($case['verdict']['php']);
})->with(fn (): array => array_map(fn (array $case): array => [$case], turnAttachmentsCorpus()['cases']));

it('agrees with both ports on every row', function (): void {
    foreach (turnAttachmentsCorpus()['cases'] as $case) {
        expect([$case['verdict']['ts'], $case['verdict']['py']])
            ->toBe([$case['verdict']['php'], $case['verdict']['php']], $case['id'])
            ->and($case['agrees'])->toBeTrue();
    }
});
