<?php

declare(strict_types=1);

namespace Prism\Harness\Steps;

/**
 * One observed moment of a run, tagged with the run it belongs to (G-282 A).
 *
 * The shape is an AGREED CONTRACT with Fancy's agent chat, versioned as
 * `contractVersion` so a consumer refuses a version it does not know rather
 * than mis-rendering it. Renaming a field, or adding one, is a contract change
 * and bumps the version; it is not a refactor.
 *
 * Everything core streams passes through untouched on `stream()`; this is the
 * tagged view of the same run, for a reader that needs to know WHICH run, in
 * WHAT order, and how long each tool took where it actually ran.
 */
final readonly class RunStep
{
    public const CONTRACT_VERSION = 'g282.1';

    public const THINKING = 'thinking';

    public const TEXT = 'text';

    public const TOOL_CALL = 'tool_call';

    public const TOOL_RESULT = 'tool_result';

    public const APPROVAL_REQUEST = 'approval_request';

    public const ERROR = 'error';

    public const STEP_START = 'step_start';

    public const STEP_FINISH = 'step_finish';

    public const DECISION_RECORDED = 'decision_recorded';

    /** G-360: the session's plan changed. Contract `g282.3`; off unless `plans.events.run_steps`. */
    public const PLAN_UPDATED = 'plan_updated';

    /**
     * @param  array<string, mixed>  $data  the per-type fields, in contract order
     */
    public function __construct(
        public string $runId,
        public ?string $parentRunId,
        public string $rootRunId,
        public ?string $turnId,
        public int $seq,
        public string $at,
        public string $type,
        public array $data = [],
        /**
         * Every step type keeps `g282.1`. Only `plan_updated` (G-360) carries
         * `g282.3`, and it is emitted only when the host turned on
         * `plans.events.run_steps`, so a consumer that knows g282.1 alone
         * never sees it.
         */
        public string $contractVersion = self::CONTRACT_VERSION,
    ) {}

    /**
     * The wire shape: camelCase, the envelope first, then the type's fields.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contractVersion' => $this->contractVersion,
            'runId' => $this->runId,
            'parentRunId' => $this->parentRunId,
            'rootRunId' => $this->rootRunId,
            'turnId' => $this->turnId,
            'seq' => $this->seq,
            'at' => $this->at,
            'type' => $this->type,
            ...$this->data,
        ];
    }
}
