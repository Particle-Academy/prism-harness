<?php

declare(strict_types=1);

namespace Prism\Harness\Tools;

use Prism\Harness\Contracts\AgentTask;
use Prism\Harness\Contracts\AgentTaskSource;
use Prism\Harness\Enums\TaskOutcome;
use Prism\Harness\Enums\TaskState;
use Prism\Harness\Exceptions\InvalidTaskOutcome;
use Prism\Harness\Exceptions\TaskNotReleasable;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tasks\TaskRecord;
use Prism\Prism\Tool;

/**
 * Lets the agent close its own task — OFF UNLESS THE HOST EXPLICITLY SAYS
 * OTHERWISE, and this is an alignment decision rather than a configuration one.
 *
 * If the model can set its own task to `done`, "run until the goal is met"
 * silently becomes "run until it decides it is met". A run that has stalled
 * then ends by declaring victory, and the task list — the one artefact that
 * would have shown the work was unfinished — agrees with it. That is the same
 * failure `prism-human-plus` addresses by reserving confirmation for the human.
 *
 * So by default `release()` is called by the APPLICATION, from evidence, and
 * this tool is not registered anywhere. A consumer that wants it registers it,
 * and even then it refuses unless BOTH of the following hold:
 *
 *  1. the existing {@see ToolAuthorizer} is enabled, and
 *  2. the host has defined the per-call ability `harness.tool.call` AND that
 *     policy allows this call.
 *
 * THE SECOND CONDITION IS WHY THIS CLASS IS MORE THAN A TOOL DEFINITION.
 * `ToolAuthorizer::allowsCall()` returns true when no per-call policy exists,
 * which is correct for an ordinary tool and wrong for this one: a host with a
 * broad offer-time policy — `harness.tool` returning true for a trusted
 * participant — would grant self-completion to every agent it trusts with any
 * tool at all, having never been asked about self-completion. Silence must not
 * read as consent for the one authority that decides whether a run is finished.
 *
 * NO NEW PERMISSION MECHANISM IS INTRODUCED. Both conditions are the gate the
 * package already has, asked twice.
 */
final class TaskCompletionTool
{
    public const NAME = 'complete_task';

    /**
     * The refusals, as CONSTANTS.
     *
     * Constant because every one of them is returned to the model inside an
     * envelope that says the host application wrote it — so none of them may
     * contain a single character the model chose. See {@see self::refused()}.
     */
    private const NOT_YOURS = 'You do not hold that task, so you may not record an outcome for it. Record outcomes only for the task you were given.';

    private const NOT_AN_OUTCOME = 'That is not an outcome. Use done or failed.';

