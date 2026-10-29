<?php

declare(strict_types=1);

namespace Prism\Harness\Participants;

use Closure;
use LogicException;

/** Explicit host-owned actor bindings; no class lookup or ambient Auth fallback. */
final class ParticipantAdapters
{
    /** @var array<string, Closure(ParticipantRef): object> */
    private array $actors = [];

    /** @param Closure(ParticipantRef): object $actor */
    public function register(string $type, Closure $actor): void
    {
        new ParticipantRef($type, 'validation');
        if (isset($this->actors[$type])) {
            throw new LogicException('A participant adapter is already registered for this type.');
        }
        $this->actors[$type] = $actor;
    }

    public function actor(ParticipantRef $participant, ?object $inheritedActor = null): object
    {
        $resolve = $this->actors[$participant->type] ?? throw new LogicException('No participant actor adapter is registered for this type.');

        return $inheritedActor ?? $resolve($participant);
    }
}
