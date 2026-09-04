<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use InvalidArgumentException;
use Prism\Harness\Contracts\HasErrorCode;

/**
 * Thrown when a task id or a worker id cannot identify one thing.
 *
 * BLANK MEANS EXACTLY THE EMPTY STRING, AND NOTHING IS TRIMMED FIRST. That is
 * deliberate, and it is the G-36 lesson written into an id check: `trim()`
 * strips a different set of codepoints in each of the three languages — PHP's
 * strips five ASCII characters, Python's strips every Unicode space including
 * U+00A0, JavaScript's adds the BOM — so `"\u{00A0}"` would be a valid worker
 * id in one language and a refused one in another, from the same input.
 *
 * The cost of not trimming is that `"worker-a "` is a DIFFERENT worker from
 * `"worker-a"`. That is the safe direction: an id that differs by a space
 * fails to extend its own lease, which is closed. Normalising to be forgiving
 * merges two distinct workers onto one claim, which is open.
 */
final class InvalidTaskIdentifier extends InvalidArgumentException implements HasErrorCode
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function duplicate(string $list, string $id): self
    {
        return new self(
            "The task list [{$list}] already holds a task with the id [{$id}]. Ids are unique within a "
            .'source, and a duplicate is refused rather than deduplicated: two tasks sharing an id means '
            .'one of them can never be claimed, released or found, while the caller believes both are queued.',
            'duplicate_task_id',
        );
    }

    public static function blank(string $what): self
    {
        return new self(
            "A task {$what} may not be the empty string. An id that names nothing is shared by everything "
            .'that failed to name itself, and every worker in that position would hold the same claim. '
            .'Note that nothing is trimmed first — a blank id here means exactly the empty string, because '
            .'trimming strips a different set of characters in every language.',
            'task_identifier_blank',
        );
    }

    #[\Override]
    public function code(): string
    {
        return $this->errorCode;
    }
}
