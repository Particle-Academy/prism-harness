<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;
use Tests\Support\G360;

// A subagent child that stops on an approval is resumed later on its own
// session, outside the runner. That run continues the run tree it was
// started in: it spends what the tree has left, never a fresh root budget,
// and an approval the spent tree could not run is refused with nothing
// recorded. Driven through the public session API over Prism's real OpenAI
// handler on faked HTTP with stray requests refused.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', []);
    config()->set('prism-harness.agent.modes.chat.subagents', [
        'research' => ['description' => 'Research something.', 'mode' => 'research'],
    ]);
    config()->set('prism-harness.agent.modes.research', [
        'system_prompt' => 'Research.', 'tools' => ['weather', 'lookup'], 'requires_approval' => ['weather'], 'max_steps' => 4,
    ]);

    $this->lookups = 0;
    $this->weatherRan = 0;
    $lookups = &$this->lookups;
    $weather = &$this->weatherRan;
    app(ToolRegistry::class)->register((new Tool)->as('lookup')->for('Look something up.')->withStringParameter('q', 'Query')
        ->using(function (string $q) use (&$lookups): string {
            $lookups++;

            return 'Found';
        }));
    app(ToolRegistry::class)->register((new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$weather): string {
            $weather++;

            return 'Sunny';
        }));
});

/**
 * The parent turn (a tree of $treeSteps model steps) delegates; the child
 * pauses on weather after one step; the parent answers. Three tree steps.
 *
 * @return array{0: Session, 1: string}
 */
function treePausedChild(int $treeSteps): array
{
    config()->set('prism-harness.agent.modes.chat.max_steps', $treeSteps);
    G360::calls([['research', ['task' => 'Find the weather.']]]);   // parent
    G360::calls([['weather', ['city' => 'Paris']]]);                 // child: pauses
    G360::text('Asked.');                                            // parent
    G360::session()->send('Go');

    $child = app(PrismHarness::class)
        ->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat::sub::research');
    $pending = $child->thread()->pendingApprovalIds();
    expect($pending)->toHaveCount(1);

    return [$child, $pending[0]];
}

it('resumes a child outside the runner on what its run tree has left, not a fresh root budget', function (): void {
    [$child, $id] = treePausedChild(4);
    $before = count(Http::recorded());

    // The child would take three more steps; the tree has one left.
    G360::calls([['lookup', ['q' => 'a']]]);
    G360::calls([['lookup', ['q' => 'b']]]);
    G360::text('Child answer.');
    $child->approve($id);

    expect($this->weatherRan)->toBe(1)
        ->and(count(Http::recorded()) - $before)->toBe(1);
});

it('refuses, recording nothing, an approval for a child whose run tree is spent', function (): void {
    [$child, $id] = treePausedChild(3);
    $before = count(Http::recorded());

    expect(fn () => $child->approve($id))->toThrow(RunNotPermitted::class);
    expect($child->thread()->pendingApprovalIds())->toBe([$id])
        ->and($this->weatherRan)->toBe(0)
        ->and(count(Http::recorded()))->toBe($before);
});

it('charges a resumed child to its run tree once, so a second resume gets only what is left', function (): void {
    [$child, $id] = treePausedChild(5);

    // Resumed: one step, which pauses on weather again (tree 4 of 5).
    G360::calls([['weather', ['city' => 'Rome']]]);
    $second = $child->approve($id)->pendingApprovals()[0]->approvalId;
    $before = count(Http::recorded());

    // One left: the child takes one of the three it would.
    G360::calls([['lookup', ['q' => 'a']]]);
    G360::calls([['lookup', ['q' => 'b']]]);
    G360::text('Child answer.');
    $child->approve($second);

    expect(count(Http::recorded()) - $before)->toBe(1);
});

// A turn on a child session the runner did not start never gets a fresh root
// budget by any route: not a new message instead of an answer, and not a run
// context the host hands in (which may only narrow what the tree has left).

it('runs a new message to a child session on what its run tree has left', function (): void {
    [$child] = treePausedChild(4);
    $before = count(Http::recorded());

    G360::calls([['lookup', ['q' => 'a']]]);
    G360::calls([['lookup', ['q' => 'b']]]);
    G360::text('Child answer.');
    $child->send('New question');

    expect(count(Http::recorded()) - $before)->toBe(1);
});

it('refuses a new message to a child session whose run tree is spent', function (): void {
    [$child] = treePausedChild(3);
    $before = count(Http::recorded());

    expect(fn () => $child->send('New question'))->toThrow(RunNotPermitted::class);
    expect(count(Http::recorded()))->toBe($before);
});

it('narrows a host-supplied run context on a resumed child to what its run tree has left', function (): void {
    [$child, $id] = treePausedChild(4);
    $before = count(Http::recorded());

    G360::calls([['lookup', ['q' => 'a']]]);
    G360::calls([['lookup', ['q' => 'b']]]);
    G360::text('Child answer.');
    $child->approve($id, true, null, RunContext::root('host_root', new RunBudget(100)));

    expect($this->weatherRan)->toBe(1)
        ->and(count(Http::recorded()) - $before)->toBe(1);
});

it('refuses, recording nothing, a host-supplied run context on a child whose run tree is spent', function (): void {
    [$child, $id] = treePausedChild(3);
    $before = count(Http::recorded());

    expect(fn () => $child->approve($id, true, null, RunContext::root('host_root', new RunBudget(100))))
        ->toThrow(RunNotPermitted::class);
    expect($child->thread()->pendingApprovalIds())->toBe([$id])
        ->and($this->weatherRan)->toBe(0)
        ->and(count(Http::recorded()))->toBe($before);
});

it('narrows a host-supplied run context on a streamed child resume to what its run tree has left', function (): void {
    [$child, $id] = treePausedChild(4);
    $before = count(Http::recorded());

    G360::calls([['lookup', ['q' => 'a']]]);
    G360::calls([['lookup', ['q' => 'b']]]);
    G360::text('Child answer.');
    iterator_to_array($child->streamDecision([new ToolApprovalResponse($id, true)], RunContext::root('host_root', new RunBudget(100))), false);

    expect($this->weatherRan)->toBe(1)
        ->and(count(Http::recorded()) - $before)->toBe(1);
});
