<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Prism\Harness\Contracts\CallCostObserver;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\CallReservation;
use Prism\Harness\Subagents\CallSettlement;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunLedger;
use Prism\Harness\Tangents\DatabaseRunLedgerStore;
use Prism\Harness\Tangents\TangentPolicy;

function tangentLedgerStore(): DatabaseRunLedgerStore
{
    return new DatabaseRunLedgerStore(DB::connection());
}

it('keeps ordinary in-memory accounting independent of a database', function (): void {
    DB::enableQueryLog();
    DB::flushQueryLog();
    $ledger = RunLedger::start('ordinary');
    $reservation = $ledger->reserveCost(new RunBudget(4, 1), 0.3);
    $ledger->recordSteps(1);
    $ledger->settleCost($reservation, 0.2);

    expect($ledger->steps())->toBe(1)
        ->and($ledger->costUsd())->toBe(0.2)
        ->and(DB::getQueryLog())->toBe([]);
});

it('shares reservations and settled spend across independently resumed ledgers', function (): void {
    $store = tangentLedgerStore();
    $root = RunLedger::start('shared')->persistUsing($store);
    $first = RunLedger::resume('shared', $store);
    $second = RunLedger::resume('shared', $store);
    $budget = new RunBudget(10, 1.0);
    $reservation = $first->reserveCost($budget, 0.75);

    expect(fn () => $second->reserveCost($budget, 0.5))->toThrow(RunNotPermitted::class);
    expect($second->reservedCostUsd())->toBe(0.75);
    $first->recordSteps(1);
    $second->settleCost($reservation, 0.6);

    expect($root->steps())->toBe(1)
        ->and($first->costUsd())->toBe(0.6)
        ->and($root->remainingCost($budget))->toBe(0.4);
    expect(fn () => $first->settleCost($reservation, 0.1))->toThrow(LogicException::class);
    $second->cancel('host_stopped');
    expect($root->cancelled())->toBeTrue()->and($first->cancelReason())->toBe('host_stopped');
    expect(fn () => $first->assertCallAvailable($budget))->toThrow(RunNotPermitted::class);
});

it('retains an in-flight reservation after a simulated worker loss and charges unknown usage at its bound', function (): void {
    $store = tangentLedgerStore();
    $root = RunLedger::start('crashed')->persistUsing($store);
    $reservation = $root->reserveCost(new RunBudget(5, 1), 0.8);
    unset($root);
    $resumed = RunLedger::resume('crashed', $store);

    expect($resumed->reservedCostUsd())->toBe(0.8);
    expect(fn () => $resumed->reserveCost(new RunBudget(5, 1), 0.3))->toThrow(RunNotPermitted::class);
    $resumed->settleCost($reservation, null);
    expect(RunLedger::resume('crashed', $store)->costUsd())->toBe(0.8);
});

it('cannot reset an existing root or resume a missing root', function (): void {
    $store = tangentLedgerStore();
    RunLedger::start('existing')->persistUsing($store)->recordCost(0.7);
    expect(fn () => RunLedger::start('existing')->persistUsing($store))->toThrow(QueryException::class);
    expect(RunLedger::resume('existing', $store)->costUsd())->toBe(0.7);
    expect(fn () => RunLedger::resume('missing', $store))->toThrow(LogicException::class);
});

it('does not restart the original wall clock when a worker resumes', function (): void {
    $store = tangentLedgerStore();
    (new RunLedger('expired', microtime(true) - 30))->persistUsing($store);
    $resumed = RunLedger::resume('expired', $store);
    expect(fn () => $resumed->reserveCost(new RunBudget(5, 1, maxSeconds: 10), 0.1))->toThrow(RunNotPermitted::class);
});

it('refuses corrupt state and nonfinite settlement without losing the held reservation', function (): void {
    $store = tangentLedgerStore();
    $root = RunLedger::start('invalid')->persistUsing($store);
    $reservation = $root->reserveCost(new RunBudget(5, 1), 0.4);
    expect(fn () => $root->settleCost($reservation, NAN))->toThrow(LogicException::class);
    expect(RunLedger::resume('invalid', $store)->reservedCostUsd())->toBe(0.4);
    DB::table('harness_run_ledgers')->where('id', 'invalid')->update(['state' => '{"version":1}']);
    expect(fn () => RunLedger::resume('invalid', $store))->toThrow(LogicException::class);
});

it('will not resume a root while silently dropping its selected cost observer', function (): void {
    $observer = new class implements CallCostObserver
    {
        public function reserving(CallReservation $call, RunContext $run): void {}

        public function settled(CallSettlement $call, RunContext $run): void {}
    };
    $store = tangentLedgerStore();
    $ledger = RunLedger::start('observed');
    $ledger->inheritCostObserver($observer);
    $ledger->persistUsing($store);
    expect(fn () => RunLedger::resume('observed', $store))->toThrow(LogicException::class);
    expect(RunLedger::resume('observed', $store, $observer)->inheritCostObserver(null))->toBe($observer);
});

it('implements the full narrowing policy matrix without granting a new actor', function (): void {
    $expected = [
        'none' => ['none', 'none', 'none', 'none'],
        'user' => ['none', 'user', 'none', 'user'],
        'agent' => ['none', 'none', 'agent', 'agent'],
        'both' => ['none', 'user', 'agent', 'both'],
    ];
    foreach (TangentPolicy::cases() as $mode) {
        foreach (TangentPolicy::cases() as $index => $restriction) {
            expect($mode->intersect($restriction)->value)->toBe($expected[$mode->value][$index]);
        }
        expect($mode->permits('forged_actor'))->toBeFalse();
    }
});
