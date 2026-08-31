<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Step;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\Participant;

/*
|--------------------------------------------------------------------------
| H-06 — approvals: declared here, originated by core, persisted by us
|--------------------------------------------------------------------------
*/

it('marks a mode-declared tool as requiring approval before it is offered', function (): void {
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('execute_op')->for('Run')->using(fn (): string => 'ran'),
        (new Tool)->as('read_op')->for('Read')->using(fn (): string => 'read'),
    ]);
    config()->set('harness.agent.modes.chat.tools', ['execute_op', 'read_op']);
    config()->set('harness.agent.modes.chat.requires_approval', ['execute_op']);
    $fake = Prism::fake([TextResponseFake::make()->withText('done')]);

    harness()->for(Participant::create(['name' => 'Ada']))->session('chat')->send('go');

    $fake->assertRequest(function (array $requests): void {
        $byName = [];
        foreach ($requests[0]->tools() as $tool) {
            $byName[$tool->name()] = $tool;
        }

        expect($byName['execute_op']->hasApprovalConfigured())->toBeTrue()
            // Only the declared one. A gate on everything is a gate nobody reads.
            ->and($byName['read_op']->hasApprovalConfigured())->toBeFalse();
    });
});

it('reads a paused run as awaiting approval rather than as a failure', function (): void {
    // The distinction that matters most here: a caller treating this as an
    // error retries, and retrying discards the half-executed action a person
    // was asked to authorise.
    Prism::fake([
        TextResponseFake::make()
            ->withText('')
            ->withFinishReason(FinishReason::Pause)
            ->withSteps(collect([
                new Step(
                    text: '',
                    finishReason: FinishReason::Pause,
                    toolCalls: [new ToolCall('call_1', 'execute_op', [])],
                    toolResults: [],
                    providerToolCalls: [],
                    usage: new Usage(1, 1),
                    meta: new Meta('x', 'y'),
                    messages: [],
                    systemPrompts: [],
                    toolApprovalRequests: [new ToolApprovalRequest('apr_1', 'call_1')],
                ),
            ])),
    ]);

    $response = harness()->for(Participant::create(['name' => 'Ada']))->session('chat')->send('go');

    expect($response->awaitingApproval())->toBeTrue()
        ->and($response->pendingApprovals())->toHaveCount(1)
        ->and($response->pendingApprovals()[0]->approvalId)->toBe('apr_1');
});

it('records an approval decision in the thread so another worker can read it', function (): void {
    // Storage, not memory. The approval granted this morning has to be visible
    // to the worker that resumes tonight, after a deploy.
    Prism::fake([
        TextResponseFake::make()->withText('resumed')->withMessages(collect([new AssistantMessage('resumed')])),
    ]);
    $session = harness()->for(Participant::create(['name' => 'Ada']))->session('chat');

    $session->approve('apr_1', true, 'looks fine');

    $stored = $session->thread()->storedMessages()->get()
        ->filter(fn ($m): bool => isset($m->payload['tool_approval_responses']))
        ->first();

    expect($stored)->not->toBeNull()
        ->and($stored->payload['tool_approval_responses'][0]['approval_id'])->toBe('apr_1')
        ->and($stored->payload['tool_approval_responses'][0]['approved'])->toBeTrue();
});

it('records a denial as a decision rather than as an absence', function (): void {
    // Prism denies by default when it finds no response, so a lost approval
    // already fails closed. An explicit denial still has to be stored: "nobody
    // answered" and "a person said no" are different facts.
    Prism::fake([TextResponseFake::make()->withText('ok')]);
    $session = harness()->for(Participant::create(['name' => 'Ada']))->session('chat');

    $session->deny('apr_2', 'not on production');

    $stored = $session->thread()->storedMessages()->get()
        ->filter(fn ($m): bool => isset($m->payload['tool_approval_responses']))
        ->first();

    expect($stored->payload['tool_approval_responses'][0]['approved'])->toBeFalse()
        ->and($stored->payload['tool_approval_responses'][0]['reason'])->toBe('not on production');
});

