<?php

declare(strict_types=1);

use Prism\Harness\Subagents\ObservedTool;
use Prism\Harness\Subagents\ResolutionRecorder;
use Prism\Harness\Tools\DelegatingTool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolError;
use Prism\Prism\ValueObjects\ToolResult;

// The harness watches a resumed run's approved tools so it can record what
// each returned if the turn throws later. Watching must change nothing about
// the tool: the provider sees the same tool, and core gets the same return,
// the same ToolError and the same exception as from the tool itself.

final class InvokableSearch extends Tool
{
    public function __construct()
    {
        $this->as('search')->for('Search.')->withStringParameter('query', 'Query')->using($this);
    }

    public function __invoke(string $query): string
    {
        return 'invoked '.$query;
    }
}

final class RenamingSearch extends Tool
{
    public function name(): string
    {
        return 'search';
    }
}

// The shape of a tool class that takes its handler's parameters from
// __invoke, so core's namesParameter() reflects on that method.
final class InvokableRaw extends Tool
{
    public function __construct()
    {
        // No using(): core falls back to __invoke for the handler.
        $this->as('search')->for('Search.');
    }

    public function __invoke(string $input): string
    {
        return 'invoked '.$input;
    }
}

final class ExtraApiSearch extends Tool
{
    public function extraApi(): string
    {
        return 'extra';
    }
}

final class PublicStateSearch extends Tool
{
    public string $label = 'labelled';
}

/** @param list<array{0: string, 1: string, 2: array<string, mixed>}> $calls */
function watchedRecorder(array $calls): ResolutionRecorder
{
    $toolCalls = array_map(fn (array $c): ToolCall => new ToolCall($c[0], $c[1], $c[2]), $calls);
    $recorder = ResolutionRecorder::for([
        new AssistantMessage('', $toolCalls, [], array_map(fn (ToolCall $c): ToolApprovalRequest => new ToolApprovalRequest('apr_'.$c->id, $c->id), $toolCalls)),
        new ToolResultMessage([], array_map(fn (ToolCall $c): ToolApprovalResponse => new ToolApprovalResponse('apr_'.$c->id, true), $toolCalls)),
    ]);
    expect($recorder)->not->toBeNull();

    return $recorder;
}

/** @return array<string, mixed> */
function toolShape(Tool $tool): array
{
    return [$tool->name(), $tool->description(), $tool->parametersAsArray(), $tool->requiredParameters(),
        $tool->hasApprovalConfigured(), $tool->isConcurrent(), $tool->failedHandler() === false, $tool->providerOptions(),
        $tool->namesParameter('input'), $tool->namesParameter('raw'), $tool->namesParameter('query'),
        $tool->isClientExecuted(), $tool->withholdsOtherCalls(), $tool->hasParameters()];
}

it('returns, fails and describes itself exactly as the tool it watches', function (Closure $make, array $args): void {
    $plain = $make();
    $watched = ObservedTool::of($make(), watchedRecorder([['call_1', 'search', $args]]));
    $outcome = function (Tool $tool) use ($args): mixed {
        try {
            $out = $tool->handle(...$args);

            return $out instanceof ToolError ? ['ToolError', $out->message] : $out;
        } catch (Throwable $e) {
            return [$e::class, $e->getMessage()];
        }
    };

    expect($watched)->toBeInstanceOf(ObservedTool::class)
        ->and(toolShape($watched))->toBe(toolShape($plain))
        ->and($outcome($watched))->toEqual($outcome($plain));
})->with([
    'string result' => [fn () => (new Tool)->as('search')->for('S')->withStringParameter('query', 'Q')->using(fn (string $query): string => 'r '.$query), ['query' => 'a']],
    'custom failure handler' => [fn () => (new Tool)->as('search')->for('S')->withStringParameter('query', 'Q')
        ->failed(fn (Throwable $e): string => 'handled '.$e->getMessage())->using(function (string $query): string {
            throw new RuntimeException('boom');
        }), ['query' => 'a']],
    'default failure handling' => [fn () => (new Tool)->as('search')->for('S')->withStringParameter('query', 'Q')->using(function (string $query): string {
        throw new RuntimeException('boom');
    }), ['query' => 'a']],
    'without error handling' => [fn () => (new Tool)->as('search')->for('S')->withStringParameter('query', 'Q')->withoutErrorHandling()->using(function (string $query): string {
        throw new LogicException('raw');
    }), ['query' => 'a']],
    'JSON string for a string-typed object parameter' => [fn () => (new Tool)->as('search')->for('S')
        ->withParameter(new ObjectSchema('filter', 'F', [new StringSchema('k', 'K')]))
        ->using(fn (string $filter): string => 'got '.gettype($filter).' '.$filter), ['filter' => '{"k":"v"}']],
    'approval and concurrency settings' => [fn () => (new Tool)->as('search')->for('S')->withStringParameter('query', 'Q')
        ->requiresApproval(true)->concurrent()->using(fn (string $query): string => 'r'), ['query' => 'a']],
    'an invokable Tool subclass' => [fn () => new InvokableSearch, ['query' => 'a']],
    'an invokable Tool subclass whose handler names input' => [fn () => new InvokableRaw, ['input' => 'a']],
    'a Tool subclass that overrides a Tool method' => [fn () => (new RenamingSearch)->as('other')->for('S')->withStringParameter('query', 'Q')
        ->using(fn (string $query): string => 'r '.$query), ['query' => 'a']],
]);

