<?php

declare(strict_types=1);

namespace Prism\Harness\Support;

use Closure;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;

/**
 * THE step a decision can apply to (G-397): the one rule every reader that
 * chooses "the paused step" uses, so the repeated-id guard, the resolver that
 * acts on decisions, the decision checks and the plan readers can never pick
 * different steps.
 *
 * Read newest first: result rows (results, decisions) and system rows are
 * skipped; the first other row must be an assistant step WITH tool calls, and
 * that step is the paused one. Anything else first (an assistant answer, a
 * user turn, any row of a kind this does not know) means nothing is paused:
 * the conversation moved on, and no decision applies to any earlier step.
 *
 * @internal
 */
final class PausedStep
{
    public const RESULT = 'result';

    public const STEP = 'step';

    public const OTHER = 'other';

    /** The kind of a stored row, from its type and payload. */
    public static function kindOfRow(string $type, mixed $payload): string
    {
        // A system row never ends a step, as a result row never does (the
        // release lines' rule): a decision still applies to the step.
        if ($type === MessageMapper::TYPE_TOOL_RESULT || $type === MessageMapper::TYPE_SYSTEM) {
            return self::RESULT;
        }

        // A step only when its calls are a non-empty list of objects (G-408),
        // the rule MessageMapper reads calls by: a copy whose calls are a
        // string, a number, null or a list of strings is not a step.
        return StepRows::isStep($type, $payload) ? self::STEP : self::OTHER;
    }

    /**
     * The kind of a message. The current turn's own prompt is not a row
     * written after the park ({@see TurnMessages}): it is passed over, as a
     * result row is.
     */
    public static function kindOfMessage(Message $message): string
    {
        if ($message instanceof ToolResultMessage || $message instanceof SystemMessage || TurnMessages::isTurn($message)) {
            return self::RESULT;
        }

        return $message instanceof AssistantMessage && $message->toolCalls !== [] ? self::STEP : self::OTHER;
    }

    /**
     * The paused step among `$newestFirst`, and the result rows after it
     * (oldest first), or null when nothing is paused.
     *
     * @template T
     *
     * @param  iterable<T>  $newestFirst
     * @param  Closure(T): string  $kindOf
     * @return array{step: T, results: list<T>}|null
     */
    public static function find(iterable $newestFirst, Closure $kindOf): ?array
    {
        $results = [];
        foreach ($newestFirst as $row) {
            $kind = $kindOf($row);
            if ($kind === self::RESULT) {
                array_unshift($results, $row);

                continue;
            }

            return $kind === self::STEP ? ['step' => $row, 'results' => $results] : null;
        }

        return null;
    }

    /**
     * The index of the paused step in `$messages` (oldest first), or null.
     *
     * @param  list<Message>  $messages
     */
    public static function indexIn(array $messages): ?int
    {
        $found = self::find(array_reverse(array_keys($messages)), static fn (int $i): string => self::kindOfMessage($messages[$i]));

        return $found['step'] ?? null;
    }
}
