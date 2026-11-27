<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\ApprovalNotPending;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\Participant;
use Tests\Support\SentRequests;

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

    /** Run once inside the next echo call's body (0.6.0, round 17): a host writing while it runs. */
    public static ?Closure $during = null;
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
 * @return array{approvals: int, free: int, ran: list<int>, decided: list<bool|null>, outcomes?: list<string|null>, later_outcomes?: list<string|null>, refusals?: list<string|null>}
 */
function recordApprovalPairingRow(array $row): array
{
    PairingCorpusRan::$echo = [];
    PairingCorpusRan::$free = 0;
    PairingCorpusRan::$during = null;

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
                if (PairingCorpusRan::$during !== null) {
                    $during = PairingCorpusRan::$during;
                    PairingCorpusRan::$during = null;
                    $during();
                }

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
        // 0.3.0 (G-404): the new message's own request fails, as a provider outage does.
        if (($row['supersede'] ?? null) === 'new_message_failed' && $count === 2) {
            throw new RuntimeException('provider down');
        }
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

    $parked = approvalPairingSession()->send('go');
    $pending = $parked->pendingApprovals();

    // DECISION B (0.2.0): a row that supersedes the parked step, recorded by
    // a host (outside the run that owns the step), or a new message.
    match ($row['supersede'] ?? null) {
        null => null,
        'assistant_tool_step' => approvalPairingSession()->thread()->record([new AssistantMessage('', [new ToolCall('later', 'free', [])])]),
        'assistant_text' => approvalPairingSession()->thread()->record([new AssistantMessage('A later answer.')]),
        'user' => approvalPairingSession()->thread()->record([new UserMessage('A later message.')]),
        // A caller naming the step's OWN run id: still outside it.
        'user_owning_run_id' => approvalPairingSession()->thread()->record([new UserMessage('A later message.')], $parked->runId),
        'new_message' => approvalPairingSession()->send('Something else.'),
        // 0.4.0 (G-387 round 15): a host records a COPY of the parked step
        // that keeps its approval requests but alters each call's arguments
        // (n + 100), before any decision; or, `_after_approval`, after every
        // pending approval was recorded approved and before the resume.
        'altered_copy' => approvalPairingAlteredCopy($row, $pending),
        'altered_copy_after_approval' => (function () use ($row, $pending): void {
            $session = approvalPairingSession();
            (fn () => $this->recordDecisions(array_map(fn ($p): ToolApprovalResponse => new ToolApprovalResponse($p->approvalId, true), $pending)))->call($session);
            approvalPairingAlteredCopy($row, $pending);
            approvalPairingSession()->send('');
        })(),
        // 0.5.0 (G-387 round 16): a host records a result for the first
        // gated call before any decision: its approval is closed (item 2).
        'host_result' => (function () use ($row): void {
            $call = array_values(array_filter($row['calls'], fn (array $call): bool => $call['name'] === 'echo'))[0];
            approvalPairingSession()->thread()->record([new ToolResultMessage([new ToolResult($call['id'], $call['name'], $call['args'], 'host')])]);
        })(),
        // 0.5.0 (G-387 round 16): every pending approval recorded approved,
        // one resume runs them, then a host records a denial of each: it
        // never stood (item 1).
        'denial_after_run' => (function () use ($pending): void {
            $session = approvalPairingSession();
            (fn () => $this->recordDecisions(array_map(fn ($p): ToolApprovalResponse => new ToolApprovalResponse($p->approvalId, true), $pending)))->call($session);
            approvalPairingSession()->send('');
            approvalPairingSession()->thread()->record([new ToolResultMessage([], array_map(
                fn ($p): ToolApprovalResponse => new ToolApprovalResponse($p->approvalId, false, 'Too late.'), $pending))]);
        })(),
        // 0.6.0 (G-387 round 17, item 1): every pending approval recorded
        // approved, and WHILE the resume runs the call a host records a user
        // row (or an assistant text row); then one more turn, `later`.
        'concurrent_user', 'concurrent_text' => (function () use ($row, $pending): void {
            $session = approvalPairingSession();
            (fn () => $this->recordDecisions(array_map(fn ($p): ToolApprovalResponse => new ToolApprovalResponse($p->approvalId, true), $pending)))->call($session);
            PairingCorpusRan::$during = fn () => approvalPairingSession()->thread()->record([$row['supersede'] === 'concurrent_user'
                ? new UserMessage('A host row while the call runs.') : new AssistantMessage('A host note while the call runs.')]);
            approvalPairingSession()->send('');
            approvalPairingSession()->send('later');
        })(),
        // 0.3.0 (G-404): a new message whose turn then fails.
        'new_message_failed' => (function (): void {
            try {
                approvalPairingSession()->send('Something else.');
            } catch (RuntimeException) {
                // The turn failed; what it recorded before it stands.
            }
        })(),
    };

    // SEGMENT OWNERSHIP (0.2.0, round 13b): a later step a host records
    // after the park, each echo call asking its own approval
    // (`apr_later_<k>`, k counting the step's echo calls), then ONE result
    // row a host records right after it.
    $later = $row['later'] ?? null;
    $laterIds = [];
    if ($later !== null) {
        $requests = [];
        foreach ($later['calls'] as $call) {
            if ($call['name'] === 'echo') {
                $laterIds[] = $id = 'apr_later_'.count($laterIds);
                $requests[] = new ToolApprovalRequest($id, $call['id'], ToolApprovalRequest::ARGUMENTS_AS_SENT);
            }
        }
        approvalPairingSession()->thread()->record([new AssistantMessage('', array_map(
            fn (array $call): ToolCall => new ToolCall($call['id'], $call['name'], $call['args']), $later['calls']), [], $requests)]);
        $result = $later['result'];
        approvalPairingSession()->thread()->record([new ToolResultMessage(
            [new ToolResult($result['id'], $result['name'], $result['args'], $result['result'])],
            array_map(fn (int $k): ToolApprovalResponse => new ToolApprovalResponse($laterIds[$k], true), $result['approved']),
        )]);
    }

    $refusals = [];
    // The later step's rounds first, while its approvals are pending, then
    // the row's rounds on the first step's approvals.
    $rounds = [
        ...array_map(fn (array $round): array => [$round, $laterIds], $later['rounds'] ?? []),
        ...array_map(fn (array $round): array => [$round, array_map(fn ($p): string => $p->approvalId, $pending)], $row['rounds']),
    ];
    foreach ($rounds as [$round, $ids]) {
        $decisions = array_map(
            fn (array $decision): ToolApprovalResponse => new ToolApprovalResponse($ids[$decision[0]], $decision[1]),
            $round,
        );
        try {
            approvalPairingSession()->decide($decisions);
            $refusals[] = null;
        } catch (ApprovalNotPending $refused) {
            // The code each round was refused with, or null (0.2.0).
            $refusals[] = $refused->code();
            approvalPairingSession()->send('');
        }
    }
    for ($i = 0; $i < ($later['resumes'] ?? 0); $i++) {
        approvalPairingSession()->send('');
    }

    $ran = PairingCorpusRan::$echo;
    sort($ran);
    // The decision the thread RECORDED for each approval, in issue order
    // (0.2.0): the first answer, or null when none was recorded. On a step
    // whose call ids repeat every recorded decision is a denial.
    $recorded = approvalPairingSession()->thread()->approvalDecisions();
    $decided = array_map(fn ($request): ?bool => $recorded[$request->approvalId] ?? null, $pending);

    $outcome = ['approvals' => count($pending), 'free' => PairingCorpusRan::$free, 'ran' => $ran, 'decided' => $decided];
    if (array_key_exists('supersede', $row) || $later !== null) {
        // The result each call of a step ended with, in call order: its
        // first result IN ITS STEP'S SEGMENT (the rows after the step and
        // before the next assistant row with tool calls), or null.
        $segments = approvalPairingSegments();
        $outcome['outcomes'] = approvalPairingSegmentOutcomes($row['calls'], $segments[0] ?? []);
        if ($later !== null) {
            $outcome['later_outcomes'] = approvalPairingSegmentOutcomes($later['calls'], $segments[1] ?? []);
        }
        $outcome['refusals'] = $refusals;
    }
    if (in_array($row['supersede'] ?? null, ['concurrent_user', 'concurrent_text'], true)) {
        // 0.6.0: whether every request the fake model received answers every
        // call of every step it carries, exactly once, in that step's own
        // result run.
        $outcome['sent'] = SentRequests::since() === [];
    }

    return $outcome;
}

