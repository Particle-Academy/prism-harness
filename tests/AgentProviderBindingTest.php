<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Context\SummarisingCompaction;
use Prism\Harness\Events\HarnessEvent;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Events\RunStarted;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Voice\VoiceExchange;
use Prism\Prism\Audio\TextResponse as TranscriptResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

require_once __DIR__.'/Support/AgentBindingFixtures.php';

/*
|--------------------------------------------------------------------------
| G-321 E — several agents in one app, each on its own provider and model
|--------------------------------------------------------------------------
|
| Every test enters where an application enters: a session from the public
| harness, a real send(), and the Anthropic wire faked underneath core. What is
| asserted is the provider and model each request ACTUALLY went to, read off the
| wire, not what a resolver says it would choose.
|
*/

/**
 * @param  array<string, mixed>  $planner  extra planner agent settings
 * @param  array<string, mixed>  $planMode
 * @param  array<string, mixed>  $researcher  null to leave the agent out
 */
function configureBindingAgents(array $planMode = [], array $planner = [], ?array $researcher = null, array $researchMode = []): void
{
    registerCustomCheapProvider();
    Http::preventStrayRequests();
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'global-model');
    config()->set('prism-harness.agent.max_cost_usd', null);
    $agents = ['planner' => [
        'owner' => 'binding-test', 'model' => 'agent-model', ...$planner,
        'modes' => ['plan' => bindingMode($planMode), 'research' => bindingMode($researchMode)],
    ]];
    if ($researcher !== null) {
        $agents['researcher'] = ['owner' => 'binding-test', ...$researcher,
            'modes' => ['research' => bindingMode($researchMode)]];
    }
    config()->set('prism-harness.agents', $agents);
}

it('lets a mode model override the agent model', function (): void {
    configureBindingAgents(planMode: ['model' => 'mode-model']);
    $requests = [];
    fakeBindingWire(null, $requests);

    bindingSession('planner', 'plan')->send('go');

    expect($requests)->toBe([['who' => 'parent', 'provider' => 'anthropic', 'model' => 'mode-model']]);
});

it('lets a mode provider override the agent provider without touching the model', function (): void {
    configureBindingAgents(planMode: ['provider' => 'custom-cheap'], planner: ['provider' => 'anthropic']);
    $requests = [];
    fakeBindingWire(null, $requests);

    bindingSession('planner', 'plan')->send('go');

    expect($requests)->toBe([['who' => 'parent', 'provider' => 'custom-cheap', 'model' => 'agent-model']]);
});

it('lets a session override beat the mode', function (): void {
    configureBindingAgents(planMode: ['provider' => 'custom-cheap', 'model' => 'mode-model']);
    $requests = [];
    fakeBindingWire(null, $requests);

    bindingSession('planner', 'plan')->usingProvider('anthropic')->usingModel('session-model')->send('go');

    expect($requests)->toBe([['who' => 'parent', 'provider' => 'anthropic', 'model' => 'session-model']]);
});

it('runs a subagent on a cheaper model under a parent on another', function (): void {
    configureBindingAgents(planMode: ['model' => 'opus-like', 'subagents' => [
        'research' => ['mode' => 'research', 'provider' => 'custom-cheap', 'model' => 'cheap-model'],
    ]]);
    $requests = [];
    fakeBindingWire('research', $requests);

    $response = bindingSession('planner', 'plan')->send('go');

    expect($response->text())->toBe('parent answer')
        ->and($requests)->toBe([
            ['who' => 'parent', 'provider' => 'anthropic', 'model' => 'opus-like'],
            ['who' => 'child', 'provider' => 'custom-cheap', 'model' => 'cheap-model'],
            ['who' => 'parent', 'provider' => 'anthropic', 'model' => 'opus-like'],
        ]);
});

