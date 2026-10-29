<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use InvalidArgumentException;

final class RegistrationOwner
{
    public static function label(?string $owner, ?string $agent): string
    {
        $label = $owner ?? ($agent === null ? 'legacy' : null);
        if ($label === null || $label === '' || strlen($label) > 128 || preg_match('//u', $label) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $label) === 1) {
            throw new InvalidArgumentException('A named registration requires a non-empty UTF-8 owner label of at most 128 bytes without control characters.');
        }

        return $label;
    }
}
