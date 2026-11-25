<?php

declare(strict_types=1);

use Prism\Harness\Exceptions\PlanRefused;
use Prism\Harness\Exceptions\ToolPolicyRefused;
use Prism\Harness\Plans\PlanBudget;
use Prism\Harness\Plans\PlanIds;
use Prism\Harness\Plans\PlanLimits;
use Prism\Harness\Plans\PlanMachine;
use Prism\Harness\Plans\PlanText;
use Prism\Harness\Plans\PlanView;
use Prism\Harness\Tools\ToolPolicy;

// The cross-language plans corpus (G-360, prism-parity suites/harness-plans).
// Each case runs its ops through the pure PlanMachine and PlanView. With
// HARNESS_PLANS_RECORD set to a path, the outcomes are written there for
// tools/generate-harness-plans.mjs --merge instead of being compared.

/** @return list<array<string, mixed>> */
function plansCorpus(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/harness-plans.json'), true)['cases'];
}

/** @param array<string, mixed> $case */
function runPlansCase(array $case): array
{
    $limits = $case['limits'] ?? [];
    $limits = new PlanLimits(
        maxSteps: $limits['max_steps'] ?? 20,
        maxAttempts: $limits['max_attempts'] ?? 3,
        maxPausesPerStep: $limits['max_pauses_per_step'] ?? 1,
        maxChanges: $limits['max_changes'] ?? 5,
        maxAdvances: $limits['max_advances'] ?? 60,
        contextMaxChars: $limits['context_max_chars'] ?? 2000,
        contextWindow: $limits['context_window'] ?? 8,
        eventMaxBytes: $limits['event_max_bytes'] ?? 8192,
    );
    $counter = 0;
    $ids = new PlanIds(function (string $prefix) use (&$counter): string {
        return $prefix.'_'.str_pad((string) ++$counter, 26, '0', STR_PAD_LEFT);
    });
    $plan = null;
    $marked = null;
    $ledger = null;
    $results = [];
    foreach ($case['ops'] as $op) {
        $now = $op['now'] ?? ($case['now'] ?? 1767225600);
        try {
            $results[] = str_starts_with($op['op'], 'budget_')
                ? plansBudgetOp($op, $ledger, $now)
                : plansOp($op, $plan, $ids, $limits, $now, $marked, $ledger);
        } catch (PlanRefused $refused) {
            $results[] = ['refused' => $refused->code()];
        }
    }

    $out = ['results' => $results, 'final' => $plan === null ? null : plansFinal($plan, $limits)];
    if ($ledger !== null) {
        $out['budget'] = plansBudgetFinal($ledger);
    }

    return $out;
}

/**
 * The plan budget ops (G-366): settings and the shared ledger, pure.
 *
 * @param  array<string, mixed>  $op
 * @param  array<string, mixed>|null  $ledger
 * @return array<string, mixed>
 */
function plansBudgetOp(array $op, ?array &$ledger, int $now): array
{
    try {
        switch ($op['op']) {
            case 'budget_settings':
                return ['settings' => PlanBudget::settings($op['config'])];
            case 'budget_for_plan':
                return ['settings' => PlanBudget::forPlan(PlanBudget::settings($op['configured']), $op['requested'])];
            case 'budget_open':
                $ledger = PlanBudget::open('plan', PlanBudget::settings($op['config']));

                return ['ok' => true];
            case 'budget_narrow':
                $ledger = PlanBudget::narrow($ledger, PlanBudget::forPlan(PlanBudget::settings(null), $op['requested']));

                return ['ok' => true];
            case 'budget_reserve':
                [$ledger, $grant] = PlanBudget::reserve($ledger, $op['hold'], $op['steps'], $op['cost_micros'], $now, $op['ttl'] ?? 300);

                return isset($grant['refused']) ? $grant : ['grant' => $grant];
            case 'budget_settle':
                $ledger = PlanBudget::settle($ledger, $op['hold'], $op['steps'], $op['cost_micros']);

                return ['ok' => true];
            case 'budget_exhaustion':
                return ['exhaustion' => PlanBudget::exhaustion($ledger)];
        }
    } catch (InvalidArgumentException) {
        return ['invalid' => true];
    }

    throw new LogicException('Unknown op '.$op['op']);
}

