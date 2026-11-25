<?php

declare(strict_types=1);

use Prism\Harness\Flow\WorkflowBudgets;
use Prism\Harness\PrismHarness;
use Prism\Harness\Stores\MemoryWorkflowLedgerStore;
use Tests\Fixtures\Participant;

// A session's saved run key and an engine run's budget live in one store. An
// engine run key is the engine's or the host's string, so it gets a prefix of
// its own: no run key, whatever it spells, opens the session's key record as
// if it were a budget.

/** @return array<string, mixed> */
function keySpaceRead(MemoryWorkflowLedgerStore $store, string $key): array
{
    return $store->transact($key, [], static fn (array $state): array => [$state, $state]);
}

it('keeps an engine run key out of the session key records, whatever it spells', function (): void {
    $store = new MemoryWorkflowLedgerStore;
    $budgets = new WorkflowBudgets($store, []);
    $session = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');

    $sessionKey = $budgets->sessionRunKey($session);
    $record = WorkflowBudgets::SESSION_RECORD.$session->key();

    // An engine key equal to the session's record key.
    $budget = $budgets->open($record, ['max_tool_calls' => 3]);
    $budget->takeToolCall();

    expect(keySpaceRead($store, $record))->toBe(['run_key' => $sessionKey])
        ->and($budgets->sessionRunKey($session))->toBe($sessionKey)
        ->and($budget->runKey)->toBe($record);

    // And the session's own budget is not the colliding run's.
    $own = $budgets->forSession($session, ['max_tool_calls' => 3]);
    expect($own->runKey)->toBe($sessionKey);
    $own->takeToolCall();
    $own->takeToolCall();
    $own->takeToolCall();
    $budget->takeToolCall();
    $budget->takeToolCall();
});
