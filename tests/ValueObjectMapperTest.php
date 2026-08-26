<?php

declare(strict_types=1);

use Prism\Harness\Exceptions\UnmappableContent;
use Prism\Harness\Support\ValueObjectMapper;
use Prism\Prism\Providers\Gemini\ValueObjects\MessagePartWithSearchGroundings;
use Prism\Prism\ValueObjects\MessagePartWithCitations;

it('round-trips a core value object', function (): void {
    $encoded = ValueObjectMapper::encode(['part' => new MessagePartWithCitations(outputText: 'hello')]);

    expect(ValueObjectMapper::decode($encoded)['part'])
        ->toBeInstanceOf(MessagePartWithCitations::class)
        ->outputText->toBe('hello');
});

it('round-trips a value object belonging to a provider', function (): void {
    // Six providers ship value objects of their own. Refusing them meant the
    // harness could not store a Gemini response that used search grounding —
    // a provider Prism supports and the harness did not, while claiming to be
    // provider-agnostic.
    $encoded = ValueObjectMapper::encode([
        'groundings' => new MessagePartWithSearchGroundings(text: 'hello', startIndex: 0, endIndex: 5),
    ]);

    expect(ValueObjectMapper::decode($encoded)['groundings'])
        ->toBeInstanceOf(MessagePartWithSearchGroundings::class)
        ->text->toBe('hello')
        ->endIndex->toBe(5);
});

it('still refuses the provider class itself', function (): void {
    // The reason the blanket exclusion existed, and it has not changed: a
    // provider takes a client and an API key, and nothing should be able to
    // name one of those from a stored row.
    expect(fn (): mixed => ValueObjectMapper::decode([
        '__prism_class' => 'Prism\Prism\Providers\Gemini\Gemini',
        '__prism_props' => [],
    ]))->toThrow(UnmappableContent::class);
});

it('still refuses a provider handler', function (): void {
    expect(fn (): mixed => ValueObjectMapper::decode([
        '__prism_class' => 'Prism\Prism\Providers\Gemini\Handlers\Text',
        '__prism_props' => [],
    ]))->toThrow(UnmappableContent::class);
});

it('refuses a ValueObjects segment nested deeper than a provider', function (): void {
    // The rule is exactly <Name>\ValueObjects\<Class>. A looser one would let
    // any nested ValueObjects under Providers qualify, which is a wider door
    // than this needs to open.
    expect(fn (): mixed => ValueObjectMapper::decode([
        '__prism_class' => 'Prism\Prism\Providers\Gemini\Deep\Nested\ValueObjects\Thing',
        '__prism_props' => [],
    ]))->toThrow(UnmappableContent::class);
});

it('refuses an application class', function (): void {
    expect(fn (): mixed => ValueObjectMapper::decode([
        '__prism_class' => 'App\Models\User',
        '__prism_props' => [],
    ]))->toThrow(UnmappableContent::class);
});
