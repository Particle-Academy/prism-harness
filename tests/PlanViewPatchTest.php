<?php

declare(strict_types=1);

use Prism\Harness\Plans\PlanIds;
use Prism\Harness\Plans\PlanLimits;
use Prism\Harness\Plans\PlanMachine;
use Prism\Harness\Plans\PlanView;

// G-360: a patch carries changed steps by id and cannot say where a new step
// goes. A pause inserts the person's step BEFORE the current one, so a reader
// applying a patch put it last. Any change to the list of ids is a snapshot.

function patchPlan(): array
{
    $limits = new PlanLimits;
    $n = 0;
    $ids = new PlanIds(function (string $prefix) use (&$n): string {
        return $prefix.'_'.str_pad((string) ++$n, 26, '0', STR_PAD_LEFT);
    });
    $steps = [['title' => 'One', 'instruction' => 'Do one.', 'owner' => 'agent'], ['title' => 'Two', 'instruction' => 'Do two.', 'owner' => 'agent']];
    $plan = PlanMachine::activate(PlanMachine::propose(PlanMachine::checkDraft(['goal' => 'g', 'steps' => $steps], $limits), 'd', $ids, 0, null, $limits), 'd', 0, null);

    return [$plan, $ids, $limits];
}

it('patches only the changed steps while the list of steps is the same', function (): void {
    [$plan] = patchPlan();
    $before = PlanView::steps($plan);
    $plan = PlanMachine::claim($plan, $plan['steps'][0]['id'], 'w', 100, 0);

    $update = PlanView::update($plan, $before);
    expect($update['snapshot'])->toBeFalse()
        ->and(array_column($update['steps'], 'id'))->toBe([$plan['steps'][0]['id']]);
});

it('sends a snapshot, in order, when a step is inserted before the current one', function (): void {
    [$plan, $ids, $limits] = patchPlan();
    $plan = PlanMachine::claim($plan, $plan['steps'][0]['id'], 'w', 100, 0);
    $before = PlanView::steps($plan);
    [$plan] = PlanMachine::pause($plan, ['kind' => 'input', 'question' => 'Which?'], null, $ids, $limits, 0, null);

    $update = PlanView::update($plan, $before);
    expect($update['snapshot'])->toBeTrue()
        ->and(array_column($update['steps'], 'owner'))->toBe(['human', 'agent', 'agent']);
});
