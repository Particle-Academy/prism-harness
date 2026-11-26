<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Closure;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;
use Prism\Harness\Exceptions\UnsupportedEventDispatcher;

/**
 * The one place the harness reads and writes Laravel's event deferral state.
 *
 * Event::defer() holds events by class only, for the whole callback, and
 * dispatches them all in one loop when it returns. Resolving a resumed run's
 * approvals needs finer control: hold only the resolver's own ToolInvoked
 * until the result is recorded, let the approved tool's body run under
 * whatever deferral the host had before, and put held events where core
 * would have put them on 0.26.7. That needs the dispatcher's protected
 * deferral fields, read and written here through scope-bound closures.
 *
 * There is no fallback. If the dispatcher is not Laravel's (or an EventFake
 * over Laravel's), or a framework release renames the fields, dispatcher()
 * throws UnsupportedEventDispatcher: carrying on without them would give up
 * the guarantee that no listener runs between an approved call and its
 * record. ResolutionTraceTest pins the fields on every framework version CI
 * installs.
 *
 * @internal
 */
final class DeferredEvents
{
    /** The fields this class reads and writes, on Illuminate\Events\Dispatcher. */
    public const FIELDS = ['deferredEvents', 'deferringEvents', 'eventsToDefer'];

    /** The Laravel dispatcher whose deferral state can be read and written. */
    public static function dispatcher(): Dispatcher
    {
        $events = Event::getFacadeRoot();
        if ($events instanceof EventFake) {
            $events = $events->dispatcher;
        }
        if (! $events instanceof Dispatcher) {
            throw UnsupportedEventDispatcher::notLaravel(get_debug_type($events));
        }
        self::assertSupports($events::class);

        return $events;
    }

    /**
     * Refuse a dispatcher class that lacks any of the fields.
     *
     * @param  class-string  $class
     */
    public static function assertSupports(string $class): void
    {
        foreach (self::FIELDS as $field) {
            if (! property_exists($class, $field)) {
                throw UnsupportedEventDispatcher::missingField($class, $field);
            }
        }
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
     * The deferred list (dispatch arguments), in order.
     *
     * @return list<array<int, mixed>>
     */
    public static function list(Dispatcher $events): array
    {
        $read = Closure::bind(static fn (Dispatcher $events): array => array_values($events->deferredEvents), null, Dispatcher::class);
        $list = [];
        foreach ($read($events) as $args) {
            if (is_array($args)) {
                $list[] = array_values($args);
            }
        }

        return $list;
    }

    /**
     * Replace the deferred list.
     *
     * @param  list<array<int, mixed>>  $list
     */
    public static function replace(Dispatcher $events, array $list): void
    {
        $write = Closure::bind(static function (Dispatcher $events, array $list): void {
            $events->deferredEvents = $list;
        }, null, Dispatcher::class);
        $write($events, $list);
    }

    /**
     * Empty the deferred list and return what it held.
     *
     * @return list<array<int, mixed>>
     */
    public static function take(Dispatcher $events): array
    {
        $list = self::list($events);
        self::replace($events, []);

        return $list;
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
        $swap = Closure::bind(static function (Dispatcher $events, bool $deferring, ?array $only): array {
            $before = [(bool) $events->deferringEvents, $events->eventsToDefer];
            $events->deferringEvents = $deferring;
            $events->eventsToDefer = $only;

            return $before;
        }, null, Dispatcher::class);
        $list = self::list($events);
        self::replace($events, []);
        $before = $swap($events, $state['deferring'], $state['events']);
        try {
            return $run();
        } finally {
            $swap($events, $before[0], is_array($before[1]) ? array_values(array_map('strval', $before[1])) : null);
            $deferred = self::list($events);
            self::replace($events, $list);
        }
    }
}
