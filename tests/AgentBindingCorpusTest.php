<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

require_once __DIR__.'/Support/AgentBindingFixtures.php';

/*
|--------------------------------------------------------------------------
| harness-agent-binding — the cross-language corpus (G-321 E)
|--------------------------------------------------------------------------
|
| Which provider and model a run, and a subagent's child, ACTUALLY request,
| and which delegations are refused before any request. The expectations were
| written by hand before any language was run; every language drives its real
| runtime against the same rows. A row PHP disagrees with fails here rather
| than being regenerated, because the reference is not the definition.
|
*/

/** @return array<string, array{0: array<string, mixed>}> */
function agentBindingCases(): array
{
    $corpus = json_decode((string) file_get_contents(__DIR__.'/Fixtures/harness-agent-binding.json'), true, flags: JSON_THROW_ON_ERROR);
    $cases = [];
    foreach ($corpus['cases'] as $case) {
        $cases[$case['id']] = [$case];
    }

    return $cases;
}

it('holds every agent-binding corpus row', function (array $case): void {
    registerCustomCheapProvider();
    Http::preventStrayRequests();
    config()->set('prism-harness.agent.provider', $case['global']['provider']);
    config()->set('prism-harness.agent.model', $case['global']['model']);
    config()->set('prism-harness.agent.max_cost_usd', null);
    config()->set('prism-harness.agent.modes', array_map('bindingMode', $case['modes'] === [] ? ['chat' => []] : $case['modes']));
    $agents = [];
    foreach ($case['agents'] as $id => $agent) {
        $agents[$id] = [...$agent, 'owner' => 'binding-corpus', 'modes' => array_map('bindingMode', $agent['modes'])];
    }
    config()->set('prism-harness.agents', $agents);

    $run = $case['run'];
    $requests = [];
    fakeBindingWire($run['delegate'], $requests);
    $session = bindingSession($run['agent'], $run['mode'], 'binding-'.$case['id']);
    if ($run['session_provider'] !== null) {
        $session->usingProvider($run['session_provider']);
    }
    if ($run['session_model'] !== null) {
        $session->usingModel($run['session_model']);
    }

    $refused = null;
    try {
        $session->send('go');
    } catch (InvalidArgumentException) {
        // A configuration refusal. Which one is pinned by AgentProviderBindingTest's exact messages.
        $refused = $requests === [] ? 'config' : 'config-after-requests';
    }

    $observed = $refused === null
        ? ['refused' => null, ...firstBindings($requests)]
        : ['refused' => $refused, 'parent' => null, 'child' => null];

    expect($observed)->toBe($case['expected']);
})->with(agentBindingCases());

it('runs every row of the corpus', function (): void {
    expect(agentBindingCases())->toHaveCount(18);
});
