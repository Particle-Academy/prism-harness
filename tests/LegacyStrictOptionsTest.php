<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Prism\Harness\Exceptions\RegistrationCollision;
use Prism\Harness\Models\Thread;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

it('keeps legacy duplicate refusal opt in and named refusal unconditional', function (): void {
    foreach ([new ToolRegistry(refuseDuplicates: true), (new ToolRegistry)->forAgent('support')] as $registry) {
        $owner = 'app';
        $tool = (new Tool)->as('lookup');
        $registry->register($tool, $owner);
        expect(fn () => $registry->register($tool, $owner))->toThrow(RegistrationCollision::class)
            ->and(fn () => $registry->registerFactory('lookup', fn (): Tool => $tool, $owner))->toThrow(RegistrationCollision::class);
        $session = app(PrismHarness::class)->forAgent($registry->forAgent(null) === $registry ? null : 'support')
            ->for(Participant::create(['name' => 'Ada']))->session('same');
        $registry->registerProvider(fn (): array => [$tool], $owner, 'catalog');
        expect(fn () => $registry->resolve(['*'], $session))->toThrow(RegistrationCollision::class);
    }
});

it('wires the explicit registry option into container registries', function (): void {
    config()->set('prism-harness.registry.refuse_duplicates', true);
    app()->forgetInstance(ToolRegistry::class);
    app()->forgetInstance(ModeRegistry::class);
    $tools = app(ToolRegistry::class);
    $tool = (new Tool)->as('lookup');
    $tools->register($tool);
    expect(fn () => $tools->register($tool))->toThrow(RegistrationCollision::class);
    expect(fn () => app(ModeRegistry::class)->register('chat', ['tools' => []]))->toThrow(RegistrationCollision::class);
});

it('makes legacy thread immutability opt in while preserving named and typed address guards', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $legacy = Thread::forParticipant($participant, 'legacy');
    $legacy->scope = 'edited';
    $legacy->save();
    expect(Thread::findLive($participant, 'edited')?->getKey())->toBe($legacy->getKey());
    config()->set('prism-harness.addresses.immutable_legacy_threads', true);
    $legacy->scope = 'refused';
    expect(fn () => $legacy->save())->toThrow(LogicException::class, 'immutable');
    config()->set('prism-harness.addresses.immutable_legacy_threads', false);
    foreach ([Thread::forParticipant($participant, 'named', 'support'), Thread::forParticipant(new ParticipantRef('external', '1'), 'typed')] as $thread) {
        $thread->scope = 'refused';
        expect(fn () => $thread->save())->toThrow(LogicException::class, 'immutable');
    }
});

it('names the required migration when a new address feature is used without it', function (): void {
    $connection = DB::connection();
    $schema = $connection->getSchemaBuilder();
    // This owned in-memory transaction is rolled back by RefreshDatabase.
    (require __DIR__.'/../database/migrations/0001_01_01_000006_add_agent_addresses.php')->down();
    expect($schema->hasTable('harness_addresses'))->toBeFalse()
        ->and($schema->hasColumn('harness_threads', 'agent_id'))->toBeFalse();
    $participant = Participant::create(['name' => 'Ada']);
    expect(fn () => Thread::forParticipant($participant, 'named', 'support'))->toThrow(LogicException::class, '0001_01_01_000006_add_agent_addresses')
        ->and(fn () => Thread::forParticipant(new ParticipantRef('external', '1'), 'typed'))->toThrow(LogicException::class, '0001_01_01_000006_add_agent_addresses')
        ->and(fn () => app(PrismHarness::class)->forAgent('support')->for($participant)->session('state')->usingMode('chat'))->toThrow(LogicException::class, '0001_01_01_000006_add_agent_addresses');
});
