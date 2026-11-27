<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\SessionLocked;
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
use Tests\Fixtures\Participant;

// G-392/G-397/G-404/G-407: a write that would supersede a parked step, made
// WHILE a run is acting on that step's approved call, does not end that call.
// The call keeps exactly one outcome, its own result, and no denial is
// recorded for it. A host tool writing on its own session is never refused;
// another run on the session is. Deterministic: the tool itself makes the
// write while it runs.

const SUPERSEDE_RUNNING_REPLACED = 'Tool call was not run because a later step replaced it';

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    $this->ran = [];
    $this->during = null;
    $ran = &$this->ran;
    $during = &$this->during;

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
            ->using(function (string $city) use (&$ran, &$during): string {
                $ran[] = $city;
                if ($during !== null) {
                    ($during)();
                }

                return "Sunny in {$city}";
            }),
    ]);

    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    Http::fakeSequence()
        ->push(['id' => 'resp_w', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'c1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'c1', 'name' => 'weather']],
            'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_w_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_w', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function supersedeRunningSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<string> every distinct result stored for the Paris call */
function supersedeRunningOutcomes(): array
{
    $outcomes = [];
    foreach (supersedeRunningSession()->thread()->storedMessages()->reorder('position')->get() as $stored) {
        $message = $stored->toPrismMessage();
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                if (($result->args['city'] ?? null) === 'Paris') {
                    $outcomes[] = (string) $result->result;
                }
            }
        }
    }

    return array_values(array_unique($outcomes));
}

/** @return array<string, Closure(Session, string): mixed> */
function supersedeRunningResumes(): array
{
    return [
        'decide' => fn (Session $session, string $approvalId): mixed => $session->decide([new ToolApprovalResponse($approvalId, true)]),
        'streamDecision' => fn (Session $session, string $approvalId): mixed => iterator_to_array($session->streamDecision([new ToolApprovalResponse($approvalId, true)]), false),
    ];
}

it('keeps one outcome when a host records a user row while the approved call runs', function (string $resume): void {
    $approvalId = supersedeRunningSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $this->refused = null;
    $this->during = function (): void {
        $this->during = null;
        try {
            supersedeRunningSession()->thread()->record([new UserMessage('Hurry.')]);
        } catch (SessionLocked $e) {
            // Serialised with the run: the write waits for it, or is refused
            // having recorded nothing.
            $this->refused = $e::class;
        }
    };

    supersedeRunningResumes()[$resume](supersedeRunningSession(), $approvalId);
    // The host writes again once the run has ended: nothing is open by then.
    if ($this->refused !== null) {
        supersedeRunningSession()->thread()->record([new UserMessage('Hurry.')]);
    }
    supersedeRunningSession()->send('');

    expect($this->ran)->toBe(['Paris'])
        ->and(supersedeRunningOutcomes())->toBe(['Sunny in Paris']);
})->with(array_keys(supersedeRunningResumes()));

it('refuses a new message while the approved call runs, and keeps one outcome', function (string $resume): void {
    $approvalId = supersedeRunningSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $this->refused = null;
    $this->during = function (): void {
        $this->during = null;
        try {
            // Another worker's session object for the same conversation.
            app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat')->send('Something else.');
        } catch (SessionLocked $e) {
            $this->refused = $e::class;
        }
    };

    supersedeRunningResumes()[$resume](supersedeRunningSession(), $approvalId);

    expect($this->refused)->toBe(SessionLocked::class)
        ->and($this->ran)->toBe(['Paris'])
        ->and(supersedeRunningOutcomes())->toBe(['Sunny in Paris']);
})->with(array_keys(supersedeRunningResumes()));

it('keeps one outcome when a host tool records a later tool-call step while the approved call runs', function (string $resume, string $laterId): void {
    $approvalId = supersedeRunningSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $this->refused = null;
    $this->during = function () use ($laterId): void {
        $this->during = null;
        try {
            // A later step, with its own gated call (its id sometimes the
            // running call's), recorded by a host tool on its own session.
            supersedeRunningSession()->thread()->record([new AssistantMessage('', [new ToolCall($laterId, 'weather', ['city' => 'Rome'])], [],
                [new ToolApprovalRequest('apr_later', $laterId, ToolApprovalRequest::ARGUMENTS_AS_SENT)])], null, ['weather']);
        } catch (SessionLocked $e) {
            $this->refused = $e::class;
        }
    };

    supersedeRunningResumes()[$resume](supersedeRunningSession(), $approvalId);

    // Never refused: the running call's result rows name its step (G-407).
    expect($this->refused)->toBeNull()
        ->and($this->ran)->toBe(['Paris'])
        ->and(supersedeRunningOutcomes())->toBe(['Sunny in Paris']);
})->with(array_keys(supersedeRunningResumes()))->with(['its own id' => ['later'], "the running call's id" => ['c1']]);

it('refuses another run on the same session while the approved call runs', function (string $resume): void {
    $approvalId = supersedeRunningSession()->send('Weather in Paris?')->pendingApprovals()[0]->approvalId;
    $this->refused = [];
    $this->during = function (): void {
        $this->during = null;
        $other = app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
        foreach ([fn () => $other->send(''), fn () => iterator_to_array($other->stream('Go on.'), false)] as $turn) {
            try {
                $turn();
            } catch (SessionLocked $e) {
                $this->refused[] = $e::class;
            }
        }
    };

    supersedeRunningResumes()[$resume](supersedeRunningSession(), $approvalId);

    expect($this->refused)->toBe([SessionLocked::class, SessionLocked::class])
        ->and($this->ran)->toBe(['Paris'])
        ->and(supersedeRunningOutcomes())->toBe(['Sunny in Paris']);
})->with(array_keys(supersedeRunningResumes()));
