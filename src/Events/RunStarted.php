<?php

declare(strict_types=1);

namespace Prism\Harness\Events;

final readonly class RunStarted extends HarnessEvent
{
    public function __construct(
        string $sessionKey,
        string $runId,
        public string $mode,
        public string $provider,
        public string $model,
        ?string $parentRunId = null,
        ?string $rootRunId = null,
    ) {
        parent::__construct($sessionKey, $runId, $parentRunId, $rootRunId);
    }
}
