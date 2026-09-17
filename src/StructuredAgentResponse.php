<?php

declare(strict_types=1);

namespace Prism\Harness;

use Prism\Harness\Exceptions\StructuredSchemaViolation;
use Prism\Prism\Structured\Response;

/**
 * One structured run's result: the document, and the text it was read from.
 *
 * BOTH, not one. `structured()` is the object the caller asked for, and
 * `text()` is what the model actually sent — which is also exactly what the
 * thread stored, so a transcript and a parse can be compared rather than
 * trusted. When a later turn reads the conversation back, it reads the text.
 *
 * Separate from {@see AgentResponse} because the runs differ in what they can
 * end as. A text run can stop awaiting a human's approval; a structured run
 * returns a document or raises, so there is no pending state to ask about here
 * and no method offering one.
 */
final readonly class StructuredAgentResponse
{
    public function __construct(
        public string $runId,
        public Response $response,
        /** Null for a root run — this run was not called by another. */
        public ?string $parentRunId = null,
        /** The run at the top of the tree. Equals `$runId` for a root. */
        public ?string $rootRunId = null,
    ) {}

    /**
     * The parsed document, checked against the schema before it got here.
     *
     * Never null: a run that could not produce one raised
     * {@see StructuredSchemaViolation} instead of
     * returning this object.
     *
     * @return array<mixed>
     */
    public function structured(): array
    {
        return $this->response->structured ?? [];
    }

    /** The document as the model wrote it, which is what the thread holds. */
    public function text(): string
    {
        return $this->response->text;
    }

    /**
     * The identifiers a consuming app joins its stream on.
     *
     * @return array{run_id: string, parent_run_id: string|null, root_run_id: string}
     */
    public function correlation(): array
    {
        return [
            'run_id' => $this->runId,
            'parent_run_id' => $this->parentRunId,
            'root_run_id' => $this->rootRunId ?? $this->runId,
        ];
    }

    public function isChildRun(): bool
    {
        return $this->parentRunId !== null;
    }
}