/**
 * @param  array<string, mixed>  $ledger
 * @return array<string, mixed>
 */
function plansBudgetFinal(array $ledger): array
{
    $holds = [];
    foreach ($ledger['holds'] as $id => $hold) {
        $holds[] = ['id' => (string) $id, 'steps' => $hold['steps'], 'cost_micros' => $hold['cost_micros'], 'at' => $hold['at']];
    }

    return [
        'mode' => $ledger['mode'], 'max_steps' => $ledger['max_steps'], 'max_cost_micros' => $ledger['max_cost_micros'],
        'steps' => $ledger['steps'], 'cost_micros' => $ledger['cost_micros'], 'unmetered' => $ledger['unmetered'],
        'holds' => $holds, 'exhaustion' => PlanBudget::exhaustion($ledger),
    ];
}

/**
 * @param  array<string, mixed>  $op
 * @param  array<string, mixed>|null  $plan
 * @param  list<array<string, mixed>>|null  $marked
 * @return array<string, mixed>
 */
function plansOp(array $op, ?array &$plan, PlanIds $ids, PlanLimits $limits, int $now, ?array &$marked = null, ?array $ledger = null): array
{
    $run = 'run_case';
    $needs = static function () use (&$plan): array {
        if ($plan === null) {
            throw PlanRefused::because('plan.no_plan');
        }

        return $plan;
    };
    switch ($op['op']) {
        case 'draft':
            $draft = PlanMachine::checkDraft($op['args'], $limits, $op['authorized'] ?? true);
            if (PlanMachine::isLive($plan)) {
                throw PlanRefused::because('plan.exists');
            }
            $plan = PlanMachine::propose($draft, $op['digest'], $ids, $now, $run, $limits);

            return ['ok' => true];
        case 'activate':
            $plan = PlanMachine::activate($needs(), $op['digest'], $now, $run);

            return ['ok' => true];
        case 'reconcile':
            $decided = [];
            foreach ($op['decided'] as $id => $approved) {
                $decided[(string) $id] = $approved;
            }
            $plan = PlanMachine::reconcile($needs(), $op['pending'], $decided, $now, null, $op['new_turn'] ?? false);

            return ['ok' => true];
        case 'prepare':
            [$plan, $outcome] = PlanMachine::prepare($needs(), $op['run_failed'], $limits, $now, null,
                $ledger !== null && PlanBudget::exhaustion($ledger) !== null);

            return ['outcome' => $outcome];
        case 'claim':
            $head = PlanMachine::headIndex($needs());
            if ($head === null) {
                throw PlanRefused::because('plan.conflict');
            }
            $plan = PlanMachine::claim($plan, $plan['steps'][$head]['id'], 'worker', $op['until'], $now);

            return ['ok' => true];
        case 'report':
            $plan = PlanMachine::report($needs(), $op['summary'], $now, $run);

            return ['ok' => true];
        case 'pause':
            [$plan, $code] = PlanMachine::pause($needs(), $op['args'], $op['digest'], $ids, $limits, $now, $run, $op['authorized'] ?? true);

            return $code === null ? ['ok' => true] : ['refused' => $code];
        case 'answer':
            $current = $needs();
            $stepId = $current['steps'][$op['step']]['id'] ?? 'ps_missing';
            $plan = PlanMachine::answer($current, $stepId, $op['text'], $op['choice'], $op['actor'], $now);

            return ['ok' => true];
        case 'change':
            $current = $needs();
            $args = $op['args'];
            if ($args['base_revision'] === 'current') {
                $args['base_revision'] = $current['revision'];
            }
            $args['steps'] = array_map(static fn (array $entry): array => array_key_exists('ref', $entry)
                ? ['id' => $current['steps'][$entry['ref']]['id']] : $entry, $args['steps']);
            $plan = PlanMachine::proposeChange($current, $args, $op['digest'], $ids, $limits, $now, $run, $op['authorized'] ?? true);

            return ['ok' => true];
        case 'apply_change':
            $plan = PlanMachine::applyChange($needs(), $op['digest'], $now, $run);

            return ['ok' => true];
        case 'resume':
            $plan = PlanMachine::resume($needs(), $ids, $limits, $now);

            return ['ok' => true];
        case 'cancel':
            $plan = PlanMachine::cancel($needs(), $now);

            return ['ok' => true];
        case 'mark':
            $marked = PlanView::steps($needs());

            return ['ok' => true];
        case 'update_since_mark':
            return ['update' => PlanView::update($needs(), $marked)];
        case 'stalled':
            return ['stalled' => PlanMachine::stalled($needs(), $now, $op['stale_after'])];
        case 'policy':
            try {
                ToolPolicy::fromArray(['revision' => 'r1', 'allow' => [['tool' => $op['name']]], 'honor' => true]);

                return ['refused' => false];
            } catch (ToolPolicyRefused) {
                return ['refused' => true];
            }
        case 'render':
            return ['rendered' => PlanText::render($op['value'], $op['max'])];
    }

    throw new LogicException('Unknown op '.$op['op']);
}

