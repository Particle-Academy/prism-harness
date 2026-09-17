<?php

declare(strict_types=1);

use Prism\Harness\Structured\SchemaCheck;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Schema\StringSchema;

/*
| The check reads Schema::toArray() rather than the concrete classes, so a
| RawSchema carrying hand-written JSON Schema is held to the same terms as one
| built from Prism's objects. What it cannot read, it passes — and the tests for
| that are as load-bearing as the ones for what it refuses, because a validator
| that silently reports nothing for a constraint it does not understand is the
| failure mode worth pinning.
*/

it('passes a document that satisfies its schema', function (): void {
    $schema = new ObjectSchema('plan', 'A plan', [
        new StringSchema('title', 'Title'),
        new ArraySchema('steps', 'Steps', new StringSchema('step', 'A step')),
    ], ['title', 'steps']);

    expect(SchemaCheck::problems($schema, ['title' => 'Ship', 'steps' => ['write']]))->toBe([]);
});

it('names a required field that is missing, by path', function (): void {
    $schema = new ObjectSchema('plan', 'A plan', [new StringSchema('title', 'Title')], ['title']);

    expect(SchemaCheck::problems($schema, []))->toBe(['plan.title is required and missing.']);
});

it('reports EVERY problem, not the first', function (): void {
    // A model that drops one required field usually drops several, and fixing
    // them one exception at a time costs a provider call each.
    $schema = new ObjectSchema('plan', 'A plan', [
        new StringSchema('title', 'Title'),
        new NumberSchema('confidence', 'Confidence'),
    ], ['title', 'confidence']);

    expect(SchemaCheck::problems($schema, []))->toHaveCount(2);
});

it('checks the type of a value that is present', function (): void {
    $schema = new ObjectSchema('plan', 'A plan', [new NumberSchema('confidence', 'Confidence')], []);

    expect(SchemaCheck::problems($schema, ['confidence' => 'very']))
        ->toBe(['plan.confidence is the string "very", and the schema asks for \'number\'.']);
});

it('accepts an integer where a number is asked for, and refuses a float where an integer is', function (): void {
    // JSON Schema says an integer IS a number. The other direction is where
    // PHP's looseness would quietly pass: 2.0 decoded from JSON is a float.
    $number = new ObjectSchema('n', 'n', [new NumberSchema('value', 'v')], []);
    $integer = new ObjectSchema('n', 'n', [new RawSchema('value', ['type' => 'integer'])], []);

    expect(SchemaCheck::problems($number, ['value' => 2]))->toBe([])
        ->and(SchemaCheck::problems($number, ['value' => 2.5]))->toBe([])
        ->and(SchemaCheck::problems($integer, ['value' => 2]))->toBe([])
        ->and(SchemaCheck::problems($integer, ['value' => 2.0]))->toHaveCount(1);
});

it('checks inside an array, item by item', function (): void {
    $schema = new ObjectSchema('plan', 'A plan', [
        new ArraySchema('steps', 'Steps', new ObjectSchema('step', 'A step', [new StringSchema('do', 'Do')], ['do'])),
    ], ['steps']);

    expect(SchemaCheck::problems($schema, ['steps' => [['do' => 'write'], ['note' => 'oops']]]))
        ->toBe([
            'plan.steps[1].do is required and missing.',
            'plan.steps[1].note was returned, and the schema declares no such property.',
        ]);
});

it('refuses a value outside an enum, and names the members', function (): void {
    $schema = new ObjectSchema('plan', 'A plan', [new EnumSchema('mode', 'Mode', ['fast', 'careful'])], []);

    expect(SchemaCheck::problems($schema, ['mode' => 'reckless']))
        ->toBe(["plan.mode is the string \"reckless\", which is not one of 'fast', 'careful'."]);
});

it('accepts null only where the schema says nullable', function (): void {
    $strict = new ObjectSchema('plan', 'A plan', [new StringSchema('title', 'Title')], []);
    $nullable = new ObjectSchema('plan', 'A plan', [new StringSchema('title', 'Title', nullable: true)], []);

    expect(SchemaCheck::problems($strict, ['title' => null]))->toHaveCount(1)
        ->and(SchemaCheck::problems($nullable, ['title' => null]))->toBe([]);
});

it('reports a key nobody declared ONLY when the schema closed itself', function (): void {
    // An open object invites the extra key, so reporting it would be this
    // package's opinion rather than the schema's.
    $closed = new ObjectSchema('plan', 'A plan', [new StringSchema('title', 'Title')], [], allowAdditionalProperties: false);
    $open = new ObjectSchema('plan', 'A plan', [new StringSchema('title', 'Title')], [], allowAdditionalProperties: true);

    expect(SchemaCheck::problems($closed, ['title' => 'Ship', 'extra' => 1]))->toHaveCount(1)
        ->and(SchemaCheck::problems($open, ['title' => 'Ship', 'extra' => 1]))->toBe([]);
});

it('tells an object from a list', function (): void {
    $object = new ObjectSchema('plan', 'A plan', [], []);
    $array = new ArraySchema('steps', 'Steps', new StringSchema('step', 'A step'));

    expect(SchemaCheck::problems($object, ['a', 'b']))->toHaveCount(1)
        ->and(SchemaCheck::problems($array, ['title' => 'Ship']))->toHaveCount(1)
        // An empty array is either, and saying otherwise would refuse the
        // commonest legitimate answer: a document with nothing in that field.
        ->and(SchemaCheck::problems($object, []))->toBe([])
        ->and(SchemaCheck::problems($array, []))->toBe([]);
});

it('passes what it cannot read rather than guessing', function (): void {
    // $ref, allOf, oneOf and the numeric facets are not implemented. A
    // validator reporting nothing for a constraint it cannot parse is the
    // failure worth naming, so it is named here and in the class docblock.
    $unreadable = new RawSchema('thing', ['$ref' => '#/definitions/Thing']);
    $facets = new RawSchema('count', ['type' => 'integer', 'minimum' => 10]);

    expect(SchemaCheck::problems($unreadable, ['anything' => true]))->toBe([])
        ->and(SchemaCheck::problems($facets, 1))->toBe([]);
});

it('takes any branch of an anyOf', function (): void {
    $schema = new RawSchema('value', ['anyOf' => [['type' => 'string'], ['type' => 'number']]]);

    expect(SchemaCheck::problems($schema, 'text'))->toBe([])
        ->and(SchemaCheck::problems($schema, 3))->toBe([])
        ->and(SchemaCheck::problems($schema, true))->toHaveCount(1);
});

it('checks a BooleanSchema as a boolean', function (): void {
    $schema = new ObjectSchema('plan', 'A plan', [new BooleanSchema('ready', 'Ready')], []);

    expect(SchemaCheck::problems($schema, ['ready' => true]))->toBe([])
        ->and(SchemaCheck::problems($schema, ['ready' => 'yes']))->toHaveCount(1);
});
