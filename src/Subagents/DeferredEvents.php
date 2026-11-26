<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;

/**
 * The one place the harness reads Laravel's event deferral state.
 *
 * Event::defer() holds events by class only, for the whole callback, and
 * dispatches them all in one loop when it returns. Resolving a resumed run's
 * approvals needs finer control: hold only the resolver's own ToolInvoked,
 * let the approved tool's body run under whatever deferral the host had
 * before, and send held events where core would have sent them. That needs
 * the dispatcher's protected deferral fields, read and written here through a
 * scope-bound closure. When the dispatcher is not Laravel's, or a framework
 * release renames the fields, dispatcher() returns null and every caller
 * falls back to plain Event::defer() behaviour. A test fails if the fields go
 * away (ResolutionTraceTest).
 *
 * @internal
 */
final class DeferredEvents
{
    /** The Laravel dispatcher whose deferral state can be read, or null. */
    public static function dispatcher(): ?Dispatcher
    {
        $events = Event::getFacadeRoot();
        if ($events instanceof EventFake) {
            $events = $events->dispatcher;
        }
        if (! $events instanceof Dispatcher) {
            return null;
        }
        foreach (['deferredEvents', 'deferringEvents', 'eventsToDefer'] as $field) {
            if (! property_exists(Dispatcher::class, $field)) {
                return null;
            }
        }

        return $events;
    }

    /**
     * Whether the dispatcher is deferring, and which events (null: all).
     *
     * @return array{deferring: bool, events: list<string>|null}
     */
    public static function state(Dispatcher $events): array
    {
        $read = Closure::bind(static function (Dispatcher $events): array {
            $deferring = (bool) $events->deferringEvents;
            $only = $events->eventsToDefer;

            return ['deferring' => $deferring, 'events' => is_array($only) ? array_values(array_map('strval', $only)) : null];
        }, null, Dispatcher::class);

        return $read($events);
    }

    /**
     * Whether, in the given state, an event of this class is deferred.
     *
     * @param  array{deferring: bool, events: list<string>|null}  $state
     */
    public static function defers(array $state, string $event): bool
    {
        return $state['deferring'] && ($state['events'] === null || in_array($event, $state['events'], true));
    }

    /**
     * Empty the deferred list and return what it held (dispatch arguments).
     *
     * @return list<array<int, mixed>>
     */
    public static function take(Dispatcher $events): array
    {
        $take = Closure::bind(static function (Dispatcher $events): array {
            $deferred = array_values($events->deferredEvents);
            $events->deferredEvents = [];

            return $deferred;
        }, null, Dispatcher::class);

        return $take($events);
    }

    /** How many events the deferred list holds. */
    public static function count(Dispatcher $events): int
    {
        $count = Closure::bind(static fn (Dispatcher $events): int => count($events->deferredEvents), null, Dispatcher::class);

        return $count($events);
    }

    /**
     * Run $run under the given deferral state, with a list of its own; what it
     * defers is handed to $deferred (dispatch arguments), whether it returns or
     * throws. The dispatcher's state before the call is restored after it.
     *
     * @template TResult
     *
     * @param  array{deferring: bool, events: list<string>|null}  $state
     * @param  Closure(): TResult  $run
     * @param  list<array<int, mixed>>  $deferred
     *
     * @param-out list<array<int, mixed>> $deferred
     *
     * @return TResult
     */
    public static function under(Dispatcher $events, array $state, Closure $run, array &$deferred): mixed
    {
        $swap = Closure::bind(
            /**
             * @param  list<string>|null  $only
             * @param  list<array<int, mixed>>  $list
             * @return array{0: bool, 1: list<string>|null, 2: list<array<int, mixed>>}
             */
            static function (Dispatcher $events, bool $deferring, ?array $only, array $list): array {
                $before = [(bool) $events->deferringEvents, $events->eventsToDefer, array_values($events->deferredEvents)];
                $events->deferringEvents = $deferring;
                $events->eventsToDefer = $only;
                $events->deferredEvents = $list;

                return $before;
            }, null, Dispatcher::class);
        $before = $swap($events, $state['deferring'], $state['events'], []);
        try {
            return $run();
        } finally {
            $after = $swap($events, $before[0], $before[1], $before[2]);
            $deferred = [];
            foreach ($after[2] as $args) {
                if (is_array($args)) {
                    $deferred[] = array_values($args);
                }
            }
        }
    }

    /**
     * Insert $entries into the deferred list right after the first entry at
     * or after $from whose event satisfies $anchor; the anchor event, or null
     * when there is none.
     *
     * @param  Closure(mixed): bool  $anchor
     * @param  Closure(object): list<array<int, mixed>>  $entries  given the anchor event
     */
    public static function insertAfter(Dispatcher $events, int $from, Closure $anchor, Closure $entries): ?object
    {
        $insert = Closure::bind(static function (Dispatcher $events) use ($from, $anchor, $entries): ?object {
            $list = array_values($events->deferredEvents);
            foreach ($list as $index => $args) {
                $event = $args[0] ?? null;
                if ($index >= $from && is_object($event) && $anchor($event)) {
                    array_splice($list, $index + 1, 0, $entries($event));
                    $events->deferredEvents = $list;

                    return $event;
                }
            }

            return null;
        }, null, Dispatcher::class);

        return $insert($events);
    }
}
