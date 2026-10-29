<?php

declare(strict_types=1);

namespace Prism\Harness\Math;

use RuntimeException;

/** A host registration failed; the untouched engine report is the authority. */
final class FormulaDocumentRejected extends RuntimeException
{
    public function __construct(public readonly CheckResult $result)
    {
        parent::__construct('The formula document was refused by the engine check; inspect the engine report.');
    }
}
