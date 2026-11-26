<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\AuthorizedTool;
use Prism\Harness\Tools\GuardedTool;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\Fixtures\Participant;

// The call-time authorization guard decides whether a call runs, and nothing
// else: an allowed call returns, fails and reads its arguments exactly as the
// tool does unguarded, and a refused call never reaches the tool.

final class ExtraMethodTool extends Tool
{
    public function extra(): string
    {
        return 'extra';
    }
}

// Its own handle() and a public method of its own: neither guard can be put
// in front of its calls.
final class OwnHandleTool extends Tool
{
    public static int $ran = 0;

    public function extra(): string
    {
        return 'extra';
    }

    public function handle(...$args): string
    {
        self::$ran++;

        return 'ran';
    }
}

function guardOutcome(Tool $tool, array $args): mixed
{
    try {
        $out = $tool->handle(...$args);

        return $out instanceof ToolError ? ['ToolError', $out->message] : $out;
    } catch (Throwable $e) {
        return [$e::class, $e->getMessage()];
    }
}

function guarded(Tool $tool, bool $allow): Tool
{
    config()->set('prism-harness.agent.authorize_tools', true);
    Gate::define(ToolAuthorizer::CALL_ABILITY, fn (): bool => $allow);
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');

    return AuthorizedTool::wrap($tool, $session, new ToolAuthorizer(app(GateContract::class), true));
}

it('returns, fails and reads its arguments as the tool does when the call is allowed', function (Closure $make, array $args): void {
    $wrapped = guarded($make(), true);

    expect($wrapped)->toBeInstanceOf(GuardedTool::class)
        ->and(guardOutcome($wrapped, $args))->toEqual(guardOutcome($make(), $args))
        ->and([$wrapped->name(), $wrapped->parametersAsArray(), $wrapped->namesParameter('input')])
        ->toBe([$make()->name(), $make()->parametersAsArray(), $make()->namesParameter('input')]);
})->with([
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
        ->using(fn (string $filter): string => 'got '.$filter), ['filter' => '{"k":"v"}']],
]);

it('does not offer a tool no guard can be put in front of', function (): void {
    OwnHandleTool::$ran = 0;
    $tool = (new OwnHandleTool)->as('own')->for('Own.')->withStringParameter('query', 'Q');
    config()->set('prism-harness.agent.authorize_tools', true);
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY, fn (): bool => false);
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $authorizer = new ToolAuthorizer(app(GateContract::class), true);
    $plain = (new Tool)->as('plain')->for('Plain.')->using(fn (): string => 'p');

    $offered = $authorizer->allowed($session, [$tool, $plain]);

    expect(AuthorizedTool::wrap($tool, $session, $authorizer))->toBeNull()
        ->and(array_map(fn (Tool $t): string => $t->name(), $offered))->toBe(['plain'])
        ->and(OwnHandleTool::$ran)->toBe(0);
});

it('never lets a refused call reach the tool', function (Closure $make): void {
    $ran = 0;
    $tool = $make()->using(function (string $query) use (&$ran): string {
        $ran++;

        return 'ran';
    });
    $wrapped = guarded($tool, false);

    expect(json_decode((string) $wrapped->handle(query: 'a'), true)['allowed'])->toBeFalse()
        ->and($ran)->toBe(0);
})->with([
    'a plain tool' => [fn () => (new Tool)->as('search')->for('S')->withStringParameter('query', 'Q')],
    'a tool the guard cannot stand in for' => [fn () => (new ExtraMethodTool)->as('search')->for('S')->withStringParameter('query', 'Q')],
]);
