<?php

declare(strict_types=1);

use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\Participant;

it('runs through the durable session and records the complete exchange', function (): void {
    Prism::fake([
        TextResponseFake::make()
            ->withText('Hello Ada')
            ->withMessages(collect([new UserMessage('Hello'), new AssistantMessage('Hello Ada')])),
    ]);
    $ada = Participant::create(['name' => 'Ada']);

    $result = harness()->for($ada)->session('chat')->send('Hello');
    $rehydrated = harness()->for($ada->fresh())->session('chat');

    expect($result->text())->toBe('Hello Ada')
        ->and($result->runId)->toStartWith('run_')
        ->and($rehydrated->run()['status'])->toBe('completed')
        ->and($rehydrated->run()['finish_reason'])->toBe('stop')
        ->and(iterator_to_array($rehydrated->thread()->messages(), false))->toHaveCount(2);
});

it('offers only tools named by the active mode', function (): void {
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('allowed_tool')->for('Allowed')->using(fn (): string => 'yes'),
        (new Tool)->as('hidden_tool')->for('Hidden')->using(fn (): string => 'no'),
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', ['allowed_tool']);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->send('Use a tool');
    $fake->assertRequest(function (array $requests): void {
        expect(array_map(fn (Tool $tool): string => $tool->name(), $requests[0]->tools()))->toBe(['allowed_tool']);
    });
});

it('offers Harness skills without copying them into an agent workspace', function (): void {
    config()->set('prism-harness.agent.modes.chat.skills', ['remotion']);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->send('Create a video');

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->systemPrompts()[0]->content)->toContain('<skill name="remotion">')
            ->and(array_map(fn (Tool $tool): string => $tool->name(), $requests[0]->tools()))->toContain('skill_read');
    });
});

it('resolves session-bound tool factories against the locked owner session', function (): void {
    app(ToolRegistry::class)->registerFactory('session_key', fn ($session): Tool => (new Tool)
        ->as('session_key')->for('Reports the owning session key.')
        ->using(fn (): string => $session->key()));
    config()->set('prism-harness.agent.modes.chat.tools', ['session_key']);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    $session = harness()->for($ada)->session('chat');
    $session->send('Identify this session');

    $fake->assertRequest(function (array $requests) use ($session): void {
        $tool = $requests[0]->tools()[0];
        expect($tool->name())->toBe('session_key')
            ->and($tool->handle([]))->toBe($session->key());
    });
});

it('discovers dynamic capability tools from the locked session', function (): void {
    app(ToolRegistry::class)->registerProvider(fn ($session): array => $session->capability('surface') === null ? [] : [
        (new Tool)->as('surface_read')->for('Read the attached surface.')->using(fn (): string => 'surface'),
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', ['*']);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);
    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->usingCapability('surface', ['id' => 'surface_one'])->send('Read it');

    $fake->assertRequest(function (array $requests): void {
        expect(array_map(fn (Tool $tool): string => $tool->name(), $requests[0]->tools()))->toContain('surface_read');
    });
});

it('persists a failed run without storing exception prose', function (): void {
    $fake = Prism::fake([TextResponseFake::make()->withText('consumed')]);
    Prism::text()->using('anthropic', 'test')->withPrompt('consume fixture')->asText();
    $ada = Participant::create(['name' => 'Ada']);
    $session = harness()->for($ada)->session('chat');

    expect(fn () => $session->send('This fails'))->toThrow(Exception::class)
        ->and($session->run()['status'])->toBe('failed')
        ->and($session->run()['failure'])->toBeString()
        ->and($session->run())->not->toHaveKey('message');
});

it('lets a caller raise the step ceiling above the mode default', function (): void {
    // A mode's `max_steps` is a sensible DEFAULT, not a fact about every task.
    // A caller holding a frozen, approved budget knows the size of the job
    // better than the config does — and when the two disagree the agent is told
    // one number and cut off at another, which discards work rather than
    // bounding it. Measured in the Lab: an agent told `max_turns: 20` was
    // truncated at the mode's 10, mid-build.
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('allowed_tool')->for('Allowed')->using(fn (): string => 'yes'),
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', ['allowed_tool']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 10);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->usingMaxSteps(20)->send('Build something large');

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->maxSteps())->toBe(20);
    });
});

it('never lets a caller raise the step ceiling past the operator ceiling', function (): void {
    // The other half, and the reason the override is bounded at all: a limit
    // the thing being limited can raise without bound is a limit in name only.
    // Same argument RunBudget::nestedWithin() makes for subagents.
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('allowed_tool')->for('Allowed')->using(fn (): string => 'yes'),
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', ['allowed_tool']);
    config()->set('prism-harness.agent.max_steps_ceiling', 12);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->usingMaxSteps(500)->send('Ask for the moon');

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->maxSteps())->toBe(12);
    });
});

it('uses the mode default when the caller asks for nothing', function (): void {
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('allowed_tool')->for('Allowed')->using(fn (): string => 'yes'),
    ]);
    config()->set('prism-harness.agent.modes.chat.tools', ['allowed_tool']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 7);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->send('Ordinary work');

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->maxSteps())->toBe(7);
    });
});
