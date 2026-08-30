<?php

declare(strict_types=1);
use Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| fancy-flow contracts
|--------------------------------------------------------------------------
|
| fancy-flow-php requires PHP 8.4 and this package supports 8.2, so it cannot
| be a require-dev without dropping two versions from the CI matrix. The
| contracts the Flow bridge implements are declared here when absent, so those
| tests run everywhere.
|
| These MIRROR fancy-flow v0.49.1 and do not define it. The signatures were
| verified against the real package with it temporarily installed:
|
|     LlmClient::complete(string $prompt, array $options = ...): array
|     ToolInvoker::invoke(string $tool, array $args = ...): mixed
|
| If the upstream contract changes, this file and stubs/fancy-flow.php are what
| drift, and nothing here will notice — which is exactly why the check was run
| against the real package rather than reasoned about, and why it is worth
| re-running when fancy-flow moves.
|
*/

if (! interface_exists('FancyFlow\Nodes\Support\LlmClient')) {
    eval('namespace FancyFlow\Nodes\Support; interface LlmClient { public function complete(string $prompt, array $options = []): array; }');
}

if (! interface_exists('FancyFlow\Nodes\Support\ToolInvoker')) {
    eval('namespace FancyFlow\Nodes\Support; interface ToolInvoker { public function invoke(string $tool, array $args = []): mixed; }');
}

if (! interface_exists('FancyFlow\Contracts\NodeExecutor')) {
    eval('namespace FancyFlow\Contracts; interface NodeExecutor { public function execute(\FancyFlow\Runtime\ExecutionContext $ctx): mixed; }');
}

if (! class_exists('FancyFlow\Runtime\ExecutionContext')) {
    eval('namespace FancyFlow\Runtime; class ExecutionContext {}');
}
