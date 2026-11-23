<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Contracts\DeclaresToolScope;
use Prism\Harness\Events\ToolApprovalAsked;
use Prism\Harness\Events\ToolApproved;
use Prism\Harness\Events\ToolAutoAllowed;
use Prism\Harness\Events\ToolDecision;
use Prism\Harness\Events\ToolDenied;
use Prism\Harness\Exceptions\ToolPolicyRefused;
use Prism\Harness\PrismHarness;
use Prism\Harness\Tools\ToolPolicy;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Tests\Fixtures\Participant;

// The cross-language tool-policy corpus (G-326). Every row's `expected` was
// written by hand before any language recorded. This runner drives the real
// runtime through Prism's OpenAI handler over faked HTTP: the model asks for
// the row's calls, then answers. Every tool needs approval by mode; a tool
// with `server` is provided at resolve time, as an MCP bridge provides one.

/** Which corpus tools ran. Static because a tool closure outlives the test's scope. */
final class CorpusRan
{
    /** @var list<string> */
    public static array $names = [];
}

/** @return list<array<string, mixed>> */
function toolPolicyCorpus(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/Fixtures/harness-tool-policy.json'), true)['cases'];
}

/** @param array{name: string, server?: string, tier?: string} $spec */
function corpusTool(array $spec): Tool
{
    $tool = new class extends Tool implements DeclaresToolScope
    {
        public ?string $server = null;

        public ?string $tier = null;

        public function toolServer(): ?string
        {
            return $this->server;
        }

        public function toolTier(): ?string
        {
            return $this->tier;
        }
    };
    $tool->server = $spec['server'] ?? null;
    $tool->tier = $spec['tier'] ?? null;
    $name = $spec['name'];

    return $tool->as($name)->for('Corpus tool.')->using(function (...$args) use ($name): string {
        CorpusRan::$names[] = $name;

        return 'ok';
    });
}

/**
 * @param  array<string, mixed>  $row
 * @return array<string, mixed>
 */
function recordToolPolicyRow(array $row): array
{
    CorpusRan::$names = [];
    $events = [];
    foreach ([ToolAutoAllowed::class, ToolApprovalAsked::class, ToolApproved::class, ToolDenied::class] as $event) {
        Event::listen($event, function (ToolDecision $decision) use (&$events): void {
            $events[] = $decision->toArray();
        });
    }
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['*']);
    config()->set('prism-harness.agent.modes.chat.max_steps', 6);
    config()->set('prism-harness.agent.modes.chat.tools', array_column($row['tools'], 'name'));

    $static = [];
    $provided = [];
    foreach ($row['tools'] as $spec) {
        isset($spec['server']) ? $provided[] = corpusTool($spec) : $static[] = corpusTool($spec);
    }
    app(ToolRegistry::class)->registerMany($static);
    if ($provided !== []) {
        app(ToolRegistry::class)->registerProvider(fn (): array => $provided, 'mcp');
    }

    $count = 0;
    Http::fake(function (Request $request) use ($row, &$count) {
        $count++;
        $output = $count === 1
            ? array_map(fn (array $call, int $index): array => [
                'id' => 'fc_'.$index, 'type' => 'function_call', 'status' => 'completed',
                'arguments' => json_encode((object) $call['args'], JSON_UNESCAPED_UNICODE), 'call_id' => 'call_'.$index, 'name' => $call['name'],
            ], $row['calls'], array_keys($row['calls']))
            : [['id' => 'msg_1', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'annotations' => [], 'text' => 'done', 'refusal' => null]]]];

        return Http::response(['id' => 'resp_'.$count, 'object' => 'response', 'created_at' => 1741989983, 'status' => 'completed',
            'model' => 'gpt-4o-2024-08-06', 'output' => $output,
            'usage' => ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2]]);
    });

    $runtime = app(AgentRuntime::class);
    try {
        $runtime->setToolPolicy(ToolPolicy::fromArray($row['policy']));
    } catch (ToolPolicyRefused $refused) {
        return ['refused' => $refused->code(), 'ran' => [], 'asked' => [], 'events' => []];
    }

    $session = fn () => app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('corpus');
    $response = $session()->send('go');
    if ($row['then'] !== null) {
        $runtime->setToolPolicy(ToolPolicy::fromArray($row['then']['policy']));
        $response = $session()->send('');
    }

    return [
        'refused' => null,
        'ran' => CorpusRan::$names,
        'asked' => array_map(fn ($pending): string => $row['calls'][(int) preg_replace('/[^0-9]/', '', $pending->toolCallId)]['name'], $response->pendingApprovals()),
        'events' => array_map(fn (array $event): array => [
            'type' => $event['type'],
            'server' => $event['server'],
            'tool' => $event['tool'],
            'args_digest' => $event['args_digest'],
            'decided_by' => $event['decided_by'],
            'policy_revision' => $event['policy_revision'],
        ], $events),
    ];
}

it('has rows', function (): void {
    expect(toolPolicyCorpus())->not->toBeEmpty();
});

it('records the corpus row', function (array $row): void {
    expect(recordToolPolicyRow($row))->toBe($row['expected']);
})->with(function (): array {
    $rows = [];
    foreach (toolPolicyCorpus() as $row) {
        $rows[$row['id'].': '.$row['title']] = [$row];
    }

    return $rows;
});
