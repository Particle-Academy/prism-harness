<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Subagents\ChildBinding;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Subagents\RunLedger;
use Prism\Harness\Subagents\Subagent;
use Prism\Harness\Subagents\SubagentRunner;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;

require_once __DIR__.'/Support/AgentBindingFixtures.php';

// A subagent's child is bound to a provider and model by the runner (G-321 E).
// A child that stops on an approval is resumed later, outside the runner: the
// host answers on the child's own session. That run must keep the binding the
// runner gave the child, not fall back to the rules for a root run.

/**
 * @param  array<string, mixed>  $planMode
 * @param  array<string, mixed>  $researchMode
 */
function configureResumeAgents(array $planMode = [], array $researchMode = []): void
{
    registerCustomCheapProvider();
    Http::preventStrayRequests();
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'global-model');
    config()->set('prism-harness.agent.max_cost_usd', null);
    config()->set('prism-harness.agents', ['planner' => [
        'owner' => 'binding-test', 'model' => 'agent-model',
        'modes' => ['plan' => bindingMode($planMode), 'research' => bindingMode($researchMode)],
    ]]);
}

/**
 * Parent delegates to `research`; the child calls `lookup`, which needs
 * approval; every later request answers in text.
 *
 * @param  list<array{who: string, provider: string, model: string}>  $requests
 */
function fakePausingChildWire(array &$requests): void
{
    Http::fake(function (HttpRequest $wire) use (&$requests) {
        $body = $wire->data();
        $encoded = json_encode($body['messages'] ?? [], JSON_THROW_ON_ERROR);
        $first = $body['messages'][0]['content'] ?? null;
        $firstText = is_string($first) ? $first : (is_array($first) ? ($first[0]['text'] ?? null) : null);
        $who = $firstText === 't' ? 'child' : 'parent';
        $answered = str_contains($encoded, '"tool_result"');
        $requests[] = [
            'who' => $who,
            'provider' => parse_url($wire->url(), PHP_URL_HOST) === 'cheap.test' ? 'custom-cheap' : 'anthropic',
            'model' => (string) ($body['model'] ?? ''),
        ];
        $call = $answered ? null : ($who === 'parent'
            ? ['type' => 'tool_use', 'id' => 'delegate-call', 'name' => 'research', 'input' => ['task' => 't']]
            : ['type' => 'tool_use', 'id' => 'lookup-call', 'name' => 'lookup', 'input' => ['q' => 'x']]);

        return Http::response([
            'id' => 'binding-'.count($requests), 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'] ?? '',
            'content' => $call !== null ? [$call] : [['type' => 'text', 'text' => $who.' answer']],
            'stop_reason' => $call !== null ? 'tool_use' : 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ]);
    });
}

it('keeps the child binding when a paused child is resumed outside the subagent runner', function (string $answer): void {
    configureResumeAgents(
        planMode: ['model' => 'opus-like', 'subagents' => [
            'research' => ['mode' => 'research', 'provider' => 'custom-cheap', 'model' => 'cheap-model'],
        ]],
        researchMode: ['tools' => ['lookup'], 'requires_approval' => ['lookup']],
    );
    $ran = 0;
    app(ToolRegistry::class)->forAgent('planner')->register((new Tool)->as('lookup')->for('Look it up.')->withStringParameter('q', 'Query')
        ->using(function (string $q) use (&$ran): string {
            $ran++;

            return 'found';
        }), 'binding-test');
    $requests = [];
    fakePausingChildWire($requests);

    bindingSession('planner', 'plan')->send('go');

    $child = bindingSession('planner', 'research', 'binding::sub::research');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1)
        ->and(array_values(array_filter($requests, fn (array $r): bool => $r['who'] === 'child')))
        ->toBe([['who' => 'child', 'provider' => 'custom-cheap', 'model' => 'cheap-model']]);

    // The host answers on the child's session: no runner, no RunContext.
    $before = count($requests);
    if ($answer === 'approve') {
        $child->approve($pending[0]);
    } else {
        iterator_to_array($child->streamDecision([new ToolApprovalResponse($pending[0], true)]), false);
    }

    expect($ran)->toBe(1)
        ->and(array_slice($requests, $before))->toBe([['who' => 'child', 'provider' => 'custom-cheap', 'model' => 'cheap-model']]);
})->with(['approve', 'streamDecision']);

