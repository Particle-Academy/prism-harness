<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Prism\Harness\Exceptions\AddressLimitExceeded;
use Prism\Harness\Models\Thread;
use Prism\Harness\Participants\ParticipantAdapters;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\DatabaseAddressIndex;
use Prism\Harness\Sessions\SessionAddress;
use Prism\Harness\Sessions\SessionStoreManager;
use Prism\Harness\Tools\ToolAuthorizer;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

it('isolates named sessions threads tasks and capabilities without moving legacy addresses', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $harness = app(PrismHarness::class);
    $legacy = $harness->for($participant)->session('same:/雪');
    $support = $harness->forAgent('support')->for($participant)->session('same:/雪');
    $charlotte = $harness->forAgent('charlotte')->for($participant)->session('same:/雪');

    expect($legacy->key())->toBe('session:'.substr(sha1($participant->getMorphClass()), 0, 12).':'.$participant->getKey().':same:/雪')
        ->and($support->key())->not->toBe($legacy->key())
        ->and($support->key())->not->toBe($charlotte->key());
    $support->usingMode('reply')->usingCapability('private', ['secret' => 'synthetic']);
    $support->tasks()->add('support only', 'one');
    expect($charlotte->mode())->toBeNull()
        ->and($charlotte->capability('private'))->toBeNull()
        ->and($charlotte->tasks()->claim('worker'))->toBeNull()
        ->and($support->thread()->getKey())->not->toBe($charlotte->thread()->getKey())
        ->and($support->thread()->getKey())->not->toBe($legacy->thread()->getKey());
    expect($harness->forAgent('support')->for($participant->fresh())->session('same:/雪')->mode())->toBe('reply');
});

it('reads live threads without allocating rows state or sessions', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    expect(Thread::findLive($participant, 'absent', 'support'))->toBeNull()
        ->and(Thread::findShared('absent', 'support'))->toBeNull();
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query)))->toStartWith('select');
    }
    $thread = Thread::forParticipant($participant, 'known', 'support');
    expect(Thread::findLive($participant, 'known', 'support')?->getKey())->toBe($thread->getKey())
        ->and(Thread::findLive($participant, 'known', 'charlotte'))->toBeNull();
    $thread->retire();
    expect(Thread::findLive($participant, 'known', 'support'))->toBeNull();
});

it('refuses invalid namespace identifiers instead of normalizing or deriving a persona name', function (): void {
    foreach (['', 'Support', ' support', 'support ', '../support', 'a/b', 'support:evil', str_repeat('a', 65), 'sυpport'] as $agent) {
        expect(fn () => app(PrismHarness::class)->forAgent($agent))->toThrow(InvalidArgumentException::class);
    }
});

it('keeps typed identities out of morph resolution and requires an explicit actor adapter', function (): void {
    $participant = new ParticipantRef('automation', 'bot/雪');
    expect(fn () => app(PrismHarness::class)->forAgent('support')->for($participant)->session('scope'))
        ->toThrow(LogicException::class, 'actor adapter');
    $actor = Participant::create(['name' => 'service actor']);
    app(ParticipantAdapters::class)->register('automation', fn (ParticipantRef $ref): object => $actor);
    $session = app(PrismHarness::class)->forAgent('support')->for($participant)->session('scope');
    $thread = $session->thread();
    expect($thread->participant_type)->toBeNull()
        ->and($thread->participant_id)->toBeNull()
        ->and($thread->participant_ref_type)->toBe('automation')
        ->and($thread->participant_ref_id)->toBe('bot/雪')
        ->and($thread->participant)->toBeNull()
        ->and($session->actor())->toBe($actor);
    Gate::define(ToolAuthorizer::ABILITY, function ($user, $boundSession, $tool) use ($actor, $participant): bool {
        return $user === $actor && $boundSession->participant() === $participant;
    });
    $authorizer = new ToolAuthorizer(app(GateContract::class), true);
    expect($authorizer->allowed($session, ['safe' => (new Tool)->as('safe')]))->toHaveCount(1);
});

it('binds a legacy model authorization actor without changing conversation ownership', function (): void {
    $participant = Participant::create(['name' => 'bot model']);
    $actor = Participant::create(['name' => 'service actor']);
    $harness = app(PrismHarness::class);
    $original = $harness->for($participant)->session('scope');
    $bound = $harness->for($participant)->asActor($actor)->session('scope');
    expect($bound->actor())->toBe($actor)
        ->and($bound->participant())->toBe($participant)
        ->and($bound->key())->toBe($original->key())
        ->and($bound->thread()->getKey())->toBe($original->thread()->getKey());
});

it('inherits a typed participants bound actor when a child session is constructed', function (): void {
    $participant = new ParticipantRef('automation', 'child-owner');
    $actor = Participant::create(['name' => 'bound actor']);
    $other = Participant::create(['name' => 'later actor']);
    $resolutions = 0;
    $harness = app(PrismHarness::class)->forAgent('support');
    expect(fn () => $harness->session($participant, 'missing', $actor))->toThrow(LogicException::class, 'actor adapter');
    app(ParticipantAdapters::class)->register('automation', function () use (&$resolutions, $actor, $other): object {
        return ++$resolutions === 1 ? $actor : $other;
    });
    $parent = $harness->for($participant)->session('parent');
    $child = $harness->session($parent->participant(), 'parent::sub::worker', $parent->actor());
    expect($child->actor())->toBe($actor)
        ->and($child->participant())->toBe($participant)
        ->and($resolutions)->toBe(1);
});