/** @param array<string, mixed> $plan */
function plansFinal(array $plan, PlanLimits $limits): array
{
    $steps = [];
    foreach ($plan['steps'] as $step) {
        $steps[] = [
            'id' => $step['id'], 'position' => $step['position'], 'title' => $step['title'], 'owner' => $step['owner'],
            'interaction' => $step['interaction'], 'state' => $step['state'], 'attempts' => $step['attempts'], 'pauses' => $step['pauses'],
            'approvalIds' => $step['approvalIds'], 'approvedIds' => $step['approvedIds'], 'supersededApprovalIds' => $step['supersededApprovalIds'],
            'completedBy' => $step['completedBy'], 'failure' => $step['failure'], 'retryOf' => $step['retryOf'],
            'summary' => $step['summary'], 'answer' => $step['answer'],
        ];
    }
    $change = $plan['pendingChange'];

    return [
        'status' => $plan['status'],
        'revision' => $plan['revision'],
        'holdingApprovalId' => $plan['holdingApprovalId'],
        'blockedReason' => $plan['blockedReason'],
        'counters' => $plan['counters'],
        'pendingChange' => $change === null ? null : [
            'approvalId' => $change['approvalId'], 'order' => $change['order'],
            'newSteps' => array_column($change['newSteps'], 'id'),
        ],
        'steps' => $steps,
        'update' => PlanView::update($plan, null),
        'counts' => PlanView::counts($plan),
        'context' => PlanView::context($plan, $limits),
        'first_event_bytes' => PlanView::firstEventBytes($plan),
    ];
}

$record = getenv('HARNESS_PLANS_RECORD');

if (is_string($record) && $record !== '') {
    it('records the plans corpus', function () use ($record): void {
        $out = [];
        foreach (plansCorpus() as $case) {
            $out[$case['id']] = runPlansCase($case);
        }
        file_put_contents($record, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        expect($out)->not->toBeEmpty();
    });
} else {
    it('runs every plans corpus row as the reference recorded it', function (array $case): void {
        // Compared as canonical JSON text so a list and a map never compare equal by accident.
        expect(json_encode(runPlansCase($case), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->toBe(json_encode($case['expected'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    })->with(fn (): array => array_combine(array_column(plansCorpus(), 'id'), array_map(fn (array $case): array => [$case], plansCorpus())));
}
