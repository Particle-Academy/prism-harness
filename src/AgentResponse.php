<?php

declare(strict_types=1);

namespace Prism\Harness;

use Prism\Prism\Text\Response;

final readonly class AgentResponse
{
    public function __construct(public string $runId, public Response $response) {}

    public function text(): string
    {
        return $this->response->text;
    }
}
