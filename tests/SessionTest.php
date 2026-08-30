<?php

declare(strict_types=1);

use Prism\Harness\Enums\Durability;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\Participant;

function harness(): PrismHarness
{
    return app(PrismHarness::class);
}

it('resolves a session rather than constructing one', function (): void {
    $ada = Participant::create(['name' => 'Ada']);

    harness()->for($ada)->session('support')->usingMode('plan');

    // A different Session instance — as a fresh worker would build — must see
    // the state the previous request wrote. Nothing is held between them.
    $rehydrated = harness()->for($ada->fresh())->session('support');

    expect($rehydrated)->toBeInstanceOf(Session::class)
        ->and($rehydrated->mode())->toBe('plan');
});

it('keeps scopes apart for the same participant', function (): void {
    $ada = Participant::create(['name' => 'Ada']);

    harness()->for($ada)->session('support')->usingMode('plan');
    harness()->for($ada)->session('coding')->usingMode('build');

    expect(harness()->for($ada)->session('support')->mode())->toBe('plan')
        ->and(harness()->for($ada)->session('coding')->mode())->toBe('build');
});

it('keeps participants apart within the same scope', function (): void {
    $ada = Participant::create(['name' => 'Ada']);
    $grace = Participant::create(['name' => 'Grace']);

    harness()->for($ada)->session('support')->usingMode('plan');

    expect(harness()->for($grace)->session('support')->mode())->toBeNull();
});

it('carries mode and model independently', function (): void {
    $ada = Participant::create(['name' => 'Ada']);

    harness()->for($ada)->session()->usingMode('plan')->usingModel('claude-sonnet-4-5');

    $session = harness()->for($ada)->session();

    expect($session->mode())->toBe('plan')
        ->and($session->model())->toBe('claude-sonnet-4-5');
});

it('persists the selected provider independently from the model', function (): void {
    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session()->usingProvider('openai')->usingModel('gpt-5');

    $session = harness()->for($ada)->session();
    expect($session->provider())->toBe('openai')->and($session->model())->toBe('gpt-5');
});

it('keeps capability attachment identifiers in the durable state slot', function (): void {
    $ada = Participant::create(['name' => 'Ada']);
    $session = harness()->for($ada)->session('capabilities');

    $session->usingCapability('browser', ['id' => 'browser_one', 'state' => 'open']);
    $session->forget();

    expect(harness()->for($ada->fresh())->session('capabilities')->capability('browser'))
        ->toBe(['id' => 'browser_one', 'state' => 'open']);
});

it('forgets one durable capability without disturbing another', function (): void {
    $ada = Participant::create(['name' => 'Ada']);
    $session = harness()->for($ada)->session('capabilities')
        ->usingCapability('browser', ['id' => 'browser_one'])
        ->usingCapability('human_plus', ['id' => 'surface_one']);

    $session->forgetCapability('browser');

    expect($session->capability('browser'))->toBeNull()
        ->and($session->capability('human_plus'))->toBe(['id' => 'surface_one']);
});

it('falls back to a default when ephemeral state is gone', function (): void {
    $ada = Participant::create(['name' => 'Ada']);

    harness()->for($ada)->session('support')->usingMode('plan')->forget();

    // Losing the ephemeral half must degrade, not corrupt.
    expect(harness()->for($ada)->session('support')->mode())->toBeNull();
});

it('keeps the conversation when ephemeral state is dropped', function (): void {
    $ada = Participant::create(['name' => 'Ada']);

    $session = harness()->for($ada)->session('support');
    $session->thread()->record([new UserMessage('Remember this.')]);
    $session->usingMode('plan')->forget();

    // The thread is durable by construction — Eloquent rows, not session
    // state — so a flushed ephemeral store cannot take the conversation.
    $thread = harness()->for($ada)->session('support')->thread();

    expect(iterator_to_array($thread->messages(), false))->toHaveCount(1)
        ->and(iterator_to_array($thread->messages(), false)[0]->text())->toBe('Remember this.');
});

it('binds the session thread to the same address', function (): void {
    $ada = Participant::create(['name' => 'Ada']);

    $support = harness()->for($ada)->session('support')->thread();
    $coding = harness()->for($ada)->session('coding')->thread();

    expect($support)->toBeInstanceOf(Thread::class)
        ->and($support->id)->not->toBe($coding->id)
        ->and($support->scope)->toBe('support');
});

it('holds the session while a run advances', function (): void {
    $ada = Participant::create(['name' => 'Ada']);
    $session = harness()->for($ada)->session('support');

    $ran = $session->lock(fn (Session $s): string => $s->usingMode('build')->mode() ?? '');

    expect($ran)->toBe('build')
        // Released afterwards, or the next request would deadlock on a session
        // whose worker finished normally.
        ->and($session->lock(fn (): bool => true))->toBeTrue();
});

it('refuses a second holder rather than letting both advance a run', function (): void {
    $ada = Participant::create(['name' => 'Ada']);
    $session = harness()->for($ada)->session('support');

    $result = $session->lock(function () use ($session): string {
        // A second worker arriving mid-run must be turned away, not allowed to
        // advance the same session concurrently.
        try {
            $session->lock(fn (): string => 'both ran', ttlSeconds: 10, waitSeconds: 0);

            return 'both ran';
        } catch (SessionLocked) {
            return 'refused';
        }
    });

    expect($result)->toBe('refused');
});

it('uses a key that does not leak the application namespace', function (): void {
    $ada = Participant::create(['name' => 'Ada']);
    $key = harness()->for($ada)->session('support')->key();

    expect($key)->toContain('support')
        ->and($key)->toContain((string) $ada->getKey())
        ->and($key)->not->toContain('Tests\\Fixtures')
        ->and($key)->not->toContain('\\');
});

it('reports the database store as durable', function (): void {
    expect(harness()->stores()->durable()->durability())->toBe(Durability::Durable)
        ->and(harness()->stores()->durable()->durability()->isDurable())->toBeTrue();
});
