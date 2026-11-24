<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\CanonicalJson;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// A decision is checked and written under the session's fenced lock, as
// streamDecision() already was. Before, decide(), approve() and deny() checked
// that the id was pending and wrote the decision with no lock held, and only
// then took the lock to resume: a session another request held still got the
// decision written, the caller got SessionLocked, and a retry was refused as
// "not pending". Two racing answers for one id could both be written.
//
// And every reader honours the FIRST recorded answer for an approval id, so a
// thread that holds two answers never runs a call its first answer denied.

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search', 'weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = ['search' => 0, 'weather' => 0];
    $ran = &$this->ran;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(function (string $query) use (&$ran): string {
                $ran['search']++;

                return 'The tigers game is today at 3pm in detroit';
            }),
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran): string {
                $ran['weather']++;

                return 'The weather will be 75 and sunny';
            }),
    ]);

    $fixture = fn (string $name): string => (string) file_get_contents(__DIR__.'/Fixtures/openai/'.$name.'.json');
    Http::fakeSequence()
        ->push($fixture('tool-calls'))
        ->push($fixture('after-tools'))
        ->push($fixture('text-reply'))
        ->push($fixture('text-reply'));
});

function decisionLockSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

function decisionLockRowCount(): int
{
    return Thread::query()->firstOrFail()->storedMessages()->count();
}

/** @return array<string, callable(Session, string): mixed> */
function decisionLockPaths(): array
{
    return [
        'approve' => fn (Session $session, string $id): mixed => $session->approve($id),
        'deny' => fn (Session $session, string $id): mixed => $session->deny($id, 'no'),
        'decide' => fn (Session $session, string $id): mixed => $session->decide([new ToolApprovalResponse($id, true)]),
    ];
}

it('writes nothing while another holder has the session lock, and the decision can be retried', function (string $path): void {
    $pending = decisionLockSession()->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    $rows = decisionLockRowCount();
    $caught = null;

    decisionLockSession()->lock(function () use ($path, $pending, &$caught): void {
        try {
            decisionLockPaths()[$path](decisionLockSession(), $pending->approvalId);
        } catch (Throwable $failure) {
            $caught = $failure;
        }
    }, waitSeconds: 0);

    expect($caught)->toBeInstanceOf(SessionLocked::class)
        ->and(decisionLockRowCount())->toBe($rows)
        ->and(decisionLockSession()->thread()->pendingApprovalIds())->toBe([$pending->approvalId])
        ->and(Http::recorded())->toHaveCount(1)
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 0]);

    // Still pending, so the person can answer, and answer differently.
    $resumed = decisionLockSession()->approve($pending);

    expect($resumed->awaitingApproval())->toBeFalse()
        ->and($this->ran)->toBe(['search' => 1, 'weather' => 1]);
})->with(['approve', 'deny', 'decide']);

it('never runs a call whose first recorded answer denied it, though a later one approved it', function (string $resume): void {
    $session = decisionLockSession();
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    $runId = $session->run()['id'] ?? null;

    // Two answers for one id, as two racing requests wrote them before the
    // check moved under the lock: a denial, then an approval.
    $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending->approvalId, false, 'no')])], $runId);
    $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending->approvalId, true)])], $runId);

    $thread = decisionLockSession()->thread();
    expect($thread->approvalDecisions())->toBe([$pending->approvalId => false])
        ->and($thread->approvedCallsAwaitingResult('weather', CanonicalJson::digest(['city' => 'Detroit'])))->toBe(0);

    // Continued the way a decision resumes a run: an empty prompt.
    if ($resume === 'send') {
        decisionLockSession()->send('');
    } else {
        iterator_to_array(decisionLockSession()->streamSteps(''), false);
    }

    expect($this->ran)->toBe(['search' => 1, 'weather' => 0]);
})->with(['send', 'streamSteps']);

it('runs a call whose first recorded answer approved it, though a later one denied it', function (): void {
    $session = decisionLockSession();
    $pending = $session->send('What time is the game, and do I need a coat?')->pendingApprovals()[0];
    $runId = $session->run()['id'] ?? null;

    $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending->approvalId, true)])], $runId);
    $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse($pending->approvalId, false, 'no')])], $runId);

    decisionLockSession()->send('');

    expect($this->ran)->toBe(['search' => 1, 'weather' => 1]);
});