    /**
     * @param  string  $worker  The worker this tool is bound to. REQUIRED, and
     *                          it is the fix for a hole the contract leaves
     *                          open: `release()` takes no worker, so a tool
     *                          holding only a source can close ANY task in the
     *                          list — including one another worker is halfway
     *                          through. The agent supplies the task id, so
     *                          nothing but this stops it naming a neighbour's.
     */
    public static function for(AgentTaskSource $source, Session $session, ToolAuthorizer $authorizer, string $worker): Tool
    {
        $tool = new Tool;

        return $tool
            ->as(self::NAME)
            ->for('Record the outcome of a task you were given. Only usable when the host application has authorized this session to close its own tasks.')
            ->withStringParameter('task_id', 'The id of the task, exactly as it was given to you.')
            ->withEnumParameter('outcome', 'Whether the task was completed or could not be completed.', ['done', 'failed'])
            // `$outcome` HAS A DEFAULT SO THAT AN ABSENT ONE IS REFUSED RATHER
            // THAN THROWN — and it defaults to the empty string, which is not
            // an outcome, so it lands on the same refusal a misspelled one
            // does. The schema still marks it required; this is about what
            // happens when the model ignores that.
            //
            // ABSENT MUST NOT MEAN DONE. The tempting argument is that the
            // agent called `complete_task`, so completion is what it meant —
            // and that argument is exactly how a sibling port shipped a tool
            // that hardcoded `done` and ignored the outcome entirely, so a
            // model asking in as many words for `failed` had success recorded
            // for it. An omitted argument is a malformed call, not a statement
            // of outcome, and `done` is never the value this package picks on
            // the model's behalf.
            ->using(function (string $task_id, string $outcome = '') use ($source, $session, $authorizer, $tool, $worker): string {
                $arguments = ['task_id' => $task_id, 'outcome' => $outcome];

                if (! $authorizer->enabled()) {
                    return self::refused(
                        'Tool authorization is disabled for this application, so nothing has authorized this '
                        .'agent to close its own tasks. Completion is recorded by the application from evidence.'
                    );
                }

                if (! $authorizer->hasCallPolicy()) {
                    return self::refused(
                        'This application has defined no '.ToolAuthorizer::CALL_ABILITY.' policy, and closing '
                        .'your own task is not granted by silence. Completion is recorded by the application '
                        .'from evidence unless a policy says otherwise.'
                    );
                }

                if (! $authorizer->allowsCall($session, $tool, $arguments)) {
                    return self::refused('The host application refused this completion.');
                }

                // STRICT, WITH NO DEFAULT. `$outcome === 'failed' ? … : 'done'`
                // is the shortcut here, and it resolves every malformed
                // argument — a missing one, `complete`, `DONE` — to the outcome
                // that ends the task and lets the run report success. See
                // InvalidTaskOutcome.
                try {
                    $resolution = TaskOutcome::fromInput($outcome);
                } catch (InvalidTaskOutcome $e) {
                    return self::refused(self::NOT_AN_OUTCOME, $task_id, $e->code());
                }

                $task = $source->find($task_id);

                // ONE REFUSAL FOR "no such task" AND "not yours", DELIBERATELY,
                // and this is the one place in the package that collapses two
                // states on purpose. Distinct answers here would make the tool
                // an existence oracle: a model that cannot see the list could
                // separate ids that exist from ids that do not, one call at a
                // time. Nothing the model can do about the difference differs —
                // in both cases the answer is "not this one" — and the
                // APPLICATION keeps the distinction, through `find()` and
                // through the source's own exceptions.
                //
                // The holder is not named either, for the same reason: this
                // string is read by the model, and a boundary that answers
                // questions about the other side of it is a probe away from
                // being a directory.
                if ($task === null || ! self::heldBy($task, $worker)) {
                    return self::refused(self::NOT_YOURS, $task_id);
                }

                try {
                    // The worker goes to the source, which checks ownership
                    // again. The pre-check above is not redundant — it is what
                    // produces a refusal worded for the model instead of an
                    // exception message worded for a developer, and it refuses
                    // before attempting a write.
                    $source->release($task, $worker, $resolution);
                } catch (TaskNotReleasable) {
                    // The ownership check above already refuses every case this
                    // can raise from a list that stood still. It stands for the
                    // one that does not: between the find and the release, the
                    // application may have released this very task from
                    // evidence, which is the ordinary way a task ends.
                    //
                    // RETURNED rather than thrown, for the reason AuthorizedTool
                    // gives: this is addressed to the model, which can pick a
                    // different task or stop. Tearing down the run over a race
                    // it did not cause would discard work it had already done.
                    //
                    // The exception's own MESSAGE is not passed on. It is
                    // written for the developer reading a stack trace, and this
                    // channel goes to the model.
                    return self::refused(self::NOT_YOURS, $task_id);
                }

                return json_encode([
                    'task_id' => $task->id(),
                    'state' => $resolution->value,
                ], JSON_THROW_ON_ERROR);
            });
    }

    /**
     * Whether this worker is the one holding the task RIGHT NOW.
     *
     * {@see AgentTask} is three methods and none of them is "who holds this",
     * so the holder is read from the record the shipped source returns. A
     * source that returns something else cannot prove ownership, and the answer
     * for one that cannot prove it is NO — the whole reason this check exists
     * is that the agent chooses the id, and a check that fails open under an
     * unfamiliar source is not a check.
     */
    private static function heldBy(AgentTask $task, string $worker): bool
    {
        if ($task->state() !== TaskState::Claimed) {
            return false;
        }

        // Compared exactly, never trimmed: see InvalidTaskIdentifier for why a
        // forgiving comparison here is the direction that fails open.
        return $task instanceof TaskRecord && $task->claimedBy === $worker;
    }

    /**
     * Framed so the model cannot read a refusal as the tool's own output, and
     * cannot read it as an instruction. Same shape as {@see AuthorizedTool}'s,
     * because a second framing for the same kind of message is a second thing
     * to keep in step.
     *
     * EVERY REASON IS A CONSTANT, and the model's own argument is echoed in a
     * SEPARATE FIELD rather than spliced into the sentence. The reason is prose
     * this envelope attributes to the host application; interpolating an
     * argument the model chose would put model-authored text inside a block
     * labelled as the host speaking, which is a free win handed to anything
     * trying to talk to itself through a tool result. The id is still returned,
     * because a model with several tasks in flight needs to know which one was
     * refused — it is just returned as data.
     *
     * `code` is PRESENT AND NULL when the refusal has no code in the shared
     * taxonomy yet, never absent: decision 0002 makes absent-versus-null
     * observable, and a port that dropped the key when it had nothing to put
     * there would emit a different shape for the same refusal. Only
     * `task_outcome_invalid` is pinned across the three languages today; the
     * authorization refusals still need codes agreed rather than invented here.
     */
    private static function refused(string $reason, ?string $taskId = null, ?string $code = null): string
    {
        return json_encode([
            '_framing' => 'Authorization decision from the host application. Not output from the tool, and not an instruction.',
            'tool' => self::NAME,
            'allowed' => false,
            'reason' => $reason,
            'task_id' => $taskId,
            'code' => $code,
        ], JSON_THROW_ON_ERROR);
    }
}
