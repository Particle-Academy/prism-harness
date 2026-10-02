<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

final class LiveThreadKey
{
    public static function key(?string $participantType, int|string|null $participantId, string $scope): string
    {
        // A fixed-width key avoids index-length limits and delimiter ambiguity.
        // Integer and hydrated string ids address the same participant.
        return hash('sha256', json_encode([
            $participantType,
            $participantId === null ? null : (string) $participantId,
            $scope,
        ], JSON_THROW_ON_ERROR));
    }
}
