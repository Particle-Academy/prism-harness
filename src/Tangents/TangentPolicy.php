<?php

declare(strict_types=1);

namespace Prism\Harness\Tangents;

enum TangentPolicy: string
{
    case None = 'none';
    case User = 'user';
    case Agent = 'agent';
    case Both = 'both';

    public function permits(string $actor): bool
    {
        if (! in_array($actor, ['user', 'agent'], true)) {
            return false;
        }

        return $this === self::Both || $this->value === $actor;
    }

    public function intersect(self $restriction): self
    {
        $user = $this->permits('user') && $restriction->permits('user');
        $agent = $this->permits('agent') && $restriction->permits('agent');

        return match (true) {
            $user && $agent => self::Both,
            $user => self::User,
            $agent => self::Agent,
            default => self::None,
        };
    }
}
