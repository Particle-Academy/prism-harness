<?php

declare(strict_types=1);

namespace Prism\Harness\Plans;

use Prism\Harness\Exceptions\PlanRefused;

/**
 * Every transition a plan can make, as pure functions over the stored plan
 * (G-360). No I/O, no clock, no randomness: the caller passes the time, the
 * id generator and the facts read from the thread, so the same inputs give
 * the same plan in PHP, TypeScript and Python. The `harness-plans` parity
 * corpus drives these functions directly.
 *
 * A PLAN GRANTS NOTHING. Nothing here runs a tool, decides an approval or
 * touches the task list. Approvals are core approvals, decided through
 * Session::decide(); this only reads which ids are pending or answered, and
 * those ids come from the persisted thread, never from a tool argument.
 *
 * Refusals throw PlanRefused with a constant message.
 *
 * @phpstan-type Step array<string, mixed>
 * @phpstan-type Plan array<string, mixed>
 */
final class PlanMachine
{
    public const DRAFT = 'plan_draft';

    public const COMPLETE = 'plan_complete_step';

    public const PAUSE = 'plan_pause_for_person';

    public const CHANGE = 'plan_propose_change';

    /** Every plan tool name. A pending approval for one of these never holds an agent step. */
    public const TOOLS = [self::DRAFT, self::COMPLETE, self::PAUSE, self::CHANGE];

    public const LIVE = ['proposed', 'active', 'waiting', 'blocked'];

    public const TERMINAL = ['done', 'failed', 'skipped'];

    // ------------------------------------------------------------------
    // Drafting
    // ------------------------------------------------------------------

    /**
     * Check a draft at the tool boundary. Refused, never truncated.
     *
     * @return array{goal: string, steps: list<array<string, mixed>>}
     */
    public static function checkDraft(mixed $args, PlanLimits $limits): array
    {
        if (! is_array($args) || ! array_key_exists('goal', $args) || ! array_key_exists('steps', $args)
            || array_diff(array_keys($args), ['goal', 'steps']) !== []) {
            throw PlanRefused::because('plan.shape');
        }
        if (! is_array($args['steps']) || ($args['steps'] !== [] && ! array_is_list($args['steps']))) {
            throw PlanRefused::because('plan.shape');
        }
        if (! PlanText::acceptable($args['goal'], PlanText::GOAL)) {
            throw PlanRefused::because('plan.text');
        }
        $count = count($args['steps']);
        if ($count < 1 || $count > $limits->maxSteps) {
            throw PlanRefused::because('plan.bounds');
        }
        $steps = [];
        foreach ($args['steps'] as $step) {
            $steps[] = self::checkStepInput($step);
        }

        return ['goal' => $args['goal'], 'steps' => $steps];
    }

    /**
     * One step as the model wrote it: `{title, instruction, owner, interaction?, question?, options?}`.
     * A person's step is a question (`interaction: input`); a person's approval
     * mid-plan is a core approval, raised with plan_pause_for_person.
     *
     * @return array<string, mixed>
     */
    public static function checkStepInput(mixed $step): array
    {
        $keys = ['title', 'instruction', 'owner', 'interaction', 'question', 'options'];
        if (! is_array($step) || ($step !== [] && array_is_list($step)) || array_diff(array_keys($step), $keys) !== []) {
            throw PlanRefused::because('plan.shape');
        }
        $owner = $step['owner'] ?? null;
        $interaction = $step['interaction'] ?? null;
        $question = $step['question'] ?? null;
        $options = $step['options'] ?? null;
        $instruction = $step['instruction'] ?? null;
        if ($owner === 'agent') {
            if ($interaction !== null || $question !== null || $options !== null) {
                throw PlanRefused::because('plan.shape');
            }
        } elseif ($owner === 'human') {
            if ($interaction !== 'input' || $question === null) {
                throw PlanRefused::because('plan.shape');
            }
        } else {
            throw PlanRefused::because('plan.shape');
        }
        if ($options !== null && (! is_array($options) || ($options !== [] && ! array_is_list($options)) || count($options) > PlanText::OPTIONS)) {
            throw PlanRefused::because('plan.shape');
        }
        if (! PlanText::acceptable($step['title'] ?? null, PlanText::TITLE)) {
            throw PlanRefused::because('plan.text');
        }
        if ($owner === 'agent' && ! PlanText::acceptable($instruction, PlanText::INSTRUCTION)) {
            throw PlanRefused::because('plan.text');
        }
        if ($owner === 'human' && $instruction !== null && ! PlanText::acceptable($instruction, PlanText::INSTRUCTION)) {
            throw PlanRefused::because('plan.text');
        }
        if ($question !== null && ! PlanText::acceptable($question, PlanText::QUESTION)) {
            throw PlanRefused::because('plan.text');
        }
        foreach ($options ?? [] as $option) {
            if (! PlanText::acceptable($option, PlanText::OPTION)) {
                throw PlanRefused::because('plan.text');
            }
        }

        return [
            'title' => $step['title'],
            'instruction' => $instruction,
            'owner' => $owner,
            'interaction' => $owner === 'human' ? 'input' : null,
            'question' => $question,
            'options' => $options ?? [],
        ];
    }

