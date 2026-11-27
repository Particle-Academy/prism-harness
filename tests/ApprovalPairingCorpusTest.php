<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// The cross-language approval-pairing corpus (harness-approval-pairing, G-392).
// Every row's `expected` was written by hand before any language ran. This
// runner drives the real runtime through Prism's OpenAI Responses handler over
// faked HTTP: the first reply is the row's calls, each with `id` and `call_id`
// equal to the row's id, and every later reply is the text `done`. `echo` is
// gated by mode and `free` is not. Each round decides the listed approvals with
// one decide() call, which resumes once with an empty prompt; a decision the
// harness refuses is swallowed, and the round still resumes once.

/** What the corpus tools did. Static because a tool closure outlives the test's scope. */
final class PairingCorpusRan
{
    /** @var list<int> */
    public static array $echo = [];

    public static int $free = 0;
}

/** @return list<array<string, mixed>> */
function approvalPairingCorpus(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/harness-approval-pairing.json'), true)['cases'];
}

function approvalPairingSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('corpus');
}

/**
 * @param  array<string, mixed>  $row
 * @return array{approvals: int, free: int, ran: list<int>, decided: list<bool|null>}
 */
function recordApprovalPairingRow(array $row): array
{
    PairingCorpusRan::$echo = [];
    PairingCorpusRan::$free = 0;

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['echo', 'free']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['echo']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 6);

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('echo')->for('Echo.')->withNumberParameter('n', 'A number')
            ->using(function (int|float $n): string {
                PairingCorpusRan::$echo[] = (int) $n;

                return 'ok';
            }),
        (new Tool)->as('free')->for('Free.')
            ->using(function (): string {
                PairingCorpusRan::$free++;

                return 'free ok';
            }),
    ]);

    $count = 0;
    Http::fake(function (Request $request) use ($row, &$count) {
        $count++;
        $output = $count === 1
            ? array_map(fn (array $call): array => [
                'id' => $call['id'], 'type' => 'function_call', 'status' => 'completed',
                'arguments' => json_encode((object) $call['args']), 'call_id' => $call['id'], 'name' => $call['name'],
            ], $row['calls'])
            : [['id' => 'msg_1', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'annotations' => [], 'text' => 'done', 'refusal' => null]]]];

        return Http::response(['id' => 'resp_'.$count, 'object' => 'response', 'created_at' => 1741989983, 'status' => 'completed',
            'model' => 'gpt-4o-2024-08-06', 'output' => $output,
            'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2]]);
    });

    $pending = approvalPairingSession()->send('go')->pendingApprovals();

    foreach ($row['rounds'] as $round) {
        $decisions = array_map(
            fn (array $decision): ToolApprovalResponse => new ToolApprovalResponse($pending[$decision[0]]->approvalId, $decision[1]),
            $round,
        );
        try {
            approvalPairingSession()->decide($decisions);
        } catch (ApprovalNotPending) {
            approvalPairingSession()->send('');
        }
    }

    $ran = PairingCorpusRan::$echo;
    sort($ran);
    // The decision the thread RECORDED for each approval, in issue order
    // (0.2.0): the first answer, or null when none was recorded. On a step
    // whose call ids repeat every recorded decision is a denial.
    $recorded = approvalPairingSession()->thread()->approvalDecisions();
    $decided = array_map(fn ($request): ?bool => $recorded[$request->approvalId] ?? null, $pending);

    return ['approvals' => count($pending), 'free' => PairingCorpusRan::$free, 'ran' => $ran, 'decided' => $decided];
}

it('has rows', function (): void {
    expect(approvalPairingCorpus())->not->toBeEmpty();
});

it('records the corpus row', function (array $row): void {
    expect(recordApprovalPairingRow($row))->toBe($row['expected']);
})->with(function (): array {
    $rows = [];
    foreach (approvalPairingCorpus() as $row) {
        $rows[$row['id'].': '.$row['title']] = [$row];
    }

    return $rows;
});