it('does not read the child session override slot on resume either', function (): void {
    configureResumeAgents(
        planMode: ['subagents' => ['research' => ['mode' => 'research', 'provider' => 'custom-cheap', 'model' => 'cheap-model']]],
        researchMode: ['tools' => ['lookup'], 'requires_approval' => ['lookup']],
    );
    app(ToolRegistry::class)->forAgent('planner')->register((new Tool)->as('lookup')->for('Look it up.')->withStringParameter('q', 'Query')
        ->using(fn (string $q): string => 'found'), 'binding-test');
    $requests = [];
    fakePausingChildWire($requests);

    bindingSession('planner', 'plan')->send('go');
    $child = bindingSession('planner', 'research', 'binding::sub::research');
    $child->usingProvider('anthropic')->usingModel('left-over-model');
    $pending = $child->thread()->pendingApprovalIds();

    $before = count($requests);
    $child->approve($pending[0]);

    expect(array_slice($requests, $before))->toBe([['who' => 'child', 'provider' => 'custom-cheap', 'model' => 'cheap-model']]);
});

it('leaves a root session that was never a child on the root rules', function (): void {
    configureResumeAgents(planMode: ['model' => 'mode-model']);
    $requests = [];
    fakeBindingWire(null, $requests);

    bindingSession('planner', 'plan')->send('go');
    bindingSession('planner', 'plan')->send('again');

    expect($requests)->toBe([
        ['who' => 'parent', 'provider' => 'anthropic', 'model' => 'mode-model'],
        ['who' => 'parent', 'provider' => 'anthropic', 'model' => 'mode-model'],
    ]);
});

// The binding must outlive the session's ephemeral half. A person may answer
// an approval hours later, after the ephemeral TTL has lapsed, Redis has
// evicted the key, or the host called forget(); the child must still go to
// the provider and model its declaration chose.
it('keeps the child binding when the ephemeral state is gone before the answer', function (string $answer): void {
    configureResumeAgents(
        planMode: ['model' => 'opus-like', 'subagents' => [
            'research' => ['mode' => 'research', 'provider' => 'custom-cheap', 'model' => 'cheap-model'],
        ]],
        researchMode: ['tools' => ['lookup'], 'requires_approval' => ['lookup']],
    );
    app(ToolRegistry::class)->forAgent('planner')->register((new Tool)->as('lookup')->for('Look it up.')->withStringParameter('q', 'Query')
        ->using(fn (string $q): string => 'found'), 'binding-test');
    $requests = [];
    fakePausingChildWire($requests);

    bindingSession('planner', 'plan')->send('go');
    $child = bindingSession('planner', 'research', 'binding::sub::research');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1);

    // The ephemeral half expires; the mode is set again, as a host would.
    $child->forget();
    $child->usingMode('research');

    $before = count($requests);
    if ($answer === 'approve') {
        $child->approve($pending[0]);
    } else {
        iterator_to_array($child->streamDecision([new ToolApprovalResponse($pending[0], true)]), false);
    }

    expect(array_slice($requests, $before))->toBe([['who' => 'child', 'provider' => 'custom-cheap', 'model' => 'cheap-model']]);
})->with(['approve', 'streamDecision']);

it('refuses to run a linked child session that has no saved binding rather than run it as a root', function (): void {
    configureResumeAgents(researchMode: ['tools' => []]);
    $requests = [];
    fakeBindingWire(null, $requests);

    $parent = bindingSession('planner', 'plan');
    $child = bindingSession('planner', 'research', 'binding::sub::research');
    $child->thread()->linkBeneath($parent->thread(), 'run_lost');

    expect(fn (): mixed => $child->send('hello'))->toThrow(RunNotPermitted::class)
        ->and($requests)->toBe([]);
});

// A run context the host builds is not the runner's, so it cannot stand in for
// the binding a linked child lost: the child is refused before any request,
// whether the context carries a binding of its own or not.
it('refuses a linked child that has no saved binding under a host context too', function (bool $withBinding): void {
    configureResumeAgents(researchMode: ['tools' => []]);
    $requests = [];
    fakeBindingWire(null, $requests);

    $parent = bindingSession('planner', 'plan');
    $child = bindingSession('planner', 'research', 'binding::sub::research');
    $child->thread()->linkBeneath($parent->thread(), 'run_lost');
    $context = RunContext::root('host_root', new RunBudget(10));
    $context = $withBinding ? $context->withBinding(new ChildBinding('custom-cheap', 'cheap-model')) : $context;

    try {
        $child->send('hello', null, [], $context);
        $code = null;
    } catch (RunNotPermitted $refused) {
        $code = $refused->code();
    }

    expect($code)->toBe('child_binding_missing')
        ->and($requests)->toBe([]);
})->with(['with a binding' => true, 'without one' => false]);

