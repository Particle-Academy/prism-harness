<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use BackedEnum;
use Prism\Harness\Exceptions\UnmappableContent;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Round-trips the Prism value objects that live inside a message's
 * `additionalContent`.
 *
 * That field is typed `array<string, mixed>` and carries whatever a provider
 * decided to attach, which in practice includes OBJECTS. Anthropic wraps every
 * assistant reply in a `MessagePartWithCitations` — not only replies that cite
 * something, every one of them.
 *
 * Storing those as plain JSON turns them into arrays, and an array coming back
 * where a value object is expected does not fail at load time. It fails on the
 * NEXT provider call, inside a mapper, with a TypeError that names a class the
 * application never mentioned. Found exactly that way: a two-turn Anthropic
 * conversation through a thread died on turn two.
 *
 * So objects are stored with their class and rebuilt from it.
 *
 * ---
 *
 * Rebuilding a class named by stored data is dynamic instantiation, so it is
 * bounded: only Prism's own value objects and enums are eligible, and anything
 * else is refused rather than attempted. Combined with the fact that
 * reaching this requires write access to the messages table, the exposure is
 * the same one the README already names — but the allowlist means a thread row
 * cannot be used to construct arbitrary application objects.
 */
final class ValueObjectMapper
{
    /**
     * The only namespaces this will rebuild.
     *
     * Prism's value objects are plain public-property carriers and its enums
     * are backed enums — both safe to reconstruct from data. Providers and
     * handlers are deliberately NOT eligible: they take clients and API keys,
     * and nothing should be able to name one of those from a stored row.
     *
     * @var list<string>
     */
    private const ALLOWED_NAMESPACES = [
        'Prism\\Prism\\ValueObjects\\',
        'Prism\\Prism\\Enums\\',
    ];

    private const CLASS_KEY = '__prism_class';

    private const PROPS_KEY = '__prism_props';

    /**
     * Encode a value for storage, descending into arrays and objects.
     */
    public static function encode(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::encode(...), $value);
        }

        if ($value instanceof BackedEnum) {
            return [
                self::CLASS_KEY => $value::class,
                self::PROPS_KEY => ['value' => $value->value],
            ];
        }

        if (! is_object($value)) {
            return $value;
        }

        if (! self::isAllowed($value::class)) {
            throw UnmappableContent::unstorableObject($value::class);
        }

        $props = [];

        foreach (get_object_vars($value) as $name => $prop) {
            $props[$name] = self::encode($prop);
        }

        return [
            self::CLASS_KEY => $value::class,
            self::PROPS_KEY => $props,
        ];
    }

    /**
     * Rebuild what encode() wrote.
     */
    public static function decode(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_key_exists(self::CLASS_KEY, $value)) {
            return array_map(self::decode(...), $value);
        }

        $class = $value[self::CLASS_KEY];
        /** @var array<string, mixed> $props */
        $props = $value[self::PROPS_KEY] ?? [];

        if (! is_string($class) || ! self::isAllowed($class) || ! class_exists($class)) {
            throw UnmappableContent::unknownStoredClass(is_string($class) ? $class : gettype($class));
        }

        if (is_subclass_of($class, BackedEnum::class)) {
            /** @var class-string<BackedEnum> $class */
            $case = $class::tryFrom($props['value'] ?? '');

            return $case ?? throw UnmappableContent::unknownStoredClass($class);
        }

        return self::construct($class, array_map(self::decode(...), $props));
    }

    private static function isAllowed(string $class): bool
    {
        foreach (self::ALLOWED_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  class-string  $class
     * @param  array<string, mixed>  $props
     */
    private static function construct(string $class, array $props): object
    {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $props)) {
                $arguments[$name] = $props[$name];

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[$name] = $parameter->getDefaultValue();

                continue;
            }

            // A required parameter with nothing stored for it means the shape
            // changed under us. Say so rather than passing null and letting it
            // surface as a TypeError somewhere else entirely.
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
                $arguments[$name] = null;

                continue;
            }

            throw UnmappableContent::missingStoredProperty($class, $name);
        }

        return $reflection->newInstanceArgs(array_values($arguments));
    }
}
