<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Fixtures\Participant;

// PROPERTY TEST for the step budget of one turn.
//
// Each example sends one prompt (send or stream) against a faked provider
// whose every step asks for a gated tool, an ungated tool, or answers in
// text, then drives a generated sequence of resumes and empty sends
// (approve, deny, decide, streamDecision, send(''), stream('')). An empty
// prompt records no user row, so all of it is one turn. The invariant:
//
//   After every action, the model calls made and the model steps recorded
//   since the turn's user row are each at most the mode's max_steps.
//
// PRISM_PROPERTY_EXAMPLES sets the number of examples (a small fixed set by
// default). PRISM_PROPERTY_BASE_SEED moves the base seed. PRISM_PROPERTY_SEED
// replays one example seed, which a failure prints with its case.

/** A deterministic xorshift32 generator: no third-party code. */
final class StepBudgetPropertyRng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;

        return $this->state = $x & 0xFFFFFFFF;
    }

    public function int(int $n): int
    {
        return $this->next() % $n;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(count($items))];
    }
}

/** Shared state between the faked provider, the tools and the driver. */
final class StepBudgetPropertyWorld
{
    /** @var list<string> the kind of each model step, in call order */
    public static array $script = [];

    public static int $calls = 0;

    public static int $ran = 0;

    public static int $participant = 0;
}

const STEP_BUDGET_FAST_EXAMPLES = 60;

const STEP_BUDGET_ACTIONS = ['approve', 'deny', 'decide', 'streamDecision', 'send', 'stream'];

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('prism.providers.anthropic.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'anthropic');
    config()->set('prism-harness.agent.model', 'claude-sonnet-5-5');
    config()->set('prism-harness.agent.modes.chat.tools', ['gated', 'free']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['gated']);

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('gated')->for('Gated.')->using(function (): string {
            StepBudgetPropertyWorld::$ran++;

            return 'ran';
        }),
        (new Tool)->as('free')->for('Free.')->using(function (): string {
            StepBudgetPropertyWorld::$ran++;

            return 'ran';
        }),
    ]);

    Http::fake(['*' => function (HttpRequest $request) {
        $n = ++StepBudgetPropertyWorld::$calls;
        $kind = StepBudgetPropertyWorld::$script[$n - 1] ?? 'text';
        $content = $kind === 'text'
            ? [['type' => 'text', 'text' => 'Done.']]
            : [['type' => 'tool_use', 'id' => 'toolu_'.$n, 'name' => $kind, 'input' => new stdClass]];
        $stop = $kind === 'text' ? 'end_turn' : 'tool_use';

        if ((json_decode((string) $request->body(), true)['stream'] ?? false) === true) {
            return Http::response(stepBudgetPropertySse('msg_'.$n, $content, $stop), 200, ['Content-Type' => 'text/event-stream']);
        }

        return Http::response(['id' => 'msg_'.$n, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]);
    }]);
});

/**
 * The same answer as the Messages API streams it.
 *
 * @param  list<array<string, mixed>>  $content
 */
function stepBudgetPropertySse(string $id, array $content, string $stop): string
{
    $events = [['type' => 'message_start', 'message' => ['id' => $id, 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5',
        'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]];
    foreach ($content as $index => $block) {
        if ($block['type'] === 'text') {
            $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'text', 'text' => '']];
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]];
        } else {
            $events[] = ['type' => 'content_block_start', 'index' => $index, 'content_block' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => new stdClass]];
            $events[] = ['type' => 'content_block_delta', 'index' => $index, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']];
        }
        $events[] = ['type' => 'content_block_stop', 'index' => $index];
    }
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop, 'stop_sequence' => null], 'usage' => ['output_tokens' => 1]];
    $events[] = ['type' => 'message_stop'];
    $newline = chr(10);

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type'].$newline.'data: '.json_encode($event).$newline.$newline, $events));
}