    /**
     * A new plan from a checked draft: `proposed`, revision 1.
     *
     * @param  array{goal: string, steps: list<array<string, mixed>>}  $draft
     * @return array<string, mixed>
     */
    public static function propose(array $draft, string $digest, PlanIds $ids, int $now, ?string $runId, PlanLimits $limits): array
    {
        $plan = [
            'id' => $ids->plan(),
            'anchorRunId' => $runId,
            'revision' => 1,
            'status' => 'proposed',
            'goal' => $draft['goal'],
            'holdingApprovalId' => null,
            'pendingChange' => null,
            'blockedReason' => null,
            'draftDigest' => $digest,
            'counters' => ['advances' => 0, 'changes' => 0, 'settledByTool' => 0, 'settledWithoutTool' => 0],
            'lastRunId' => $runId,
            'createdAt' => self::iso($now),
            'updatedAt' => self::iso($now),
            'lastAdvancedAt' => null,
            'steps' => [],
        ];
        foreach ($draft['steps'] as $input) {
            $plan['steps'][] = self::newStep($ids->step(), $input);
        }
        $plan = self::renumber($plan);
        if (PlanView::firstEventBytes($plan) > $limits->eventMaxBytes) {
            throw PlanRefused::because('plan.size');
        }

        return $plan;
    }