it('does not hand the parent session override to a child by default', function (): void {
    configureBindingAgents(planMode: ['subagents' => ['research' => ['mode' => 'research']]]);
    $requests = [];
    fakeBindingWire('research', $requests);

    bindingSession('planner', 'plan')->usingProvider('custom-cheap')->usingModel('session-model')->send('go');

    expect(firstBindings($requests))->toBe([
        'parent' => ['provider' => 'custom-cheap', 'model' => 'session-model'],
        'child' => ['provider' => 'anthropic', 'model' => 'agent-model'],
    ]);
});

it('hands the parent session override to a child that asks for it, above its own declaration', function (): void {
    configureBindingAgents(planMode: ['subagents' => ['research' => [
        'mode' => 'research', 'model' => 'cheap-model', 'inherit_session_model' => true,
    ]]]);
    $requests = [];
    fakeBindingWire('research', $requests);

    bindingSession('planner', 'plan')->usingModel('session-model')->send('go');

    expect(firstBindings($requests)['child'])->toBe(['provider' => 'anthropic', 'model' => 'session-model']);
});

it('runs a subagent on a declared agent with that agent binding', function (): void {
    configureBindingAgents(
        planMode: ['model' => 'opus-like', 'agents' => ['researcher'], 'subagents' => [
            'research' => ['mode' => 'research', 'agent' => 'researcher'],
        ]],
        researcher: ['provider' => 'custom-cheap', 'model' => 'researcher-model'],
    );
    $requests = [];
    fakeBindingWire('research', $requests);

    bindingSession('planner', 'plan')->send('go');

    expect(firstBindings($requests)['child'])->toBe(['provider' => 'custom-cheap', 'model' => 'researcher-model']);
});

it('refuses a subagent naming an agent the mode does not list, before any request', function (): void {
    configureBindingAgents(
        planMode: ['subagents' => ['research' => ['mode' => 'research', 'agent' => 'researcher']]],
        researcher: ['model' => 'researcher-model'],
    );
    $requests = [];
    fakeBindingWire('research', $requests);

    expect(fn () => bindingSession('planner', 'plan')->send('go'))->toThrow(
        InvalidArgumentException::class,
        'Harness mode [plan] declares subagent [research] for agent [researcher], which the mode does not list in its agents.',
    )->and($requests)->toBe([]);
});

it('refuses an agent id that is padded or mixed case rather than normalising it', function (string $id): void {
    configureBindingAgents(
        planMode: ['agents' => ['researcher'], 'subagents' => ['research' => ['mode' => 'research', 'agent' => $id]]],
        researcher: ['model' => 'researcher-model'],
    );
    $requests = [];
    fakeBindingWire('research', $requests);

    expect(fn () => bindingSession('planner', 'plan')->send('go'))->toThrow(
        InvalidArgumentException::class,
        'Harness mode [plan] declares subagent [research] with a malformed agent id: agent ids are lowercase identifiers of 1-64 ASCII bytes.',
    )->and($requests)->toBe([]);
})->with(['leading space' => ' researcher', 'trailing space' => 'researcher ', 'mixed case' => 'Researcher', 'tab' => "researcher\t"]);

it('refuses a padded or mixed-case entry in the mode agents list', function (string $id): void {
    configureBindingAgents(
        planMode: ['agents' => [$id], 'subagents' => ['research' => ['mode' => 'research', 'agent' => 'researcher']]],
        researcher: ['model' => 'researcher-model'],
    );

    expect(fn () => bindingSession('planner', 'plan')->send('go'))->toThrow(
        InvalidArgumentException::class,
        'Harness mode [plan] declares a malformed agents list: each entry must be a lowercase agent id of 1-64 ASCII bytes.',
    );
})->with(['mixed case' => 'Researcher', 'padded' => ' researcher']);

