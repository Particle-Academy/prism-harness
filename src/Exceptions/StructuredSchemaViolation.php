<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Prism\Harness\Contracts\HasErrorCode;
use RuntimeException;

/**
 * A structured turn came back with a document the caller cannot use.
 *
 * Two ways that happens, and they are separate codes because the caller's next
 * move differs: text that is not a document at all, and a document that reads
 * fine and breaks its contract.
 *
 * THE DOCUMENT TRAVELS ON THE EXCEPTION. The first question anyone asks is
 * "what did it actually say", and an exception that answers it turns a support
 * thread into a log line. It is the model's output, so treat it as such: it
 * belongs in a log or a diagnostic screen, and in whatever your logger already
 * redacts.
 *
 * NEITHER CASE IS AN EMPTY SUCCESS. A document that fails its contract arriving
 * as `[]` reads exactly like a considered answer — the planning agent that
 * proposed nothing rather than the one whose answer was unreadable — and
 * settles the work as done. The refusal is the whole feature.
 */
final class StructuredSchemaViolation extends RuntimeException implements HasErrorCode
{
    /**
     * @param  list<string>  $problems
     */
    private function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly string $document,
        private readonly array $problems = [],
    ) {
        parent::__construct($message);
    }

    /**
     * The model returned no readable document.
     *
     * Prism parses the provider's text into `structured` and leaves it null
     * when there was nothing to parse — an apology in prose, a fenced block
     * that never closed, a truncated response.
     */
    public static function unreadable(string $document): self
    {
        return new self(
            'The model returned no readable document for a structured turn. Its text is on this '
            .'exception as document().',
            'structured_unreadable',
            $document,
        );
    }

    /**
     * Valid JSON, wrong shape.
     *
     * @param  list<string>  $problems
     */
    public static function failsSchema(string $document, array $problems): self
    {
        return new self(
            'The model returned a document that does not satisfy the schema: '.implode(' ', $problems),
            'structured_schema_violation',
            $document,
            $problems,
        );
    }

    /** The raw text the model returned, exactly as it arrived. */
    public function document(): string
    {
        return $this->document;
    }

    /**
     * Every way the document missed the schema, rather than only the first.
     *
     * A model that drops one required field usually drops several, and fixing
     * them one exception at a time costs a provider call each.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    #[\Override]
    public function code(): string
    {
        return $this->errorCode;
    }
}