// A host context may bind the session it runs for that turn. Only the
// runner's own context saves a binding, so the next turn with no context runs
// on the root rules again.
it('uses a host context binding on a session that was never a child for that turn only', function (): void {
    configureResumeAgents(planMode: ['model' => 'mode-model']);
    $requests = [];
    fakeBindingWire(null, $requests);

    $session = bindingSession('planner', 'plan');
    $session->send('go', null, [], RunContext::root('host_root', new RunBudget(10))->withBinding(new ChildBinding('custom-cheap', 'cheap-model')));
    bindingSession('planner', 'plan')->send('again');

    expect($requests)->toBe([
        ['who' => 'parent', 'provider' => 'custom-cheap', 'model' => 'cheap-model'],
        ['who' => 'parent', 'provider' => 'anthropic', 'model' => 'mode-model'],
    ]);
});

// A child resumed outside the runner keeps the depth the runner gave it, so a
// delegation from it is refused at the same maximum depth as inside the tree.
it('refuses a delegation past the maximum depth from a child resumed outside the runner', function (string $context): void {
    configureResumeAgents(planMode: ['max_steps' => 20], researchMode: [
        'max_steps' => 20, 'tools' => ['lookup'], 'requires_approval' => ['lookup'],
        'subagents' => ['deeper' => ['mode' => 'research']],
    ]);
    app(ToolRegistry::class)->forAgent('planner')->register((new Tool)->as('lookup')->for('Look it up.')->withStringParameter('q', 'Query')
        ->using(fn (string $q): string => 'found'), 'binding-test');
    $seen = [];
    $toolResults = [];
    Http::fake(function (HttpRequest $wire) use (&$seen, &$toolResults) {
        $body = $wire->data();
        $first = $body['messages'][0]['content'] ?? null;
        $firstText = is_string($first) ? $first : (is_array($first) ? ($first[0]['text'] ?? null) : null);
        $seen[] = $firstText;
        $results = 0;
        foreach ($body['messages'] ?? [] as $message) {
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (($block['type'] ?? null) === 'tool_result') {
                    $results++;
                    $toolResults[] = json_encode($block['content'] ?? null, JSON_THROW_ON_ERROR);
                }
            }
        }
        $call = $firstText !== 'deep-task' ? null : match ($results) {
            0 => ['type' => 'tool_use', 'id' => 'lookup-call', 'name' => 'lookup', 'input' => ['q' => 'x']],
            1 => ['type' => 'tool_use', 'id' => 'deeper-call', 'name' => 'deeper', 'input' => ['task' => 'grandchild']],
            default => null,
        };

        return Http::response([
            'id' => 'depth-'.count($seen), 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'] ?? '',
            'content' => $call !== null ? [$call] : [['type' => 'text', 'text' => 'answer']],
            'stop_reason' => $call !== null ? 'tool_use' : 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ]);
    });

    // The runner starts a child one level below the maximum; it stops on an approval.
    $parent = bindingSession('planner', 'research', 'deep');
    $deep = new RunContext(ledger: RunLedger::start('run_root'), budget: new RunBudget(50), parentRunId: 'run_root', depth: RunContext::MAX_DEPTH - 2);
    app(SubagentRunner::class)->run(new Subagent('deeper', 'Go deeper.', 'research', new RunBudget(20)), $parent, $deep, 'run_root', 'deep-task');
    $child = bindingSession('planner', 'research', 'deep::sub::deeper');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1);

    // The host answers on the child's own session, after the ephemeral half is gone.
    $child->forget();
    $child->usingMode('research');
    $child->approve($pending[0], true, null, match ($context) {
        'none' => null,
        'host' => RunContext::root('host_root', new RunBudget(50)),
        'host with a binding' => RunContext::root('host_root', new RunBudget(50))->withBinding(new ChildBinding('anthropic', 'forged-model')),
    });

    expect($seen)->not->toContain('grandchild')
        ->and(implode("\n", $toolResults))->toContain('maximum depth');
})->with(['none', 'host', 'host with a binding']);