it('refuses a malformed provider, model or inheritance declaration', function (array $mode, string $message): void {
    configureBindingAgents(planMode: $mode);

    expect(fn () => bindingSession('planner', 'plan')->send('go'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty mode model' => [['model' => ''], 'Harness mode [plan] declares a malformed provider/model: expected a nonempty string or null.'],
    'array mode provider' => [['provider' => ['openai']], 'Harness mode [plan] declares a malformed provider/model: expected a nonempty string or null.'],
    'empty subagent model' => [['subagents' => ['research' => ['mode' => 'research', 'model' => '']]],
        'Harness mode [plan] declares subagent [research] with a malformed provider/model: expected a nonempty string or null.'],
    'string inheritance' => [['subagents' => ['research' => ['mode' => 'research', 'inherit_session_model' => 'yes']]],
        'Harness mode [plan] declares subagent [research] with a malformed inherit_session_model: expected a boolean.'],
]);

it('refuses a malformed spend_ceiling when the runtime is built', function (mixed $ceiling): void {
    configureBindingAgents(planner: ['spend_ceiling' => $ceiling]);

    expect(fn () => bindingSession('planner', 'plan')->send('go'))->toThrow(
        InvalidArgumentException::class, 'Agent spend_ceiling must be a positive finite number of USD or null.',
    );
})->with(['zero' => 0, 'negative' => -1.0, 'string' => '1.00', 'infinite' => INF, 'not a number' => NAN, 'bool' => true]);

/*
| Spend ceilings: one root ledger, one more limit per labelled agent.
*/

/** $0.10 reserved per call (10 output tokens at $10,000/MTok); $0.02 actually charged by the reply's tokens. */
function priceBindingProviders(): void
{
    config()->set('prism-harness.pricing', [
        'anthropic' => ['opus-like' => ['inputPerMTok' => 0, 'outputPerMTok' => 10000, 'toolOverheadTokens' => 286]],
        'custom-cheap' => ['cheap-model' => ['inputPerMTok' => 0, 'outputPerMTok' => 10000, 'toolOverheadTokens' => 286]],
    ]);
}

it('stops only the agent over its ceiling; the parent agent completes', function (): void {
    configureBindingAgents(
        planMode: ['model' => 'opus-like', 'agents' => ['researcher'], 'subagents' => [
            'research' => ['mode' => 'research', 'agent' => 'researcher'],
        ]],
        researcher: ['provider' => 'custom-cheap', 'model' => 'cheap-model', 'spend_ceiling' => 0.05, 'max_output_tokens_per_call' => 10],
    );
    priceBindingProviders();
    $requests = [];
    fakeBindingWire('research', $requests, $toolResults);

    $session = bindingSession('planner', 'plan');
    $response = $session->send('go');

    expect($response->text())->toBe('parent answer')
        ->and($session->run()['status'])->toBe('completed')
        ->and(array_column($requests, 'who'))->toBe(['parent', 'parent'])
        ->and(implode(' ', $toolResults))->toContain("would exceed this agent's spend ceiling");
});

it('charges a ceilinged agent to the same root ledger, then refuses it once the ceiling is spent', function (): void {
    configureBindingAgents(
        planMode: ['model' => 'opus-like', 'agents' => ['researcher'], 'subagents' => [
            'research' => ['mode' => 'research', 'agent' => 'researcher'],
        ]],
        researcher: ['provider' => 'custom-cheap', 'model' => 'cheap-model', 'spend_ceiling' => 0.11, 'max_output_tokens_per_call' => 10],
    );
    priceBindingProviders();
    $requests = [];
    fakeBindingWire('research', $requests, $toolResults);
    $root = RunContext::root('binding-root', new RunBudget(10));

    // The first delegation fits ($0.10 reserved, $0.02 charged). The second
    // would put $0.02 + $0.10 against $0.11 and is refused before its request.
    bindingSession('planner', 'plan', 'ledger')->send('go', context: $root);
    $first = $root->ledger->agentCostUsd('researcher');
    $before = count($requests);
    // A second conversation in the same tree: the ledger is the tree's.
    bindingSession('planner', 'plan', 'ledger-again')->send('again', context: $root);

    expect($first)->toEqualWithDelta(0.02, 1e-9)
        ->and($root->ledger->costUsd())->toBeGreaterThanOrEqual($first)
        ->and(array_column($requests, 'who'))->toBe(['parent', 'child', 'parent', 'parent', 'parent'])
        ->and($before)->toBe(3)
        ->and(implode(' ', $toolResults))->toContain("would exceed this agent's spend ceiling");
});

it('refuses an unpriced custom provider under an agent ceiling, before any request', function (): void {
    configureBindingAgents(planner: ['provider' => 'custom-cheap', 'model' => 'unpriced-model', 'spend_ceiling' => 1.0, 'max_output_tokens_per_call' => 10]);
    $requests = [];
    fakeBindingWire(null, $requests);

    try {
        bindingSession('planner', 'plan')->send('go');
        $code = null;
    } catch (RunNotPermitted $refused) {
        $code = $refused->code();
    }

    expect($code)->toBe('budget_unpriced')->and($requests)->toBe([]);
});

/*
| harness_agent on the event stream.
*/

it('tags the events of a named agent with harness_agent, and leaves legacy correlation alone', function (): void {
    configureBindingAgents(planMode: ['subagents' => ['research' => ['mode' => 'research', 'agent' => 'researcher']], 'agents' => ['researcher']],
        researcher: ['model' => 'researcher-model']);
    config()->set('prism-harness.agent.modes.chat', bindingMode([]));
    $events = [];
    Event::listen([RunStarted::class, RunFinished::class], function (HarnessEvent $event) use (&$events): void {
        $events[] = $event;
    });
    $requests = [];
    fakeBindingWire('research', $requests);

    bindingSession('planner', 'plan')->send('go');
    $named = $events;
    $events = [];
    fakeBindingWire(null, $requests);
    bindingSession(null, 'chat')->send('go');

    $agents = array_map(fn (HarnessEvent $event): array => [$event::class, $event->correlation()['harness_agent'] ?? null], $named);
    expect($agents)->toBe([
        [RunStarted::class, 'planner'],
        [RunStarted::class, 'researcher'],
        [RunFinished::class, 'researcher'],
        [RunFinished::class, 'planner'],
    ])->and(array_map(fn (HarnessEvent $event): array => array_keys($event->correlation()), $events))
        ->each->toBe(['session_key', 'run_id', 'parent_run_id', 'root_run_id']);
});

/*
| Helpers that name a provider take a custom one.
*/

it('summarises with a custom provider name', function (): void {
    registerCustomCheapProvider();
    $fake = Prism::fake([TextResponseFake::make()->withText('the summary')]);
    $messages = [];
    for ($i = 1; $i <= 3; $i++) {
        $messages[] = new UserMessage("question {$i}");
        $messages[] = new AssistantMessage("answer {$i}");
    }

    (new SummarisingCompaction(model: 'cheap-model', keep: 2, provider: 'custom-cheap'))->compact($messages);

    $fake->assertRequest(function (array $requests): void {
        expect([$requests[0]->provider(), $requests[0]->model()])->toBe(['custom-cheap', 'cheap-model']);
    });
});

it('transcribes with a custom provider name', function (): void {
    $fake = Prism::fake([new TranscriptResponse(text: 'heard')]);

    $heard = (new VoiceExchange(transcribeModel: 'cheap-whisper', transcribeProvider: 'custom-cheap'))
        ->transcribe(Audio::fromBase64(base64_encode('audio'), 'audio/mpeg'));

    expect($heard)->toBe('heard');
    $fake->assertRequest(function (array $requests): void {
        expect([$requests[0]->provider(), $requests[0]->model()])->toBe(['custom-cheap', 'cheap-whisper']);
    });
});

it('refuses an empty provider name in a helper', function (): void {
    expect(fn () => new SummarisingCompaction(provider: ''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new VoiceExchange(speakProvider: ''))->toThrow(InvalidArgumentException::class);
});