/** @return array{maxSteps: int, entry: string, script: list<string>, actions: list<string>} */
function stepBudgetPropertyGenerate(int $seed): array
{
    $g = new StepBudgetPropertyRng($seed);
    $maxSteps = 1 + $g->int(5);
    $script = [];
    for ($i = 0; $i < 3 * $maxSteps + 6; $i++) {
        $roll = $g->int(20);
        $script[] = $roll < 11 ? 'gated' : ($roll < 17 ? 'free' : 'text');
    }
    $actions = [];
    for ($i = 0, $count = 4 + $g->int(9); $i < $count; $i++) {
        $actions[] = $g->pick(STEP_BUDGET_ACTIONS);
    }

    return ['maxSteps' => $maxSteps, 'entry' => $g->pick(['send', 'stream']), 'script' => $script, 'actions' => $actions];
}

function stepBudgetPropertySession(): Session
{
    return app(PrismHarness::class)
        ->for(Participant::query()->firstOrCreate(['name' => 'Budget '.StepBudgetPropertyWorld::$participant]))
        ->session('chat');
}

/** Model steps since the turn's user row: one assistant row per step. */
function stepBudgetPropertySteps(): int
{
    $steps = 0;
    foreach (stepBudgetPropertySession()->thread()->storedMessages()->reorder('position', 'desc')->get() as $row) {
        if ($row->type === 'user') {
            break;
        }
        $steps += $row->type === 'assistant' ? 1 : 0;
    }

    return $steps;
}

/** The approval id waiting at the transcript tail, if any. */
function stepBudgetPropertyPending(): ?string
{
    $row = stepBudgetPropertySession()->thread()->storedMessages()->where('type', 'assistant')->reorder('position', 'desc')->first();
    $id = $row?->payload['tool_approval_requests'][0]['approval_id'] ?? null;

    return is_string($id) ? $id : null;
}

function stepBudgetPropertyAct(string $action): void
{
    $pending = stepBudgetPropertyPending();
    if ($pending === null && in_array($action, ['approve', 'deny', 'decide', 'streamDecision'], true)) {
        $action = 'send';
    }
    $drain = function (iterable $events): void {
        foreach ($events as $event) {
            // drained
        }
    };
    match ($action) {
        'approve' => stepBudgetPropertySession()->approve((string) $pending),
        'deny' => stepBudgetPropertySession()->deny((string) $pending),
        'decide' => stepBudgetPropertySession()->decide([new ToolApprovalResponse((string) $pending, true)]),
        'streamDecision' => $drain(stepBudgetPropertySession()->streamDecision([new ToolApprovalResponse((string) $pending, true)])),
        'stream' => $drain(stepBudgetPropertySession()->stream('')),
        default => stepBudgetPropertySession()->send(''),
    };
}

/**
 * @param  array{maxSteps: int, entry: string, script: list<string>, actions: list<string>}  $case
 * @return list<string>
 */
function stepBudgetPropertyCheck(array $case): array
{
    StepBudgetPropertyWorld::$participant++;
    StepBudgetPropertyWorld::$script = $case['script'];
    StepBudgetPropertyWorld::$calls = 0;
    StepBudgetPropertyWorld::$ran = 0;
    config()->set('prism-harness.agent.modes.chat.max_steps', $case['maxSteps']);
    // The registries read the config once: a fresh set per example.
    foreach ([ModeRegistry::class, AgentRuntime::class, PrismHarness::class] as $class) {
        app()->forgetInstance($class);
    }

    $violations = [];
    $check = function (string $after) use ($case, &$violations): void {
        $steps = stepBudgetPropertySteps();
        if (StepBudgetPropertyWorld::$calls > $case['maxSteps']) {
            $violations[] = "after {$after}: ".StepBudgetPropertyWorld::$calls." model calls, max_steps is {$case['maxSteps']}";
        }
        if ($steps > $case['maxSteps']) {
            $violations[] = "after {$after}: {$steps} model steps recorded in the turn, max_steps is {$case['maxSteps']}";
        }
    };

    $run = function (callable $step): ?string {
        try {
            $step();
        } catch (Error $error) {
            return 'error: '.$error::class.': '.$error->getMessage();
        } catch (Throwable) {
            // A refusal (budget, lock, nothing to resume) is an allowed outcome.
        }

        return null;
    };

    $error = $run(function () use ($case): void {
        if ($case['entry'] === 'stream') {
            foreach (stepBudgetPropertySession()->stream('Go') as $event) {
                // drained
            }
        } else {
            stepBudgetPropertySession()->send('Go');
        }
    });
    if ($error !== null) {
        return [$error];
    }
    $check('the prompt');
    foreach ($case['actions'] as $index => $action) {
        $error = $run(fn () => stepBudgetPropertyAct($action));
        if ($error !== null) {
            return [...$violations, $error];
        }
        $check("action {$index} ({$action})");
        if ($violations !== []) {
            return $violations;
        }
    }

    return $violations;
}

