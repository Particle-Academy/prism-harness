<?php

declare(strict_types=1);

namespace Prism\Harness\Context;

use Prism\Harness\Sessions\Session;

/**
 * Contributes text to the system prompt of every model call a session makes.
 *
 * This is the seam long-term memory uses to wake an agent up with what it
 * already knows (prism-graph's Wake), and it is deliberately general: anything
 * that must reach the model as context, for this session, at call time.
 *
 * RULES A PROVIDER MUST KEEP, because the harness cannot check them for you:
 *  - Return DATA, fenced as data, never instructions you would not let the
 *    user type. What you return sits in the system prompt, which outranks the
 *    conversation.
 *  - Be fast and bounded. This runs before every call, inside the session's
 *    lock; a slow provider is a slow turn.
 *  - Scope to the session you are given. It carries the participant, actor,
 *    agent namespace and thread; never read another session's memory.
 *
 * A provider that throws is reported and skipped: the turn goes ahead without
 * its context rather than not at all.
 */
interface ContextProvider
{
    /** Text to append to the system prompt, or '' for none. $prompt is the new user turn ('' on approval resumes). */
    public function context(Session $session, string $prompt): string;
}
