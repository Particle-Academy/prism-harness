<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
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
