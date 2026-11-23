<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use InvalidArgumentException;

/**
 * RFC 8785 (JCS) canonical JSON, and the digest of it.
 *
 * Keys sorted by UTF-16 code units, no whitespace, ECMAScript number
 * formatting. Spelled the same way in all three harness languages, so the same
 * arguments give the same digest in each, which is what lets a record written
 * by one be matched by another.
 *
 * One PHP-specific limit: an empty PHP array cannot say whether it was `[]` or
 * `{}`. Nested inside the arguments it is written as `[]`. The arguments
 * themselves are always an object.
 */
final class CanonicalJson
{
    /** Integers below this are exact in a double; at or above it they are formatted as one. */
    private const EXACT_INTEGER = 9007199254740992;

    /** `sha256:` and the hex sha256 of the arguments' canonical JSON. Never the arguments. */
    public static function digest(mixed $arguments): string
    {
        $canonical = is_array($arguments) && $arguments === [] ? '{}' : self::encode($arguments ?? []);

        return 'sha256:'.hash('sha256', $canonical);
    }

    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => abs($value) < self::EXACT_INTEGER ? (string) $value : self::number((float) $value),
            is_float($value) => self::number($value),
            is_string($value) => self::string($value),
            is_array($value) => array_is_list($value)
                ? '['.implode(',', array_map(self::encode(...), $value)).']'
                : self::object($value),
            $value instanceof \JsonSerializable => self::encode($value->jsonSerialize()),
            default => throw new InvalidArgumentException('Canonical JSON has no representation for '.get_debug_type($value).'.'),
        };
    }

    /** @param array<array-key, mixed> $value */
    private static function object(array $value): string
    {
        $keys = array_map(strval(...), array_keys($value));
        usort($keys, static fn (string $a, string $b): int => strcmp(self::utf16($a), self::utf16($b)));

        return '{'.implode(',', array_map(
            static fn (string $key): string => self::string($key).':'.self::encode($value[$key]),
            $keys,
        )).'}';
    }

    private static function utf16(string $key): string
    {
        // By hand rather than mbstring/iconv, which this package does not
        // require. Invalid UTF-8 is refused by string() below anyway.
        if (preg_match_all('/./su', $key, $characters) === false) {
            return $key;
        }
        $units = '';
        foreach ($characters[0] as $character) {
            $bytes = array_values(unpack('C*', $character) ?: []);
            $point = match (count($bytes)) {
                1 => $bytes[0],
                2 => (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F),
                3 => (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F),
                default => (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F),
            };
            $units .= $point >= 0x10000
                ? pack('n2', 0xD800 | (($point - 0x10000) >> 10), 0xDC00 | (($point - 0x10000) & 0x3FF))
                : pack('n', $point);
        }

        return $units;
    }

    private static function string(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    /** ECMAScript Number::toString, as RFC 8785 requires. */
    private static function number(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Canonical JSON has no representation for a non-finite number.');
        }
        if ($value == 0.0) {
            return '0';
        }
        if (abs($value) < self::EXACT_INTEGER && floor($value) === $value) {
            return (string) (int) $value;
        }

        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $shortest = var_export(abs($value), true);
        } finally {
            ini_set('serialize_precision', $precision === false ? '-1' : $precision);
        }

        $sign = $value < 0 ? '-' : '';
        [$mantissa, $exponent] = array_pad(explode('E', strtoupper($shortest), 2), 2, '0');
        [$whole, $fraction] = array_pad(explode('.', $mantissa, 2), 2, '');
        $digits = $whole.$fraction;
        $point = strlen($whole) + (int) $exponent;
        $stripped = ltrim($digits, '0');
        $point -= strlen($digits) - strlen($stripped);
        $digits = rtrim($stripped, '0');
        $digits = $digits === '' ? '0' : $digits;
        $k = strlen($digits);

        if ($k <= $point && $point <= 21) {
            return $sign.$digits.str_repeat('0', $point - $k);
        }
        if ($point > 0 && $point <= 21) {
            return $sign.substr($digits, 0, $point).'.'.substr($digits, $point);
        }
        if ($point > -6 && $point <= 0) {
            return $sign.'0.'.str_repeat('0', -$point).$digits;
        }
        $shift = $point - 1;

        return $sign.$digits[0].($k > 1 ? '.'.substr($digits, 1) : '').'e'.($shift > 0 ? '+' : '-').abs($shift);
    }
}
