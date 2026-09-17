<?php

declare(strict_types=1);

namespace Prism\Harness\Structured;

use Prism\Prism\Contracts\Schema;

/**
 * Does a document the model returned actually satisfy the schema it was given?
 *
 * A provider that supports strict structured output answers yes by
 * construction. Most do not, and the ones that do can still be pointed at a
 * model or an endpoint where the mode is unavailable — so the question has to
 * be asked here, once, on the way back.
 *
 * WHY THIS IS NOT COERCION. The tempting alternative is to keep the fields that
 * fit and drop the rest, which turns a wrong answer into a plausible one: a
 * planning agent that returns a document missing its `nodes` array becomes a
 * plan with no steps, and an empty plan settles a batch as done. The consumer
 * who asked for this said the same thing from the other side — they refuse a
 * document they cannot read rather than treat it as "nothing proposed".
 *
 * WHAT IT CHECKS is what a JSON schema says without needing a resolver: the
 * declared type, the required keys, the members of an enum, the items of an
 * array, and, where a schema closes itself to additions, keys nobody declared.
 * It reads `Schema::toArray()` rather than the concrete classes, so a
 * `RawSchema` carrying hand-written JSON Schema is checked on the same terms.
 *
 * WHAT IT DOES NOT CHECK: `$ref`, `allOf`, `oneOf`, `not`, and the numeric and
 * string facets (`minimum`, `pattern`, `minLength`). Each would be a partial
 * implementation of a specification this package has no business owning, and a
 * partial validator that reports nothing for a constraint it cannot read is
 * worse than one whose limits are written down. What it cannot read, it passes.
 */
final class SchemaCheck
{
    /**
     * Every way this document fails its schema, deepest path first in the order found.
     *
     * An empty list means it satisfies the parts of the schema this can read.
     *
     * @return list<string>
     */
    public static function problems(Schema $schema, mixed $document): array
    {
        return self::check($schema->toArray(), $document, $schema->name());
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function check(array $schema, mixed $value, string $path): array
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            return self::checkAnyOf($schema['anyOf'], $value, $path);
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            return in_array($value, $schema['enum'], true)
                ? []
                : [sprintf('%s is %s, which is not one of %s.', $path, self::describe($value), self::members($schema['enum']))];
        }

        $types = self::types($schema);

        if ($types === []) {
            // Nothing declared to check against. A schema that says nothing
            // about a value cannot be violated by it.
            return [];
        }

        if (! self::matchesAny($types, $value)) {
            return [sprintf('%s is %s, and the schema asks for %s.', $path, self::describe($value), self::members($types))];
        }

        if (in_array('object', $types, true) && is_array($value)) {
            return self::checkObject($schema, $value, $path);
        }

        if (in_array('array', $types, true) && is_array($value)) {
            return self::checkArray($schema, $value, $path);
        }

        return [];
    }

    /**
     * @param  array<int|string, mixed>  $branches
     * @return list<string>
     */
    private static function checkAnyOf(array $branches, mixed $value, string $path): array
    {
        foreach ($branches as $branch) {
            if (is_array($branch) && self::check($branch, $value, $path) === []) {
                return [];
            }
        }

        return [sprintf('%s is %s, which satisfies none of the alternatives the schema allows.', $path, self::describe($value))];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private static function checkObject(array $schema, array $value, string $path): array
    {
        $problems = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $name) {
            if (is_string($name) && ! array_key_exists($name, $value)) {
                $problems[] = sprintf('%s.%s is required and missing.', $path, $name);
            }
        }

        foreach ($properties as $name => $property) {
            if (! is_string($name) || ! is_array($property) || ! array_key_exists($name, $value)) {
                continue;
            }

            foreach (self::check($property, $value[$name], $path.'.'.$name) as $problem) {
                $problems[] = $problem;
            }
        }

        // Only where the schema closed itself. An open object invites the extra
        // key, so reporting it would be this package's opinion rather than the
        // schema's.
        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($value) as $name) {
                if (is_string($name) && ! array_key_exists($name, $properties)) {
                    $problems[] = sprintf('%s.%s was returned, and the schema declares no such property.', $path, $name);
                }
            }
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private static function checkArray(array $schema, array $value, string $path): array
    {
        $items = $schema['items'] ?? null;

        if (! is_array($items)) {
            return [];
        }

        $problems = [];

        foreach (array_values($value) as $index => $item) {
            foreach (self::check($items, $item, sprintf('%s[%d]', $path, $index)) as $problem) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * The declared types, as a list — `nullable` schemas declare two.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function types(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_string($type)) {
            return [$type];
        }

        if (is_array($type)) {
            return array_values(array_filter($type, is_string(...)));
        }

        return [];
    }

    /**
     * @param  list<string>  $types
     */
    private static function matchesAny(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            if (self::matches($type, $value)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            // An integer satisfies `number`, as JSON Schema says it does. A
            // float does NOT satisfy `integer`, and 2.0 decoded from JSON is a
            // float — the one place PHP's looseness would quietly pass.
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            // json_decode(..., true) gives both as arrays; a list is an array
            // and a map is an object, and an empty array is either.
            'array' => is_array($value) && ($value === [] || array_is_list($value)),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            default => true,
        };
    }

    private static function describe(mixed $value): string
    {
        if (is_array($value)) {
            return $value === [] || array_is_list($value) ? 'an array' : 'an object';
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => sprintf('the string "%s"', self::shorten($value)),
            is_int($value), is_float($value) => sprintf('the number %s', $value),
            default => get_debug_type($value),
        };
    }

    /**
     * The first 40 characters of a value quoted back in a message.
     *
     * PCRE's `u` modifier rather than `mb_strimwidth`, because this package
     * declares no `ext-mbstring` and a message helper is no reason to start.
     * A string PCRE refuses as malformed UTF-8 falls back to bytes: a message
     * about a document that is already broken should not itself throw.
     */
    private static function shorten(string $value): string
    {
        $shortened = preg_replace('/^(.{0,40}).*$/us', '$1', $value);

        if ($shortened === null) {
            $shortened = substr($value, 0, 40);
        }

        return $shortened === $value ? $value : $shortened.'…';
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function members(array $values): string
    {
        return implode(', ', array_map(
            fn (mixed $value): string => is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            array_values($values),
        ));
    }
}