// A run context the host builds may carry a binding of its own. Once the
// runner has saved one with the child, the saved binding wins: the context
// cannot move the child to another provider or model.
it('keeps the saved child binding when a host context on the resume carries another', function (string $answer): void {
    configureResumeAgents(
        planMode: ['max_steps' => 20, 'subagents' => ['research' => ['mode' => 'research', 'provider' => 'custom-cheap', 'model' => 'cheap-model']]],
        researchMode: ['max_steps' => 20, 'tools' => ['lookup'], 'requires_approval' => ['lookup']],
    );
    app(ToolRegistry::class)->forAgent('planner')->register((new Tool)->as('lookup')->for('Look it up.')->withStringParameter('q', 'Query')
        ->using(fn (string $q): string => 'found'), 'binding-test');
    $requests = [];
    fakePausingChildWire($requests);

    bindingSession('planner', 'plan')->send('go');
    $child = bindingSession('planner', 'research', 'binding::sub::research');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1);

    $forged = RunContext::root('host_root', new RunBudget(50))->withBinding(new ChildBinding('anthropic', 'forged-model'));
    $before = count($requests);
    if ($answer === 'approve') {
        $child->approve($pending[0], true, null, $forged);
    } else {
        iterator_to_array($child->streamDecision([new ToolApprovalResponse($pending[0], true)], $forged), false);
    }
    // And the saved binding is still there for a later turn with no context.
    $child->send('again');

    expect(array_slice($requests, $before))->toBe([
        ['who' => 'child', 'provider' => 'custom-cheap', 'model' => 'cheap-model'],
        ['who' => 'child', 'provider' => 'custom-cheap', 'model' => 'cheap-model'],
    ]);
})->with(['approve', 'streamDecision']);

// A child the runner starts again, now under a run tree the host built, keeps
// the run tree reference it saved under an earlier tree. The runner's own
// context spends from the host's tree and is never held against the saved one,
// and the saved one is never replaced with nothing, so a later turn the runner
// does not start still continues it rather than a fresh root budget.
it('runs a child started again under a host-built tree even when the tree it saved before is spent, and keeps that tree', function (): void {
    configureResumeAgents(
        planMode: ['max_steps' => 3, 'subagents' => [
            'research' => ['mode' => 'research', 'provider' => 'custom-cheap', 'model' => 'cheap-model'],
        ]],
        researchMode: ['tools' => []],
    );
    $requests = [];
    Http::fake(function (HttpRequest $wire) use (&$requests) {
        $body = $wire->data();
        $messages = $body['messages'] ?? [];
        $first = $messages[0]['content'] ?? null;
        $firstText = is_string($first) ? $first : (is_array($first) ? ($first[0]['text'] ?? null) : null);
        $who = $firstText === 't' ? 'child' : 'parent';
        $last = end($messages);
        $lastIsResult = is_array($last) && is_array($last['content'] ?? null)
            && in_array('tool_result', array_column($last['content'], 'type'), true);
        $requests[] = ['who' => $who, 'provider' => parse_url($wire->url(), PHP_URL_HOST) === 'cheap.test' ? 'custom-cheap' : 'anthropic'];
        $call = $who === 'parent' && ! $lastIsResult
            ? ['type' => 'tool_use', 'id' => 'delegate-'.count($requests), 'name' => 'research', 'input' => ['task' => 't']]
            : null;

        return Http::response([
            'id' => 'tree-'.count($requests), 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'] ?? '',
            'content' => $call !== null ? [$call] : [['type' => 'text', 'text' => $who.' answer']],
            'stop_reason' => $call !== null ? 'tool_use' : 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
        ]);
    });

    bindingSession('planner', 'plan')->send('go');
    $child = bindingSession('planner', 'research', 'binding::sub::research');
    $tree = $child->childTree();
    expect($tree)->not->toBeNull();

    $before = count($requests);
    bindingSession('planner', 'plan')->send('again', null, [], RunContext::root('host_root', new RunBudget(100)));

    expect(array_values(array_filter(array_slice($requests, $before), fn (array $r): bool => $r['who'] === 'child')))
        ->toBe([['who' => 'child', 'provider' => 'custom-cheap']])
        ->and($child->childTree())->toBe($tree);
});
