<?php

declare(strict_types=1);

use Prism\Harness\Context\ContextProvider;
use Prism\Harness\Context\MemoryHooks;
use Prism\Harness\Context\TurnObserver;
use Prism\Harness\Sessions\Session;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\Participant;

function memoryContext(callable $context): ContextProvider
{
    return new class($context) implements ContextProvider
    {
        /** @var callable */
        private $context;

        public function __construct(callable $context)
        {
            $this->context = $context;
        }

        public function context(Session $session, string $prompt): string
        {
            return ($this->context)($session, $prompt);
        }
    };
}

function memoryObserver(callable $recorded): TurnObserver
{
    return new class($recorded) implements TurnObserver
    {
        /** @var callable */
        private $recorded;

        public function __construct(callable $recorded)
        {
            $this->recorded = $recorded;
        }

        public function recorded(Session $session, string $runId, array $messages): void
        {
            ($this->recorded)($session, $runId, $messages);
        }
    };
}

it('adds nothing when no hook is registered, as shipped', function (): void {
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    harness()->for(Participant::create(['name' => 'Ada']))->session('chat')->send('Hello');

    $fake->assertRequest(function (array $requests): void {
        $prompts = array_map(fn ($p): string => $p->content, $requests[0]->systemPrompts());
        expect(implode("\n", $prompts))->not->toContain('<memory');
    });
});

it('puts provider context into the system prompt, built for the calling session and prompt', function (): void {
    $seen = [];
    app(MemoryHooks::class)->provide(memoryContext(function (Session $session, string $prompt) use (&$seen): string {
        $seen[] = [$session->key(), $prompt];

        return '<memory>Ada prefers tea.</memory>';
    }));
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    $session = harness()->for(Participant::create(['name' => 'Ada']))->session('chat');
    $session->send('What do I like?');

    expect($seen)->toBe([[$session->key(), 'What do I like?']]);
    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->systemPrompts()[0]->content)->toContain('<memory>Ada prefers tea.</memory>');
    });
});

it('reaches the streaming path too, not only send()', function (): void {
    app(MemoryHooks::class)->provide(memoryContext(fn (): string => '<memory>streamed context</memory>'));
    $fake = Prism::fake([TextResponseFake::make()->withText('streamed')]);

    foreach (harness()->for(Participant::create(['name' => 'Ada']))->session('chat')->stream('Hi') as $event) {
        // drain
    }

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->systemPrompts()[0]->content)->toContain('<memory>streamed context</memory>');
    });
});

it('skips a provider that throws, and the turn still completes', function (): void {
    app(MemoryHooks::class)
        ->provide(memoryContext(fn (): string => throw new RuntimeException('memory store down')))
        ->provide(memoryContext(fn (): string => '<memory>still here</memory>'));
    $fake = Prism::fake([TextResponseFake::make()->withText('answered')]);

    $result = harness()->for(Participant::create(['name' => 'Ada']))->session('chat')->send('Hello');

    expect($result->text())->toBe('answered');
    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->systemPrompts()[0]->content)->toContain('<memory>still here</memory>');
    });
});

it('tells observers about each recorded turn, with the session, after the thread has it', function (): void {
    $calls = [];
    app(MemoryHooks::class)->observe(memoryObserver(function (Session $session, string $runId, array $messages) use (&$calls): void {
        // The thread already holds the turn when an observer is called.
        $stored = iterator_to_array($session->thread()->messages(), false);
        $calls[] = [$session->key(), $runId, array_map(fn ($m): string => $m::class, $messages), count($stored)];
    }));
    Prism::fake([
        TextResponseFake::make()->withText('Hello Ada')
            ->withMessages(collect([new UserMessage('Hello'), new AssistantMessage('Hello Ada')])),
    ]);

    $session = harness()->for(Participant::create(['name' => 'Ada']))->session('chat');
    $result = $session->send('Hello');

    expect($calls)->toHaveCount(1)
        ->and($calls[0][0])->toBe($session->key())
        ->and($calls[0][1])->toBe($result->runId)
        ->and($calls[0][2])->toBe([UserMessage::class, AssistantMessage::class])
        ->and($calls[0][3])->toBe(2);
});

it('keeps the turn when an observer throws', function (): void {
    app(MemoryHooks::class)->observe(memoryObserver(fn () => throw new RuntimeException('memory store down')));
    Prism::fake([
        TextResponseFake::make()->withText('Hello Ada')
            ->withMessages(collect([new UserMessage('Hello'), new AssistantMessage('Hello Ada')])),
    ]);

    $ada = Participant::create(['name' => 'Ada']);
    $result = harness()->for($ada)->session('chat')->send('Hello');

    expect($result->text())->toBe('Hello Ada')
        ->and(iterator_to_array(harness()->for($ada->fresh())->session('chat')->thread()->messages(), false))->toHaveCount(2);
});
