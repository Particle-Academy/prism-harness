<?php

declare(strict_types=1);

namespace Prism\Harness\Participants;

use InvalidArgumentException;

/** A host-defined address, never an Eloquent morph class to instantiate. */
final readonly class ParticipantRef
{
    public string $id;

    public function __construct(public string $type, int|string $id)
    {
        $this->id = (string) $id;
        foreach ([$type, $this->id] as $value) {
            if ($value === '' || strlen($value) > 255 || preg_match('//u', $value) !== 1) {
                throw new InvalidArgumentException('Participant references require non-empty UTF-8 type and id, at most 255 bytes each.');
            }
        }
    }
}
