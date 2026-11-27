<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Prism\Prism\Contracts\Message;
use WeakMap;

/**
 * The messages the CURRENT turn adds to what it sends (its prompt), as
 * opposed to rows already stored in the thread (G-397). Choosing the paused
 * step over what a turn sends, the turn's own prompt is not a row written
 * after the park: a new message still continues the step it follows. A user
 * row stored after the park is, and ends it. In memory only, by identity.
 *
 * @internal
 */
final class TurnMessages
{
    /** @var WeakMap<Message, true>|null */
    private static ?WeakMap $turn = null;

    /**
     * @template T of Message
     *
     * @param  T  $message
     * @return T
     */
    public static function of(Message $message): Message
    {
        self::$turn ??= new WeakMap;
        self::$turn[$message] = true;

        return $message;
    }

    public static function isTurn(Message $message): bool
    {
        return self::$turn !== null && isset(self::$turn[$message]);
    }
}
