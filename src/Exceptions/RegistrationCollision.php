<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use LogicException;

final class RegistrationCollision extends LogicException
{
    public readonly string $errorCode;

    public function __construct(?string $agent, string $name, string $previous, string $incoming)
    {
        $this->errorCode = 'registration_collision';
        parent::__construct(sprintf('Harness registry collision: agent [%s], name [%s], owners [%s] and [%s]. Use replace naming predecessor owner [%s].', $agent ?? 'legacy', $name, $previous, $incoming, $previous));
    }
}
