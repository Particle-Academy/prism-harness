<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

use Closure;

/**
 * Host-minted plan and step ids: `pl_` / `ps_` and a ULID (G-360).
 *
 * Never chosen by the model. The generator is replaceable so the parity corpus
 * can mint the same ids in all three languages.
 */
final class PlanIds
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const STEP_PATTERN = '/\Aps_[0-9A-HJKMNP-TV-Z]{26}\z/';

    /** @param (Closure(string): string)|null $mint  given a prefix (`pl` or `ps`), returns a full id */
    public function __construct(private readonly ?Closure $mint = null) {}

    public function plan(): string
    {
        return $this->mint !== null ? ($this->mint)('pl') : 'pl_'.self::ulid();
    }

    public function step(): string
    {
        return $this->mint !== null ? ($this->mint)('ps') : 'ps_'.self::ulid();
    }

    public static function isStepId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::STEP_PATTERN, $id) === 1;
    }

    public static function ulid(): string
    {
        $time = (int) floor(microtime(true) * 1000);
        $out = '';
        for ($i = 0; $i < 10; $i++) {
            $out = self::CROCKFORD[$time % 32].$out;
            $time = intdiv($time, 32);
        }
        $bits = '';
        foreach (str_split(random_bytes(10)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::CROCKFORD[(int) bindec($chunk)];
        }

        return $out;
    }
}