it('indexes writes and every retired generation while read-only resolution remains empty', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $session = app(PrismHarness::class)->forAgent('support')->for($participant)->session('scope');
    expect(DB::table('harness_addresses')->count())->toBe(0);
    $session->usingMode('reply')->usingCapability('one', ['value' => true]);
    $session->tasks()->add('work', 'one');
    $first = $session->thread();
    $second = $session->newConversation();
    $key = $session->address()->indexKey();
    expect(DB::table('harness_addresses')->count())->toBe(1)
        ->and(DB::table('harness_threads')->where('address_key', $key)->count())->toBe(2)
        ->and($first->fresh()->address_key)->toBe($second->address_key)
        ->and(DB::table('harness_address_resources')->where('address_key', $key)->where('kind', 'tasks')->count())->toBe(1);
    $index = new DatabaseAddressIndex($first->getConnection());
    expect($index->page($participant->getMorphClass(), (string) $participant->getKey(), limit: 1)['entries'])->toHaveCount(1)
        ->and($index->page($participant->getMorphClass(), (string) $participant->getKey(), cursor: 1)['entries'])->toBe([]);
    expect(fn () => $index->page(null, null, limit: 101))->toThrow(InvalidArgumentException::class);
    $second->scope = 'moved';
    expect(fn () => $second->save())->toThrow(LogicException::class, 'immutable');
});

it('keeps legacy task lists on their resolved store without requiring a thread database', function (): void {
    app(SessionStoreManager::class)->durable();
    $harness = app(PrismHarness::class);
    $resolver = Thread::getConnectionResolver();
    $blocked = Mockery::mock(ConnectionResolverInterface::class);
    $blocked->shouldReceive('connection')->andThrow(new RuntimeException('Synthetic thread database unavailable'));
    Thread::setConnectionResolver($blocked);
    try {
        $tasks = $harness->tasks('independent');
        $tasks->add('work on the already resolved store', 'one');
        expect($tasks->find('one')?->id)->toBe('one');
        $blocked->shouldNotHaveReceived('connection');
        expect(fn () => $harness->forAgent('support')->tasks('indexed'))
            ->toThrow(RuntimeException::class, 'Synthetic thread database unavailable');
    } finally {
        Thread::setConnectionResolver($resolver);
    }
});

it('qualifies named application task lists while retaining the intentional legacy shared list', function (): void {
    $harness = app(PrismHarness::class);
    $harness->forAgent('support')->tasks('shared-name')->add('support only', 'one');
    expect($harness->forAgent('charlotte')->tasks('shared-name')->claim('worker'))->toBeNull()
        ->and($harness->tasks('shared-name')->claim('worker'))->toBeNull()
        ->and($harness->forAgent('support')->tasks('shared-name')->claim('worker')?->id)->toBe('one')
        ->and($harness->tasks('shared-name')->key())->toBe('shared-name:tasks');
});

it('executes every shared address vector against an independently authored expectation', function (): void {
    $cases = json_decode(file_get_contents(__DIR__.'/Fixtures/harness-agent-addresses.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
    expect($cases)->toHaveCount(46);
    foreach ($cases as $case) {
        $input = $case['input'];
        try {
            $address = new SessionAddress($input['participant']['type'] ?? null,
                $input['participant'] === null ? null : (string) $input['participant']['id'], $input['scope'], $input['agent']);
            $result = ['session_key' => $input['participant'] === null ? null : $address->sessionKey(), 'thread_key' => $address->threadKey(), 'index_key' => $address->indexKey()];
        } catch (InvalidArgumentException) {
            $result = ['error' => 'invalid_address'];
        }
        expect($result)->toBe($case['expected'], $case['id']);
    }
});

it('refuses resource writes if ownership indexing fails', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $session = app(PrismHarness::class)->forAgent('support')->for($participant)->session('s');
    $armed = true;
    DB::listen(function ($query) use (&$armed): void {
        if ($armed && str_contains($query->sql, 'insert') && str_contains($query->sql, 'harness_addresses')) {
            $armed = false;
            throw new RuntimeException('Synthetic index failure');
        }
    });
    expect(fn () => $session->usingMode('reply'))->toThrow(RuntimeException::class, 'Synthetic index failure')
        ->and(DB::table('harness_session_state')->where('key', $session->key().':ephemeral')->exists())->toBeFalse();
});

it('pages beyond one hundred addresses and enforces an optional quota before writing state', function (): void {
    $participant = Participant::create(['name' => 'Ada']);
    $harness = app(PrismHarness::class)->forAgent('support');
    for ($i = 0; $i < 101; $i++) {
        $harness->for($participant)->session('scope:'.$i)->usingMode('reply');
    }
    $index = DatabaseAddressIndex::configured((new Thread)->getConnection());
    $first = $index->page($participant->getMorphClass(), (string) $participant->getKey());
    $second = $index->page($participant->getMorphClass(), (string) $participant->getKey(), $first['next_cursor']);
    expect($first['entries'])->toHaveCount(100)
        ->and($first['next_cursor'])->toBe(100)
        ->and($second['entries'])->toHaveCount(1)
        ->and($second['next_cursor'])->toBeNull();
    config()->set('prism-harness.addresses.max_addresses_per_participant', 101);
    $extra = $harness->for($participant)->session('scope:extra');
    try {
        $extra->usingMode('refused');
        $this->fail('Configured address quota did not refuse.');
    } catch (AddressLimitExceeded $error) {
        expect($error->errorCode)->toBe('address_limit_exceeded');
    }
    expect($extra->mode())->toBeNull();
    $harness->for($participant)->session('scope:0')->usingMode('still usable');
    expect($harness->for($participant)->session('scope:0')->mode())->toBe('still usable')
        ->and((require __DIR__.'/../config/prism-harness.php')['addresses']['max_addresses_per_participant'])->toBeNull();
});