/**
 * @param  array{maxSteps: int, entry: string, script: list<string>, actions: list<string>}  $case
 * @return list<array{maxSteps: int, entry: string, script: list<string>, actions: list<string>}>
 */
function stepBudgetPropertySmaller(array $case): array
{
    $candidates = [];
    foreach (array_keys($case['actions']) as $index) {
        $smaller = $case;
        array_splice($smaller['actions'], $index, 1);
        $candidates[] = $smaller;
    }
    if ($case['maxSteps'] > 1) {
        $candidates[] = [...$case, 'maxSteps' => $case['maxSteps'] - 1];
    }

    return $candidates;
}

function stepBudgetPropertyEnvInt(string $name): ?int
{
    $value = getenv($name);
    if ($value === false) {
        return null;
    }
    if (preg_match('/\A[1-9][0-9]{0,17}\z/', $value) !== 1) {
        throw new InvalidArgumentException("{$name} must be a whole number of at least 1, got '{$value}'.");
    }

    return (int) $value;
}

it('never lets empty resumes take a turn past max_steps steps or model calls', function (): void {
    $replay = stepBudgetPropertyEnvInt('PRISM_PROPERTY_SEED');
    $base = stepBudgetPropertyEnvInt('PRISM_PROPERTY_BASE_SEED') ?? 392;
    $examples = stepBudgetPropertyEnvInt('PRISM_PROPERTY_EXAMPLES') ?? STEP_BUDGET_FAST_EXAMPLES;
    $seeds = $replay !== null ? [$replay] : array_map(fn (int $i): int => crc32("budget/{$base}/{$i}") ?: 1, range(0, $examples - 1));
    fwrite(STDERR, '[step-budget-property] base='.$base.' examples='.count($seeds).($replay !== null ? ' replay='.$replay : '')."\n");

    foreach ($seeds as $seed) {
        $case = stepBudgetPropertyGenerate($seed);
        $violations = stepBudgetPropertyCheck($case);
        if ($violations === []) {
            continue;
        }

        $minimal = $case;
        $minimalViolations = $violations;
        for ($budget = 40, $progress = true; $progress && $budget > 0;) {
            $progress = false;
            foreach (stepBudgetPropertySmaller($minimal) as $candidate) {
                if (--$budget < 0) {
                    break;
                }
                $candidateViolations = stepBudgetPropertyCheck($candidate);
                if ($candidateViolations !== []) {
                    [$minimal, $minimalViolations, $progress] = [$candidate, $candidateViolations, true];

                    break;
                }
            }
        }

        $this->fail("Step budget property FAILED\n"
            ."  replay: PRISM_PROPERTY_SEED={$seed}\n"
            .'  minimal case: '.json_encode($minimal)."\n"
            .'  violations: '.json_encode($minimalViolations, JSON_PRETTY_PRINT)."\n"
            .'  original case: '.json_encode($case));
    }

    expect(true)->toBeTrue();
});

it('generates the shapes the budget property needs, in the fixed-seed set', function (): void {
    $seen = [];
    foreach (range(0, STEP_BUDGET_FAST_EXAMPLES - 1) as $i) {
        $case = stepBudgetPropertyGenerate(crc32("budget/392/{$i}") ?: 1);
        $seen['entry:'.$case['entry']] = true;
        $seen['max:'.$case['maxSteps']] = true;
        foreach ($case['actions'] as $action) {
            $seen['action:'.$action] = true;
        }
        foreach (array_slice($case['script'], 0, $case['maxSteps']) as $kind) {
            $seen['step:'.$kind] = true;
        }
    }

    $want = ['entry:send', 'entry:stream', 'step:gated', 'step:free', 'step:text'];
    foreach (range(1, 5) as $max) {
        $want[] = 'max:'.$max;
    }
    foreach (STEP_BUDGET_ACTIONS as $action) {
        $want[] = 'action:'.$action;
    }

    expect(array_values(array_diff($want, array_keys($seen))))->toBe([]);
});
