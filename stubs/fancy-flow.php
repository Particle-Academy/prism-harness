<?php

/**
 * The fancy-flow contracts this package implements, declared for static
 * analysis only.
 *
 * fancy-flow-php requires PHP 8.4 and this package supports 8.2, so it cannot
 * be a require-dev without dropping two supported versions from CI. These stubs
 * let PHPStan check the bridge against the real signatures without the install.
 *
 * They MIRROR fancy-flow and do not define it: if the upstream contract
 * changes, this file is what has to be updated, and the integration test that
 * runs against the real package is what will notice.
 */

namespace FancyFlow\Nodes\Support;

interface LlmClient
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{text: string, data?: mixed, usage?: array<string, mixed>, raw?: mixed}
     */
    public function complete(string $prompt, array $options = []): array;
}

interface ToolInvoker
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function invoke(string $tool, array $args = []): mixed;
}

namespace FancyFlow\Contracts;

use FancyFlow\Runtime\ExecutionContext;

interface NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed;
}

namespace FancyFlow\Schema;

final class FlowNode
{
    public string $id;

    /** @var array<string, mixed> */
    public array $config;
}

namespace FancyFlow\Runtime;

use FancyFlow\Schema\FlowNode;

final class RunEvent
{
    public static function log(string $level, string $message, ?string $nodeId = null, mixed $detail = null): self {}
}

final class ExecutionContext
{
    public FlowNode $node;

    public function option(string $key, mixed $default = null): mixed {}

    public function input(string $port = 'in', mixed $default = null): mixed {}

    public function emit(RunEvent $event): void {}
}
