<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// G-401: a decision is stored, its turn never runs because another worker
// holds the session (decide() and approve() throw SessionLocked after the
// decision is durable), and a second, opposite decision follows. A call a
// person denied never runs:
//  (a) denied, then approved: it does not run;
//  (d) approved, then denied: it does not run.

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $ran = &$this->ran;
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran[] = $city;

                return "Sunny in {$city}";
            }),
    ]);

    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    Http::fakeSequence()
        ->push(['id' => 'resp_l', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'c1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'c1', 'name' => 'weather']],
            'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_l_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_l', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function lockedTurnSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return array<string, Closure(Session, string, bool): mixed> */
function lockedTurnPaths(): array
{
    return [
        'decide' => fn (Session $session, string $approvalId, bool $approved): mixed => $session->decide([new ToolApprovalResponse($approvalId, $approved)]),
        'approve or deny' => fn (Session $session, string $approvalId, bool $approved): mixed => $session->approve($approvalId, $approved),
    ];
}

/** Park, store `$first` while another worker holds the session, then decide `$second`. */
function lockedTurnScenario(string $path, bool $first, bool $second): array
{
    $approvalId = lockedTurnSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;

    // Another worker holds the session.
    $release = lockedTurnSession()->holdFencedLock(30, 0);
    $locked = null;
    try {
        lockedTurnPaths()[$path](lockedTurnSession(), $approvalId, $first);
    } catch (SessionLocked $e) {
        $locked = $e::class;
    } finally {
        $release();
    }

    lockedTurnPaths()[$path](lockedTurnSession(), $approvalId, $second);
    lockedTurnSession()->send('');

    return [$locked];
}

it('(a) never runs a call denied while the session was held, when an approval follows', function (string $path): void {
    [$locked] = lockedTurnScenario($path, false, true);

    expect($locked)->toBe(SessionLocked::class)
        ->and($this->ran)->toBe([]);
})->with(array_keys(lockedTurnPaths()));

it('(d) never runs a call approved while the session was held, when a denial follows', function (string $path): void {
    [$locked] = lockedTurnScenario($path, true, false);

    expect($locked)->toBe(SessionLocked::class)
        ->and($this->ran)->toBe([]);
})->with(array_keys(lockedTurnPaths()));
