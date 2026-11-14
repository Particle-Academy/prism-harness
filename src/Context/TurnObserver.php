<?php

declare(strict_types=1);

namespace Prism\Harness\Context;

use Prism\Harness\Sessions\Session;
use Prism\Prism\Contracts\Message;

/**
 * Told about each batch of messages the moment a session records it: the
 * user turn, the assistant's reply, tool calls and their results.
 *
 * This is the seam long-term memory uses to write after a turn, with the
 * session in hand (participant, actor, agent namespace, thread) rather than a
 * key to look it up by. It runs AFTER the messages are durably recorded, so
 * an observer can never lose a turn the thread kept, and it cannot change
 * what was recorded.
 *
 * Tool results are other people's text. An observer that stores them must
 * keep them marked as such (prism-graph records them with trust "tool"), or
 * a fetched web page becomes something the agent believes.
 *
 * An observer that throws is reported and skipped: memory failing must not
 * fail a turn the conversation already holds.
 */
interface TurnObserver
{
    /** @param list<Message> $messages in the order they were recorded */
    public function recorded(Session $session, string $runId, array $messages): void;
}
