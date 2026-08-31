<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Prism\Harness\Exceptions\UnsafeAuthorizationConfiguration;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

function authorizer(bool $enabled): ToolAuthorizer
{
    return new ToolAuthorizer(app(GateContract::class), $enabled);
}

/** @return list<string> */
function offeredTools(array $requests): array
{
    return array_map(fn (Tool $tool): string => $tool->name(), $requests[0]->tools());
}

/*
|--------------------------------------------------------------------------
| H-03 — a policy that is defined but never consulted
|--------------------------------------------------------------------------
*/

it('refuses a defined tool policy while the authorizer is disabled', function (): void {
    // The failure this exists to catch is invisible at runtime: the policy is
    // in the codebase, reads as a control to the next person, and is never
    // called. Nothing errors and every tool is offered to every run.
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => false);

    expect(fn (): ToolAuthorizer => authorizer(enabled: false))
        ->toThrow(UnsafeAuthorizationConfiguration::class);
});

it('names both ways out of the unsafe authorization configuration', function (): void {
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => false);

    try {
        authorizer(enabled: false);
        $this->fail('Expected the disabled-but-defined policy to be refused.');
    } catch (UnsafeAuthorizationConfiguration $e) {
        // An error that only says "no" sends someone hunting.
        expect($e->getMessage())
            ->toContain('prism-harness.agent.authorize_tools')
            ->toContain(ToolAuthorizer::ABILITY);
    }
});

it('accepts a defined policy once the authorizer is enabled', function (): void {
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);

    expect(authorizer(enabled: true)->enabled())->toBeTrue();
});

it('accepts the default install, where no policy is defined and the flag is off', function (): void {
    // The default is not the dangerous shape and must stay frictionless: an
    // app that never writes a policy is served correctly by it.
    expect(authorizer(enabled: false)->enabled())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| H-04 — the mode is a ceiling, not a default
|--------------------------------------------------------------------------
*/

function registerTools(): void
{
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('read_thing')->for('Read')->using(fn (): string => 'r'),
        (new Tool)->as('write_thing')->for('Write')->using(fn (): string => 'w'),
        (new Tool)->as('unrelated_thing')->for('Unrelated')->using(fn (): string => 'u'),
    ]);
}

it('refuses to widen a mode when the caller names a tool outside it', function (): void {
    // Before this, `$toolNames ?? $mode->tools` let the caller's list REPLACE
    // the mode's, so a mode named `readonly` guaranteed nothing.
    registerTools();
    config()->set('prism-harness.agent.modes.chat.tools', ['read_thing']);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->send('Go', ['read_thing', 'write_thing']);

    $fake->assertRequest(function (array $requests): void {
        expect(offeredTools($requests))->toBe(['read_thing']);
    });
});

it('lets a caller narrow within the mode', function (): void {
    registerTools();
    config()->set('prism-harness.agent.modes.chat.tools', ['read_thing', 'write_thing']);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->send('Go', ['read_thing']);

    $fake->assertRequest(function (array $requests): void {
        expect(offeredTools($requests))->toBe(['read_thing']);
    });
});

it('treats a wildcard mode as an unrestricted ceiling', function (): void {
    registerTools();
    config()->set('prism-harness.agent.modes.chat.tools', ['*']);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->send('Go', ['write_thing']);

    $fake->assertRequest(function (array $requests): void {
        expect(offeredTools($requests))->toBe(['write_thing']);
    });
});

it('reads a wildcard FROM THE CALLER as everything the mode allows, never the registry', function (): void {
    // The subtle direction. `'*'` from a caller must not escalate to whatever
    // the registry happens to hold — it means "all of mine", not "all of them".
    registerTools();
    config()->set('prism-harness.agent.modes.chat.tools', ['read_thing']);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->send('Go', ['*']);

    $fake->assertRequest(function (array $requests): void {
        expect(offeredTools($requests))->toBe(['read_thing'])
            ->not->toContain('unrelated_thing');
    });
});

/*
|--------------------------------------------------------------------------
| H-05 — authorization at invocation, with arguments
|--------------------------------------------------------------------------
*/

it('asks the policy again at call time, with the arguments in hand', function (): void {
    // The gap offer-time filtering cannot close: "may use delete_file" is
    // expressible; "only under /tmp" is not, because at filter time there are
    // no arguments yet.
    Gate::define(ToolAuthorizer::ABILITY, fn (): bool => true);
    Gate::define(ToolAuthorizer::CALL_ABILITY, fn ($user, $session, Tool $tool, array $args): bool => str_starts_with((string) ($args['path'] ?? ''), '/tmp/'));
    config()->set('prism-harness.agent.authorize_tools', true);

    $tool = (new Tool)->as('write_thing')->for('Write')
        ->withStringParameter('path', 'Where')
        ->using(fn (string $path): string => 'wrote '.$path);

    $session = harness()->for(Participant::create(['name' => 'Ada']))->session('chat');
    $guarded = app(ToolAuthorizer::class)->allowed($session, ['write_thing' => $tool])[0];

    expect($guarded->handle(path: '/tmp/ok.txt'))->toBe('wrote /tmp/ok.txt');

    $refused = json_decode($guarded->handle(path: '/etc/passwd'), true, 512, JSON_THROW_ON_ERROR);
    expect($refused['allowed'])->toBeFalse()
        // Framed, so the model cannot read a refusal as the tool's own output.
        ->and($refused['_framing'])->toContain('Not output from the tool');
});

it('leaves a tool untouched when the authorizer is disabled', function (): void {
    // No clone, no wrapper, no per-call cost for the default install.
    $tool = (new Tool)->as('read_thing')->for('Read')->using(fn (): string => 'r');
    $session = harness()->for(Participant::create(['name' => 'Ada']))->session('chat');

    expect(authorizer(enabled: false)->allowed($session, ['read_thing' => $tool])[0])->toBe($tool);
});