it('does not wrap a tool whose class adds public methods of its own', function (): void {
    $tool = (new ExtraApiSearch)->as('search')->for('S')->using(fn (): string => 'r');

    expect(ObservedTool::of($tool, watchedRecorder([['call_1', 'search', []]])))->toBeNull();
});

it('wraps a tool with public state of its own without a deprecation', function (): void {
    $tool = (new PublicStateSearch)->as('search')->for('S')->withStringParameter('query', 'Q')->using(fn (string $query): string => 'r');
    set_error_handler(function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });
    try {
        $watched = ObservedTool::of($tool, watchedRecorder([['call_1', 'search', ['query' => 'a']]]));
    } finally {
        restore_error_handler();
    }

    expect($watched)->toBeInstanceOf(ObservedTool::class)
        ->and($watched?->handle(query: 'a'))->toBe('r');
});

it('changes the wrapped tool when a setter is called on the wrapper', function (): void {
    $tool = (new Tool)->as('search')->for('S')->withStringParameter('query', 'Q')->using(fn (string $query): string => 'r');
    $watched = ObservedTool::of($tool, watchedRecorder([['call_1', 'search', ['query' => 'a']]]));

    expect($watched?->withoutErrorHandling())->toBe($watched)
        ->and($tool->failedHandler())->toBeFalse()
        ->and($watched?->copyWithProviderOptions(['k' => 'v'])->providerOptions('k'))->toBe('v')
        ->and($tool->providerOptions('k'))->toBeNull();
});

// When core adds a public Tool method, this fails, and until the method is
// delegated no tool is wrapped (each turn falls back to resolving first).
it('delegates every public method of Tool', function (): void {
    $missing = [];
    foreach ((new ReflectionClass(Tool::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! $method->isStatic() && $method->getName() !== '__construct'
            && (new ReflectionMethod(DelegatingTool::class, $method->getName()))->getDeclaringClass()->getName() !== DelegatingTool::class) {
            $missing[] = $method->getName();
        }
    }

    expect($missing)->toBe([])
        ->and(DelegatingTool::canWrap(new Tool))->toBeTrue();
});

it('records what each approved call returned, in order, and ends at a call it does not know', function (): void {
    $recorder = watchedRecorder([['call_1', 'search', ['query' => 'a']], ['call_2', 'search', ['query' => 'b']], ['call_3', 'search', ['query' => 'c']]]);

    // call_1 refused by core (no return), call_2 returns a ToolError.
    $recorder->completed('search', ['query' => 'b'], new ToolError('failed b'));
    $recorder->completed('search', ['query' => 'z'], 'not a queued call');
    $recorder->completed('search', ['query' => 'c'], 'late');

    expect(array_map(fn ($r) => [$r->toolCallId, $r->result, $r->args], $recorder->results()))
        ->toBe([['call_2', 'failed b', ['query' => 'b']]]);
});

it('queues only approved calls of the last assistant step that have no result yet', function (): void {
    $calls = [new ToolCall('done', 'search', []), new ToolCall('denied', 'search', []), new ToolCall('open', 'search', []), new ToolCall('free', 'search', [])];
    $recorder = ResolutionRecorder::for([
        new AssistantMessage('', $calls, [], [new ToolApprovalRequest('a1', 'done'), new ToolApprovalRequest('a2', 'denied'), new ToolApprovalRequest('a3', 'open')]),
        new ToolResultMessage([new ToolResult('done', 'search', [], 'r')]),
        new ToolResultMessage([], [new ToolApprovalResponse('a1', true), new ToolApprovalResponse('a2', false), new ToolApprovalResponse('a3', true)]),
    ]);
    $recorder?->completed('search', [], 'r');

    expect(array_map(fn ($r) => $r->toolCallId, $recorder?->results() ?? []))->toBe(['open'])
        ->and(ResolutionRecorder::for([new AssistantMessage('', $calls)]))->toBeNull();
});