    /**
     * The person approved the draft: the approved call ran with its stored
     * arguments, and they must be the arguments that were proposed.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function activate(array $plan, string $digest, int $now, ?string $runId): array
    {
        if ($plan['status'] !== 'proposed' || $plan['draftDigest'] !== $digest) {
            throw PlanRefused::because('plan.not_waiting');
        }
        $plan['status'] = 'active';
        $plan['holdingApprovalId'] = null;

        return self::written($plan, $now, $runId);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function reject(array $plan, ?string $reason): array
    {
        $plan['status'] = 'rejected';
        $plan['blockedReason'] = $reason;
        $plan['holdingApprovalId'] = null;
        foreach ($plan['steps'] as $i => $step) {
            if (! in_array($step['state'], self::TERMINAL, true)) {
                $plan['steps'][$i] = self::closed($step, 'skipped', 'host', 'rejected');
            }
        }

        return $plan;
    }

    // ------------------------------------------------------------------
    // Reading the thread: held, superseded, moved, decided
    // ------------------------------------------------------------------

    /**
     * Bring the plan in line with the thread (G-360 §1.4).
     *
     * `$pending` lists every approval pending at the transcript tail, with the
     * tool it was asked for and the digest of its stored arguments. `$decided`
     * maps every answered approval id to whether it was approved. Both are read
     * from the persisted thread; nothing here accepts an id from a tool
     * argument or from model text.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array{id: string, name: string, digest: string}>  $pending
     * @param  array<string, bool>  $decided
     * @param  bool  $newTurn  a turn with a new user message is starting: every pending approval is about to end
     * @return array<string, mixed>
     */
    public static function reconcile(array $plan, array $pending, array $decided, int $now, ?string $runId, bool $newTurn = false): array
    {
        if (! in_array($plan['status'], self::LIVE, true)) {
            return $plan;
        }
        $original = $plan;
        $pendingIds = array_column($pending, 'id');

        $known = self::boundIds($plan);
        $find = static function (string $name, ?string $digest) use ($pending, &$known): ?string {
            foreach ($pending as $entry) {
                if ($entry['name'] === $name && $entry['digest'] === $digest && ! in_array($entry['id'], $known, true)) {
                    $known[] = $entry['id'];

                    return $entry['id'];
                }
            }

            return null;
        };

        // The draft.
        if ($plan['status'] === 'proposed') {
            $plan['holdingApprovalId'] ??= $find(self::DRAFT, $plan['draftDigest']);
            $holding = $plan['holdingApprovalId'];
            if ($holding === null) {
                $plan = self::reject($plan, 'approval_withdrawn');
            } elseif (array_key_exists($holding, $decided)) {
                if ($decided[$holding] === false) {
                    $plan = self::reject($plan, null);
                }
            } elseif (! in_array($holding, $pendingIds, true)) {
                $plan = self::reject($plan, 'approval_withdrawn');
            }
        }

        // A proposed change.
        if (is_array($plan['pendingChange'])) {
            $change = $plan['pendingChange'];
            $change['approvalId'] ??= $find(self::CHANGE, $change['digest']);
            $plan['pendingChange'] = $change;
            $id = $change['approvalId'];
            if ($id === null || (array_key_exists($id, $decided) && $decided[$id] === false)
                || (! array_key_exists($id, $decided) && ! in_array($id, $pendingIds, true))) {
                $plan['pendingChange'] = null;
                if ($plan['status'] === 'waiting') {
                    $plan['status'] = 'active';
                }
            }
        }

        // A person's approval step, raised with plan_pause_for_person.
        foreach ($plan['steps'] as $i => $step) {
            if ($step['owner'] !== 'human' || $step['interaction'] !== 'approve' || $step['state'] !== 'held') {
                continue;
            }
            if ($step['approvalIds'] === []) {
                $bound = $find(self::PAUSE, $step['pauseDigest']);
                if ($bound === null) {
                    $plan['steps'][$i] = self::closed($step, 'failed', 'host', 'approval_withdrawn');
                    $plan = self::block($plan, 'approval_withdrawn');

                    continue;
                }
                $step['approvalIds'] = [$bound];
                $plan['steps'][$i] = $step;
            }
            $id = $step['approvalIds'][0];
            if (array_key_exists($id, $decided)) {
                $step['approvedIds'] = $decided[$id] ? [$id] : [];
                $plan['steps'][$i] = self::closed($step, $decided[$id] ? 'done' : 'skipped', 'person', $decided[$id] ? null : 'declined');
            } elseif (! in_array($id, $pendingIds, true)) {
                $step['supersededApprovalIds'] = [...$step['supersededApprovalIds'], $id];
                $step['approvalIds'] = [];
                $plan['steps'][$i] = self::closed($step, 'failed', 'host', 'approval_withdrawn');
                $plan = self::block($plan, 'approval_withdrawn');
            }
        }

        // The agent's head step and the approvals its turn staged. Not at the
        // start of a turn with a new message: that turn may stage the card
        // the hold moves to, so the hold is judged when the turn is over.
        $head = self::headIndex($plan);
        if (! $newTurn && $head !== null && in_array($plan['status'], ['active', 'waiting'], true)) {
            $step = $plan['steps'][$head];
            if ($step['owner'] === 'agent' && in_array($step['state'], ['running', 'held'], true)) {
                $plan = self::reconcileAgentStep($plan, $head, $pending, $pendingIds, $decided, $known);
            }
        }

        $plan = self::normalised($plan);

        return $plan === $original ? $plan : self::written($plan, $now, $runId);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array{id: string, name: string, digest: string}>  $pending
     * @param  list<string>  $pendingIds
     * @param  array<string, bool>  $decided
     * @param  list<string>  $known
     * @return array<string, mixed>
     */
    private static function reconcileAgentStep(array $plan, int $index, array $pending, array $pendingIds, array $decided, array $known): array
    {
        $step = $plan['steps'][$index];
        $live = array_values(array_diff($step['approvalIds'], $step['approvedIds']));

        foreach ($live as $id) {
            if (array_key_exists($id, $decided) && $decided[$id] === false) {
                // A person refused work this step needed: never shown as done.
                $plan['steps'][$index] = self::closed($step, 'failed', 'person', 'approval_refused');

                return $plan;
            }
        }
        $stillPending = [];
        $superseded = [];
        foreach ($live as $id) {
            if (array_key_exists($id, $decided)) {
                $step['approvedIds'][] = $id;
            } elseif (in_array($id, $pendingIds, true)) {
                $stillPending[] = $id;
            } else {
                $superseded[] = $id;
            }
        }
        $fresh = [];
        foreach ($pending as $entry) {
            if (! in_array($entry['name'], self::TOOLS, true) && ! in_array($entry['id'], $known, true)) {
                $fresh[] = $entry['id'];
            }
        }
        $step['approvalIds'] = [...array_values(array_diff($step['approvalIds'], $superseded)), ...$fresh];
        $step['supersededApprovalIds'] = [...$step['supersededApprovalIds'], ...$superseded];

        if ($stillPending !== [] || $fresh !== []) {
            $step['state'] = 'held';
        } elseif ($superseded !== []) {
            // The person's card was lost and nothing replaced it.
            $plan['steps'][$index] = self::closed($step, 'failed', 'host', 'approval_withdrawn');

            return self::block($plan, 'approval_withdrawn');
        } else {
            $step['state'] = 'running';
        }
        $plan['steps'][$index] = $step;

        return $plan;
    }

    // ------------------------------------------------------------------
    // The driver
    // ------------------------------------------------------------------

    /**
     * Settle the previous head from persisted state, check the limits, and
     * choose what happens next. Returns the plan and an outcome:
     * `['run', stepId]`, `['waiting', stepId]`, `['blocked', reason]`,
     * `['done', null]` or `['not_active', status]`.
     *
     * @param  array<string, mixed>  $plan
     * @return array{0: array<string, mixed>, 1: array{0: string, 1: ?string}}
     */
    public static function prepare(array $plan, bool $runFailed, PlanLimits $limits, int $now, ?string $runId): array
    {
        if ($plan['status'] !== 'active') {
            return [$plan, ['not_active', $plan['status']]];
        }
        $original = $plan;
        $plan = self::settle($plan, $runFailed, $limits);

        $head = self::headIndex($plan);
        $outcome = null;
        if ($plan['status'] === 'blocked') {
            $outcome = ['blocked', $plan['blockedReason']];
        } elseif ($head === null) {
            $plan = self::normalised($plan);
            $outcome = ['done', null];
        } else {
            $step = $plan['steps'][$head];
            if ($step['owner'] === 'human' && $step['state'] === 'todo') {
                $step['state'] = 'waiting_input';
                $plan['steps'][$head] = $step;
                $outcome = ['waiting', $step['id']];
            } elseif ($step['state'] !== 'todo') {
                $outcome = ['waiting', $step['id']];
            } elseif ($plan['counters']['advances'] >= $limits->maxAdvances) {
                $plan = self::block($plan, 'budget');
                $outcome = ['blocked', 'budget'];
            } else {
                $outcome = ['run', $step['id']];
            }
        }
        $plan = self::normalised($plan);

        return [$plan === $original ? $plan : self::written($plan, $now, $runId), $outcome];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function settle(array $plan, bool $runFailed, PlanLimits $limits): array
    {
        $head = self::headIndex($plan);
        if ($head === null) {
            return $plan;
        }
        $step = $plan['steps'][$head];
        if ($step['owner'] !== 'agent') {
            return $plan;
        }
        if ($step['state'] === 'running') {
            if (is_array($step['report']) && ! $runFailed) {
                $step['summary'] = $step['report']['summary'];
                $plan['steps'][$head] = self::closed($step, 'done', 'agent-report', null);
                $plan['counters']['settledByTool']++;

                return $plan;
            }
            $step['state'] = 'todo';
            $step['claim'] = null;
            $step['report'] = null;
            $step['lastSettle'] = $runFailed ? 'run_failed' : 'no_report';
            if (! $runFailed) {
                $plan['counters']['settledWithoutTool']++;
            }
            $plan['steps'][$head] = $step;
        }
        $step = $plan['steps'][$head];
        if ($step['state'] === 'todo' && $step['attempts'] >= $limits->maxAttempts) {
            $reason = $step['lastSettle'] === 'no_report' ? 'no_completion' : 'attempts_exhausted';
            $plan['steps'][$head] = self::closed($step, 'failed', 'host', $reason);
            $plan = self::block($plan, $reason);
        }

        return $plan;
    }

    /**
     * Claim the head step for one turn.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function claim(array $plan, string $stepId, string $worker, int $until, int $now): array
    {
        $head = self::headIndex($plan);
        if ($plan['status'] !== 'active' || $head === null || $plan['steps'][$head]['id'] !== $stepId || $plan['steps'][$head]['state'] !== 'todo') {
            throw PlanRefused::because('plan.conflict');
        }
        $step = $plan['steps'][$head];
        $step['state'] = 'running';
        $step['claim'] = ['worker' => $worker, 'until' => $until];
        $step['attempts']++;
        $step['report'] = null;
        $plan['steps'][$head] = $step;
        $plan['counters']['advances']++;
        $plan['lastAdvancedAt'] = self::iso($now);

        return self::written($plan, $now, null);
    }

    /**
     * plan_complete_step: the agent reports its running step done. It does
     * NOT advance; the next advance settles it.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function report(array $plan, mixed $summary, int $now, ?string $runId): array
    {
        $head = self::headIndex($plan);
        if (! in_array($plan['status'], ['active', 'waiting'], true) || $head === null
            || $plan['steps'][$head]['owner'] !== 'agent' || $plan['steps'][$head]['state'] !== 'running') {
            throw PlanRefused::because('plan.no_running_step');
        }
        if (! PlanText::acceptable($summary, PlanText::SUMMARY)) {
            throw PlanRefused::because('plan.text');
        }
        $plan['steps'][$head]['report'] = ['summary' => $summary];

        return self::written($plan, $now, $runId);
    }

    /**
     * plan_pause_for_person: insert a person's step before the agent's
     * running step. A second pause on one step blocks the plan, and the
     * blocked plan is returned with the refusal so it can be written.
     *
     * @param  array<string, mixed>  $plan
     * @return array{0: array<string, mixed>, 1: ?string} the plan, and a refusal code or null
     */
    public static function pause(array $plan, mixed $args, ?string $digest, PlanIds $ids, PlanLimits $limits, int $now, ?string $runId): array
    {
        if (! is_array($args) || array_diff(array_keys($args), ['kind', 'question', 'options']) !== []
            || ! in_array($args['kind'] ?? null, ['approve', 'input'], true)) {
            throw PlanRefused::because('plan.shape');
        }
        $options = $args['options'] ?? null;
        if ($options !== null && (! is_array($options) || ($options !== [] && ! array_is_list($options))
            || count($options) > PlanText::OPTIONS || ($args['kind'] === 'approve' && $options !== []))) {
            throw PlanRefused::because('plan.shape');
        }
        if (! PlanText::acceptable($args['question'] ?? null, PlanText::TITLE)) {
            throw PlanRefused::because('plan.text');
        }
        foreach ($options ?? [] as $option) {
            if (! PlanText::acceptable($option, PlanText::OPTION)) {
                throw PlanRefused::because('plan.text');
            }
        }
        $head = self::headIndex($plan);
        if ($plan['status'] !== 'active' || $head === null || $plan['steps'][$head]['owner'] !== 'agent'
            || $plan['steps'][$head]['state'] !== 'running') {
            throw PlanRefused::because('plan.no_running_step');
        }
        $step = $plan['steps'][$head];
        if ($step['pauses'] >= $limits->maxPausesPerStep) {
            $plan['steps'][$head] = self::closed($step, 'failed', 'host', 'pause_limit');
            $plan = self::block($plan, 'pause_limit');

            return [self::written(self::normalised($plan), $now, $runId), 'plan.pause_limit'];
        }
        $step['pauses']++;
        $step['state'] = 'todo';
        $step['claim'] = null;
        $step['report'] = null;
        $step['attempts'] = max(0, $step['attempts'] - 1);
        $plan['steps'][$head] = $step;

        $human = self::newStep($ids->step(), [
            'title' => $args['question'],
            'instruction' => null,
            'owner' => 'human',
            'interaction' => $args['kind'],
            'question' => $args['question'],
            'options' => $options ?? [],
        ]);
        $human['state'] = $args['kind'] === 'approve' ? 'held' : 'waiting_input';
        $human['pauseDigest'] = $args['kind'] === 'approve' ? $digest : null;
        array_splice($plan['steps'], $head, 0, [$human]);
        if (count($plan['steps']) > $limits->maxSteps) {
            throw PlanRefused::because('plan.bounds');
        }

        return [self::written(self::normalised(self::renumber($plan)), $now, $runId), null];
    }

    /**
     * A person answers a question step. The actor is the host's, never the
     * model's. An answer is data for the next turn; it releases no approval.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function answer(array $plan, mixed $stepId, mixed $text, mixed $choice, mixed $actor, int $now): array
    {
        if (! is_string($actor) || trim($actor) === '') {
            throw PlanRefused::because('plan.no_actor');
        }
        $index = null;
        foreach ($plan['steps'] as $i => $step) {
            if ($step['id'] === $stepId) {
                $index = $i;
            }
        }
        if ($index === null || ! in_array($plan['status'], ['active', 'waiting'], true)) {
            throw PlanRefused::because('plan.not_answerable');
        }
        $step = $plan['steps'][$index];
        if ($step['owner'] !== 'human' || $step['interaction'] !== 'input' || $step['state'] !== 'waiting_input') {
            throw PlanRefused::because('plan.not_answerable');
        }
        $text ??= '';
        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || PlanText::length($text) > PlanText::ANSWER
            || ($choice !== null && ! in_array($choice, $step['options'], true))
            || ($text === '' && $choice === null)) {
            throw PlanRefused::because('plan.answer_invalid');
        }
        $step['answer'] = ['text' => $text, 'choice' => $choice, 'actor' => $actor, 'at' => self::iso($now)];
        $plan['steps'][$index] = self::closed($step, 'done', 'person', null);

        return self::written(self::normalised($plan), $now, null);
    }

    /**
     * plan_propose_change: a new list for the steps that can still be
     * replaced. Only `todo`, agent-owned steps can be replaced; every other
     * step must appear unchanged, by id, in its order, with every finished
     * step first. A stale revision, an unknown id and a step that cannot be
     * replaced get the same refusal.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function proposeChange(array $plan, mixed $args, string $digest, PlanIds $ids, PlanLimits $limits, int $now, ?string $runId): array
    {
        if (! is_array($args) || array_diff(array_keys($args), ['reason', 'base_revision', 'steps']) !== []
            || ! is_array($args['steps'] ?? null) || ($args['steps'] !== [] && ! array_is_list($args['steps']))) {
            throw PlanRefused::because('plan.shape');
        }
        if ($plan['status'] !== 'active' || ($args['base_revision'] ?? null) !== $plan['revision']) {
            throw PlanRefused::because('plan.change_refused');
        }
        if ($plan['counters']['changes'] >= $limits->maxChanges) {
            throw PlanRefused::because('plan.change_limit');
        }
        if (! PlanText::acceptable($args['reason'] ?? null, PlanText::REASON)) {
            throw PlanRefused::because('plan.text');
        }
        if (count($args['steps']) < 1 || count($args['steps']) > $limits->maxSteps) {
            throw PlanRefused::because('plan.bounds');
        }

        $existing = [];
        foreach ($plan['steps'] as $step) {
            $existing[$step['id']] = $step;
        }
        $order = [];
        $new = [];
        foreach ($args['steps'] as $entry) {
            if (is_array($entry) && array_key_exists('id', $entry) && $entry['id'] === null) {
                unset($entry['id']);
            }
            if (is_array($entry) && array_key_exists('id', $entry)) {
                $rest = $entry;
                unset($rest['id']);
                if (array_filter($rest, static fn (mixed $v): bool => $v !== null) !== []
                    || ! PlanIds::isStepId($entry['id']) || ! isset($existing[$entry['id']]) || in_array($entry['id'], $order, true)) {
                    throw PlanRefused::because('plan.change_refused');
                }
                $order[] = $entry['id'];

                continue;
            }
            $step = self::newStep($ids->step(), self::checkStepInput($entry));
            $new[$step['id']] = $step;
            $order[] = $step['id'];
        }

        $fixed = [];
        foreach ($plan['steps'] as $step) {
            if (! self::replaceable($step)) {
                $fixed[] = $step['id'];
            }
        }
        // Every fixed step is kept, in its order.
        $keptFixed = array_values(array_filter($order, static fn (string $id): bool => in_array($id, $fixed, true)));
        if ($keptFixed !== $fixed) {
            throw PlanRefused::because('plan.change_refused');
        }
        // Finished steps come first; a fixed head stays the head.
        $seenOpen = false;
        foreach ($order as $id) {
            $terminal = isset($existing[$id]) && in_array($existing[$id]['state'], self::TERMINAL, true);
            if ($terminal && $seenOpen) {
                throw PlanRefused::because('plan.change_refused');
            }
            if (! $terminal) {
                if (! $seenOpen) {
                    $head = self::headIndex($plan);
                    if ($head !== null && ! self::replaceable($plan['steps'][$head]) && $plan['steps'][$head]['id'] !== $id) {
                        throw PlanRefused::because('plan.change_refused');
                    }
                }
                $seenOpen = true;
            }
        }
        $replaced = array_values(array_diff(array_keys($existing), $order));
        if (count($order) + count($replaced) > $limits->maxSteps) {
            throw PlanRefused::because('plan.bounds');
        }

        $plan['pendingChange'] = [
            'approvalId' => null,
            'baseRevision' => $plan['revision'],
            'reason' => $args['reason'],
            'digest' => $digest,
            'order' => $order,
            'newSteps' => array_values($new),
        ];
        $plan['status'] = 'waiting';
        $plan['counters']['changes']++;
        if (PlanView::firstEventBytes($plan) > $limits->eventMaxBytes) {
            throw PlanRefused::because('plan.size');
        }

        return self::written($plan, $now, $runId);
    }

    /**
     * The person approved the change: the approved call ran with its stored
     * arguments, which must be the proposed ones.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function applyChange(array $plan, string $digest, int $now, ?string $runId): array
    {
        $change = $plan['pendingChange'];
        if ($plan['status'] !== 'waiting' || ! is_array($change) || $change['digest'] !== $digest) {
            throw PlanRefused::because('plan.not_waiting');
        }
        $byId = [];
        foreach ([...$plan['steps'], ...$change['newSteps']] as $step) {
            $byId[$step['id']] = $step;
        }
        $steps = [];
        foreach ($change['order'] as $id) {
            $steps[] = $byId[$id];
        }
        foreach ($plan['steps'] as $step) {
            if (! in_array($step['id'], $change['order'], true)) {
                $steps[] = self::closed($step, 'skipped', 'host', 'replaced');
            }
        }
        $plan['steps'] = $steps;
        $plan['pendingChange'] = null;
        $plan['status'] = 'active';

        return self::written(self::normalised(self::renumber($plan)), $now, $runId);
    }

    /**
     * The host unblocks a plan. A step that failed for lack of a person's
     * card or of attempts gets a retry step after it; a step that paused too
     * often is not replayed; a plan that ran out of advances stays blocked.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function resume(array $plan, PlanIds $ids, PlanLimits $limits, int $now): array
    {
        if ($plan['status'] !== 'blocked' || $plan['blockedReason'] === 'budget') {
            throw PlanRefused::because('plan.not_resumable');
        }
        $reason = $plan['blockedReason'];
        if (in_array($reason, ['attempts_exhausted', 'no_completion', 'approval_withdrawn'], true)) {
            $failed = null;
            foreach ($plan['steps'] as $i => $step) {
                if ($step['owner'] === 'agent' && $step['state'] === 'failed' && $step['failure'] === $reason) {
                    $failed = $i;
                }
            }
            if ($failed !== null) {
                if (count($plan['steps']) + 1 > $limits->maxSteps) {
                    throw PlanRefused::because('plan.bounds');
                }
                $old = $plan['steps'][$failed];
                $retry = self::newStep($ids->step(), [
                    'title' => $old['title'], 'instruction' => $old['instruction'], 'owner' => 'agent',
                    'interaction' => null, 'question' => null, 'options' => [],
                ]);
                $retry['retryOf'] = $old['id'];
                array_splice($plan['steps'], $failed + 1, 0, [$retry]);
            }
        }
        $plan['status'] = 'active';
        $plan['blockedReason'] = null;

        return self::written(self::normalised(self::renumber($plan)), $now, null);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function cancel(array $plan, int $now): array
    {
        if (! in_array($plan['status'], self::LIVE, true)) {
            throw PlanRefused::because('plan.not_live');
        }
        foreach ($plan['steps'] as $i => $step) {
            if (! in_array($step['state'], self::TERMINAL, true)) {
                $plan['steps'][$i] = self::closed($step, 'skipped', 'host', 'cancelled');
            }
        }
        $plan['pendingChange'] = null;
        $plan['holdingApprovalId'] = null;
        $plan['status'] = 'cancelled';

        return self::written($plan, $now, null);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $plan */
    public static function headIndex(array $plan): ?int
    {
        foreach ($plan['steps'] as $i => $step) {
            if (! in_array($step['state'], self::TERMINAL, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Whether the reaper should hand this plan back to the host: it is
     * active, its head is the agent's step with no live claim (todo, or
     * running on a lease that ran out), and it has not moved for $staleAfter
     * seconds. A step waiting on a person is never stalled.
     *
     * @param  array<string, mixed>  $plan
     */
    public static function stalled(array $plan, int $now, int $staleAfter): bool
    {
        $head = self::headIndex($plan);
        if ($plan['status'] !== 'active' || $head === null) {
            return false;
        }
        $step = $plan['steps'][$head];
        $claimLive = is_array($step['claim']) && $step['claim']['until'] > $now;
        $idle = ($step['owner'] === 'agent' && $step['state'] === 'todo') || ($step['state'] === 'running' && ! $claimLive);
        $last = strtotime((string) ($plan['lastAdvancedAt'] ?? $plan['updatedAt']));

        return $idle && ($last === false || $now - $last >= $staleAfter);
    }

    /** @param array<string, mixed> $plan */
    public static function isLive(?array $plan): bool
    {
        return is_array($plan) && in_array($plan['status'], self::LIVE, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function newStep(string $id, array $input): array
    {
        return [
            'id' => $id,
            'position' => 0,
            'title' => $input['title'],
            'instruction' => $input['instruction'],
            'owner' => $input['owner'],
            'interaction' => $input['interaction'],
            'question' => $input['question'],
            'options' => $input['options'],
            'state' => 'todo',
            'approvalIds' => [],
            'approvedIds' => [],
            'supersededApprovalIds' => [],
            'pauseDigest' => null,
            'claim' => null,
            'attempts' => 0,
            'pauses' => 0,
            'lastSettle' => null,
            'report' => null,
            'summary' => null,
            'answer' => null,
            'completedBy' => null,
            'failure' => null,
            'retryOf' => null,
        ];
    }

    /** @param array<string, mixed> $step */
    private static function replaceable(array $step): bool
    {
        return $step['owner'] === 'agent' && $step['state'] === 'todo';
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private static function closed(array $step, string $state, string $by, ?string $failure): array
    {
        $step['state'] = $state;
        $step['completedBy'] = $by;
        $step['failure'] = $failure;
        $step['claim'] = null;
        $step['report'] = null;

        return $step;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function block(array $plan, string $reason): array
    {
        $plan['status'] = 'blocked';
        $plan['blockedReason'] = $reason;

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function renumber(array $plan): array
    {
        $plan['steps'] = array_values($plan['steps']);
        foreach ($plan['steps'] as $i => $step) {
            $plan['steps'][$i]['position'] = $i + 1;
        }

        return $plan;
    }

    /**
     * Finish an active plan whose steps are all closed, and point
     * holdingApprovalId at the one approval that is actually holding it.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function normalised(array $plan): array
    {
        if (in_array($plan['status'], ['active', 'waiting'], true) && $plan['pendingChange'] === null && self::headIndex($plan) === null) {
            $plan['status'] = 'done';
        }
        if ($plan['status'] === 'proposed') {
            return $plan;
        }
        $holding = null;
        if (is_array($plan['pendingChange']) && $plan['pendingChange']['approvalId'] !== null) {
            $holding = $plan['pendingChange']['approvalId'];
        } else {
            $head = self::headIndex($plan);
            if ($head !== null && $plan['steps'][$head]['state'] === 'held') {
                $step = $plan['steps'][$head];
                $holding = array_values(array_diff($step['approvalIds'], $step['approvedIds']))[0] ?? null;
            }
        }
        $plan['holdingApprovalId'] = in_array($plan['status'], ['active', 'waiting'], true) ? $holding : null;

        return $plan;
    }

    /**
     * Every approval id the plan is already bound to.
     *
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private static function boundIds(array $plan): array
    {
        $ids = [];
        if (is_string($plan['holdingApprovalId'])) {
            $ids[] = $plan['holdingApprovalId'];
        }
        if (is_array($plan['pendingChange']) && is_string($plan['pendingChange']['approvalId'])) {
            $ids[] = $plan['pendingChange']['approvalId'];
        }
        foreach ($plan['steps'] as $step) {
            array_push($ids, ...$step['approvalIds'], ...$step['supersededApprovalIds']);
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private static function written(array $plan, int $now, ?string $runId): array
    {
        $plan['revision']++;
        $plan['updatedAt'] = self::iso($now);
        $plan['lastRunId'] = $runId;

        return $plan;
    }

    public static function iso(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $seconds);
    }
}
