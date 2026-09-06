<?php

declare(strict_types=1);

use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunLedger;

/**
 * A nested budget and the ledger that enforces it have to be in the SAME UNIT.
 *
 * `nestedWithin()` used to compute a child's budget as what REMAINS, while
 * `exhaustion()` compares the ledger's CUMULATIVE totals against it. Those are
 * different quantities, and the comparison is wrong the moment a parent has
 * spent anything at all — which is every real subagent, because a parent that
 * has not run yet has no reason to spawn one.
 *
 * The nesting tests that existed did not catch it because they all spawn from
 * an UNSPENT parent, where remaining and cumulative happen to be equal. It
 * takes a nearly-spent parent to pull the two apart.
 *
 * Reported as prism-harness#10.
 */
it('gives a child of a nearly spent parent the steps that actually remain', function (): void {
    // The reproduction from the issue, exactly: a parent allowed 8 steps that
    // has already taken 7, spawning a child that asks for 2. One step is left,
    // so the child must get one -- not zero.
    $parent = new RunBudget(maxSteps: 8);
    $ledger = RunLedger::start('run_root');
    $ledger->recordSteps(7);

    $child = (new RunBudget(maxSteps: 2))->nestedWithin($parent, $ledger);

    // Before the fix this was min(2, 8 - 7) = 1, and exhaustion then asked
    // `7 >= 1` and refused the child outright.
    expect($ledger->exhaustion($child))->toBeNull();

    // And it must stop at the parent's ceiling rather than running past it.
    $ledger->recordSteps(1);
    expect($ledger->exhaustion($child))->not->toBeNull();
});

it('never lets a child raise the parent ceiling', function (): void {
    // The other direction, and the reason the budget is a ceiling rather than
    // an allowance: a child asking for more than the whole parent budget is
    // capped by the parent, not granted its request.
    $parent = new RunBudget(maxSteps: 4);
    $ledger = RunLedger::start('run_root');
    $ledger->recordSteps(1);

    $child = (new RunBudget(maxSteps: 100))->nestedWithin($parent, $ledger);

    expect($child->maxSteps)->toBe(4);
});

it('still holds when the parent has spent nothing', function (): void {
    // The case the old tests covered. Kept, because a fix that only works for
    // spent parents would be the same defect mirrored.
    $parent = new RunBudget(maxSteps: 8);
    $ledger = RunLedger::start('run_root');

    $child = (new RunBudget(maxSteps: 2))->nestedWithin($parent, $ledger);

    expect($child->maxSteps)->toBe(2)
        ->and($ledger->exhaustion($child))->toBeNull();
});

it('keeps cost in the same unit as the ledger', function (): void {
    // maxCostUsd had the identical mismatch: computed as remaining in
    // nestedWithin(), compared against the cumulative total in exhaustion().
    $parent = new RunBudget(maxSteps: 100, maxCostUsd: 1.00);
    $ledger = RunLedger::start('run_root');
    $ledger->recordCost(0.90);

    $child = (new RunBudget(maxSteps: 10, maxCostUsd: 0.05))->nestedWithin($parent, $ledger);

    // 0.10 USD remains and the child asked for 0.05, so it may spend.
    expect($ledger->exhaustion($child))->toBeNull();

    $ledger->recordCost(0.05);
    expect($ledger->exhaustion($child))->not->toBeNull();
});
