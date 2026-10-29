<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use RuntimeException;

final class AddressLimitExceeded extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct()
    {
        $this->errorCode = 'address_limit_exceeded';
        parent::__construct('The configured max_addresses_per_participant limit was reached; no resource was written.');
    }
}