/*
|--------------------------------------------------------------------------
| H-08 — the harness event stream
|--------------------------------------------------------------------------
*/

it('emits a correlated run lifecycle on the harness stream', function (): void {
    Event::fake([RunStarted::class, RunFinished::class]);
    Prism::fake([TextResponseFake::make()->withText('done')]);

    $response = harness()->for(Participant::create(['name' => 'Ada']))->session('chat')->send('go');

    Event::assertDispatched(RunStarted::class, fn (RunStarted $e): bool => $e->runId === $response->runId && $e->mode === 'chat');
    Event::assertDispatched(RunFinished::class, function (RunFinished $e) use ($response): bool {
        // Lineage on every event, so a consuming app can join this to its own
        // stream without adopting ours.
        return $e->runId === $response->runId
            && $e->correlation()['root_run_id'] === $response->runId
            && $e->awaitingApproval === false;
    });
});

it('emits only the exception class when a run fails, never the message', function (): void {
    // A provider message can carry a request URL or a key inside one, and an
    // event may end up on a screen.
    Event::fake([RunFailed::class]);
    config()->set('harness.agent.modes.chat.subagents', ['ghost' => ['mode' => 'nope']]);

    try {
        harness()->for(Participant::create(['name' => 'Ada']))->session('chat')->send('go');
    } catch (Throwable) {
        // expected
    }

    Event::assertDispatched(RunFailed::class, fn (RunFailed $e): bool => $e->exception === InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| H-09 / H-11 — the controller's doctor, and the audit trail
|--------------------------------------------------------------------------
*/

it('resolves every configured mode, not just the default one', function (): void {
    // A mode nobody has entered yet keeps its misconfiguration until someone
    // switches to it. `all()` is what finds that on a Tuesday.
    config()->set('harness.agent.modes.other', ['system_prompt' => 'x', 'tools' => [], 'skills' => [], 'max_steps' => 2]);

    expect(array_keys(app(ModeRegistry::class)->all()))->toContain('chat', 'other');
});

it('reports a healthy configuration', function (): void {
    $this->artisan('harness:doctor')->assertSuccessful();
});

it('fails when an approval gate names a tool the mode never offers', function (): void {
    // A gate on nothing, which reads to an auditor exactly like a gate.
    config()->set('harness.agent.modes.chat.tools', ['read_op']);
    config()->set('harness.agent.modes.chat.requires_approval', ['execute_op']);

    $this->artisan('harness:doctor')->assertFailed();
});

it('fails when a mode names a subagent whose mode does not exist', function (): void {
    config()->set('harness.agent.modes.chat.subagents', ['ghost' => ['mode' => 'no_such_mode']]);

    $this->artisan('harness:doctor')->assertFailed();
});

it('records which tools a run invoked, by name and not by argument', function (): void {
    // Names audit a guardrail; arguments are PII and already live in
    // prism-opentelemetry behind an opt-in capture gate.
    app(ToolRegistry::class)->register((new Tool)->as('read_op')->for('Read')->using(fn (): string => 'r'));
    config()->set('harness.agent.modes.chat.tools', ['read_op']);
    Prism::fake([
        TextResponseFake::make()->withText('done')->withSteps(collect([
            new Step(
                text: 'done',
                finishReason: FinishReason::Stop,
                toolCalls: [new ToolCall('call_1', 'read_op', ['secret' => 'hunter2'])],
                toolResults: [],
                providerToolCalls: [],
                usage: new Usage(1, 1),
                meta: new Meta('x', 'y'),
                messages: [],
                systemPrompts: [],
            ),
        ])),
    ]);

    $ada = Participant::create(['name' => 'Ada']);
    harness()->for($ada)->session('chat')->send('go');

    $run = harness()->for($ada->fresh())->session('chat')->run();

    expect($run['tool_calls'])->toBe(['read_op'])
        // The argument must not have followed the name into run bookkeeping.
        ->and(json_encode($run))->not->toContain('hunter2');
});