/**
 * The host's copy of the parked step (0.4.0): every call again under its id
 * and tool, its `n` + 100, and every request kept, under the same approval id
 * and call id.
 *
 * @param  array<string, mixed>  $row
 * @param  list<ToolApprovalRequest>  $pending
 */
function approvalPairingAlteredCopy(array $row, array $pending): void
{
    approvalPairingSession()->thread()->record([new AssistantMessage('', array_map(
        fn (array $call): ToolCall => new ToolCall($call['id'], $call['name'], ['n' => ($call['args']['n'] ?? 0) + 100]), $row['calls']), [],
        array_map(fn (ToolApprovalRequest $p): ToolApprovalRequest => new ToolApprovalRequest($p->approvalId, $p->toolCallId, ToolApprovalRequest::ARGUMENTS_AS_SENT), $pending))],
        toolNames: ['echo', 'free']);
}

/**
 * The tool_results entries of each step's segment, oldest first: one list
 * per assistant row with tool calls, in thread order.
 *
 * @return list<list<array<string, mixed>>>
 */
function approvalPairingSegments(): array
{
    $segments = [];
    foreach (approvalPairingSession()->thread()->storedMessages()->orderBy('position')->get() as $stored) {
        if ($stored->type === 'assistant' && ($stored->payload['tool_calls'] ?? []) !== []) {
            $segments[] = [];

            continue;
        }
        if ($segments !== [] && $stored->type === 'tool_result') {
            foreach ($stored->payload['tool_results'] ?? [] as $entry) {
                $segments[count($segments) - 1][] = $entry;
            }
        }
    }

    return $segments;
}

/**
 * The first result each call got in its segment, in call order, or null. An
 * entry is a call's by exact arguments, then id and tool name, then id: the
 * first call so matched with no result yet; an entry whose match already has
 * one is a repeat, and an entry that matches no call counts for nothing.
 *
 * @param  list<array<string, mixed>>  $calls
 * @param  list<array<string, mixed>>  $entries
 * @return list<string|null>
 */
function approvalPairingSegmentOutcomes(array $calls, array $entries): array
{
    $first = array_fill(0, count($calls), null);
    foreach ($entries as $entry) {
        $args = is_array($entry['args'] ?? null) ? $entry['args'] : [];
        foreach ([
            fn (array $call): bool => $call['id'] === $entry['tool_call_id'] && $call['name'] === $entry['tool_name'] && $call['args'] == $args,
            fn (array $call): bool => $call['id'] === $entry['tool_call_id'] && $call['name'] === $entry['tool_name'],
            fn (array $call): bool => $call['id'] === $entry['tool_call_id'],
        ] as $matches) {
            $matched = array_keys(array_filter($calls, $matches));
            if ($matched === []) {
                continue;
            }
            $open = array_values(array_filter($matched, fn (int $at): bool => $first[$at] === null));
            if ($open !== []) {
                $first[$open[0]] = (string) $entry['result'];
            }

            break;
        }
    }

    return $first;
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
