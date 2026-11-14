<?php

declare(strict_types=1);

namespace Prism\Harness\Context;

use Prism\Harness\Sessions\Session;
use Prism\Prism\Contracts\Message;
use Throwable;

/**
 * The registered context providers and turn observers, in registration order.
 * Bound as a singleton; register from a service provider:
 *
 *   app(MemoryHooks::class)->provide(new MyContext)->observe(new MyRecorder);
 *
 * Both hooks are failure-isolated here, in one place, so every call site in
 * the runtime gets the same rule: a broken hook degrades the turn, it never
 * takes it down, and it is reported rather than swallowed.
 */
final class MemoryHooks
{
    /** @var list<ContextProvider> */
    private array $providers = [];

    /** @var list<TurnObserver> */
    private array $observers = [];

    public function provide(ContextProvider $provider): self
    {
        $this->providers[] = $provider;

        return $this;
    }

    public function observe(TurnObserver $observer): self
    {
        $this->observers[] = $observer;

        return $this;
    }

    /** The system prompt with every provider's context appended, separated by blank lines. */
    public function systemPrompt(string $base, Session $session, string $prompt): string
    {
        $parts = $base === '' ? [] : [$base];
        foreach ($this->providers as $provider) {
            try {
                $context = $provider->context($session, $prompt);
            } catch (Throwable $failure) {
                report($failure);

                continue;
            }
            if (trim($context) !== '') {
                $parts[] = $context;
            }
        }

        return implode("\n\n", $parts);
    }

    /** @param iterable<Message> $messages */
    public function recorded(Session $session, string $runId, iterable $messages): void
    {
        if ($this->observers === []) {
            return;
        }
        $messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        if ($messages === []) {
            return;
        }
        foreach ($this->observers as $observer) {
            try {
                $observer->recorded($session, $runId, $messages);
            } catch (Throwable $failure) {
                report($failure);
            }
        }
    }
}
