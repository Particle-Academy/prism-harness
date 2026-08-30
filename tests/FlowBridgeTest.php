<?php

declare(strict_types=1);

use Prism\Harness\Exceptions\ToolNotAvailable;
use Prism\Harness\Flow\HarnessAgentExecutor;
use Prism\Harness\Flow\HarnessToolInvoker;
use Prism\Harness\Flow\PrismLlmClient;
use Prism\Prism\Tool;

/**
 * The fancy-flow bridge.
 *
 * fancy-flow needs PHP 8.4 and this package supports 8.2, so it is not
 * installed here — the contracts are declared in tests/Pest.php when absent so
 * these run across the whole matrix. That mirroring is the risk, and it is
 * checked against the real package rather than assumed: see the note there.
 */
it('refuses a tool it was never given', function (): void {
    // Thrown, not returned. A model handed "unknown tool" as a RESULT treats it
    // as information and spends the step budget guessing at an allowlist it
    // cannot see, while the workflow author who wrote the wrong name sees only
    // a poor answer.
    $invoker = new HarnessToolInvoker(['search' => (new Tool)->as('search')->for('x')->using(fn (): string => 'ok')]);

    expect(fn (): mixed => $invoker->invoke('delete_everything'))
        ->toThrow(ToolNotAvailable::class);
});

it('names what it does have, so the wrong name is obvious', function (): void {
    $invoker = new HarnessToolInvoker(['search' => (new Tool)->as('search')->for('x')->using(fn (): string => 'ok')]);

    expect(fn (): mixed => $invoker->invoke('serch'))
        ->toThrow(ToolNotAvailable::class, 'available: search');
});

it('distinguishes an empty invoker from a wrong name', function (): void {
    // An invoker with no tools at all is almost always a wiring mistake, and
    // "available: " with nothing after it sends people to the wrong file.
    expect(fn (): mixed => (new HarnessToolInvoker)->invoke('anything'))
        ->toThrow(ToolNotAvailable::class, 'given no tools at all');
});

it('runs a tool it was given and returns its value untouched', function (): void {
    $invoker = new HarnessToolInvoker([
        'echo' => (new Tool)->as('echo')->for('echo')->withStringParameter('word', 'w')->using(fn (string $word): string => $word),
    ]);

    expect($invoker->invoke('echo', ['word' => 'hello']))->toBe('hello');
});

it('raises when a tool fails, rather than passing the failure on as data', function (): void {
    // Prism's Tool::handle() CATCHES a handler exception and returns a
    // ToolError value — it does not propagate. So the failure arrives as a
    // return, and an invoker that only caught exceptions would hand that
    // object to the workflow as a successful result, where a later node would
    // read a failure as data.
    //
    // This is why the ToolError branch exists, and why it is the branch that
    // fires for a throwing tool.
    $invoker = new HarnessToolInvoker([
        'broken' => (new Tool)->as('broken')->for('x')->using(function (): string {
            throw new RuntimeException('the underlying thing failed');
        }),
    ]);

    expect(fn (): mixed => $invoker->invoke('broken'))
        ->toThrow(ToolNotAvailable::class, 'The tool [broken] reported a failure')
        ->and(fn (): mixed => $invoker->invoke('broken'))
        ->toThrow(ToolNotAvailable::class, 'the underlying thing failed');
});

it('says which knob is unset when no model is configured', function (): void {
    // Prism has no default model. A provider asked for none fails with a
    // generic HTTP error naming neither the cause nor the fix.
    $client = new PrismLlmClient(defaultProvider: 'anthropic');

    expect(fn (): array => $client->complete('hi'))
        ->toThrow(RuntimeException::class, 'No model configured');
});

it('implements the contract fancy-flow actually declares', function (): void {
    // The mirror is the risk. This asserts the shape the bridge is written
    // against; the signatures were verified against fancy-flow v0.49.1 with the
    // real package installed, and this fails if either side is renamed here.
    expect(class_implements(PrismLlmClient::class))
        ->toContain('FancyFlow\Nodes\Support\LlmClient')
        ->and(class_implements(HarnessToolInvoker::class))
        ->toContain('FancyFlow\Nodes\Support\ToolInvoker');

    $complete = new ReflectionMethod('FancyFlow\Nodes\Support\LlmClient', 'complete');
    expect($complete->getNumberOfParameters())->toBe(2)
        ->and((string) $complete->getReturnType())->toBe('array');
});

it('ships an executor for the current durable fancy-flow node contract', function (): void {
    expect(class_implements(HarnessAgentExecutor::class))
        ->toContain('FancyFlow\\Contracts\\NodeExecutor');

    $execute = new ReflectionMethod(HarnessAgentExecutor::class, 'execute');
    expect((string) $execute->getParameters()[0]->getType())->toBe('FancyFlow\\Runtime\\ExecutionContext')
        ->and((string) $execute->getReturnType())->toBe('array');
});
