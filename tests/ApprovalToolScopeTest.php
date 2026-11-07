<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Harness\AgentRuntime;
use Prism\Harness\Exceptions\StructuredSchemaViolation;
use Prism\Harness\Modes\ModeRegistry;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Stores\DatabaseSessionStore;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Streaming\Events\ToolApprovalRequestEvent;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolApprovalRequest;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Prism\Prism\ValueObjects\ToolCall;
use Tests\Fixtures\Participant;
use Tests\TestCase;

require_once __DIR__.'/Support/CallBudgetFixtures.php';
require_once __DIR__.'/Support/CallBudgetHttpFixtures.php';

final class ApprovalToolScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set('prism.providers.openai.api_key', 'synthetic-offline');
        config()->set('prism-harness.agent.provider', 'openai');
        config()->set('prism-harness.agent.model', 'gpt-4o');
        config()->set('prism-harness.agent.modes.chat.tools', ['scope_safe', 'scope_ceiling']);
        config()->set('prism-harness.agent.modes.chat.requires_approval', ['scope_safe']);
    }

    /** @return array<string, mixed> */
    private function functionResponse(string $name, string $id): array
    {
        $response = json_decode((string) file_get_contents(__DIR__.'/Fixtures/openai/tool-calls.json'), true, flags: JSON_THROW_ON_ERROR);
        $response['id'] = 'resp_'.$id;
        $response['output'] = [[
            'type' => 'function_call', 'id' => 'fc_'.$id, 'call_id' => 'call_'.$id,
            'name' => $name, 'arguments' => '{}', 'status' => 'completed',
        ]];

        return $response;
    }

    /**
     * Real provider handlers over intercepted HTTP, with a new Session object
     * for the same durable thread. Counters measure reachable capability.
     *
     * @param  list<string>|null  $requested
     * @param  Closure(Session): void|null  $beforeResume
     * @return array{counts: array<string, int>, offers: list<list<string>>, session: Session}
     */
    private function exercise(?array $requested, ?bool $decision, ?Closure $beforeResume = null, string $method = 'decide'): array
    {
        $counts = ['scope_safe' => 0, 'scope_ceiling' => 0];
        foreach (array_keys($counts) as $name) {
            app(ToolRegistry::class)->register((new Tool)->as($name)->for('An inert test tool.')->using(function () use (&$counts, $name): string {
                $counts[$name]++;

                return 'done';
            }));
        }
        $sequence = Http::fakeSequence();
        if ($decision !== null) {
            $sequence->push($this->functionResponse('scope_safe', 'gate'));
        }
        $sequence->push($this->functionResponse('scope_ceiling', 'ceiling'));
        $sequence->push((string) file_get_contents(__DIR__.'/Fixtures/openai/text-reply.json'));
        $participant = Participant::query()->create(['name' => 'Scope test']);
        $session = app(PrismHarness::class)->for($participant)->session('chat');
        $first = $session->send('A test turn.', $requested);
        if ($decision !== null) {
            self::assertTrue($first->awaitingApproval());
            self::assertCount(1, $first->pendingApprovals());
            self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $counts);
            $reloaded = app(PrismHarness::class)->for($participant)->session('chat');
            self::assertNotSame($session, $reloaded);
            self::assertSame($session->key(), $reloaded->key());
            $beforeResume?->__invoke($reloaded);
            $request = $first->pendingApprovals()[0];
            match ($method) {
                'approve' => $reloaded->approve($request),
                'deny' => $reloaded->deny($request),
                default => $reloaded->decide([new ToolApprovalResponse($request->approvalId, $decision)]),
            };
        }
        $offers = [];
        foreach (Http::recorded() as [$request]) {
            $body = json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('harness_tool_names', (string) $request->body());
            $offers[] = array_map(static fn (array $tool): string => $tool['name'], $body['tools'] ?? []);
        }
        self::assertNotEmpty($offers);

        return ['counts' => $counts, 'offers' => $offers, 'session' => $session];
    }

    public function test_fresh_narrowed_control(): void
    {
        $row = $this->exercise(['scope_safe'], null);
        self::assertSame(['scope_safe'], $row['offers'][0]);
        self::assertSame(0, $row['counts']['scope_ceiling']);
    }

    public function test_full_ceiling_positive_control(): void
    {
        $row = $this->exercise(null, null);
        self::assertContains('scope_ceiling', $row['offers'][0]);
        self::assertSame(1, $row['counts']['scope_ceiling']);
    }

    public function test_approved_durable_resume_keeps_narrowed_scope(): void
    {
        $row = $this->exercise(['scope_safe'], true);
        self::assertSame(1, $row['counts']['scope_safe']);
        self::assertSame(0, $row['counts']['scope_ceiling']);
        foreach ($row['offers'] as $offer) {
            self::assertSame(['scope_safe'], $offer);
        }
    }

    public function test_denied_durable_resume_keeps_narrowed_scope(): void
    {
        $row = $this->exercise(['scope_safe'], false);
        self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $row['counts']);
        foreach ($row['offers'] as $offer) {
            self::assertSame(['scope_safe'], $offer);
        }
    }

    public function test_approve_delegates_without_widening(): void
    {
        $row = $this->exercise(['scope_safe'], true, method: 'approve');
        self::assertSame(['scope_safe' => 1, 'scope_ceiling' => 0], $row['counts']);
    }

    public function test_deny_delegates_without_widening(): void
    {
        $row = $this->exercise(['scope_safe'], false, method: 'deny');
        self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $row['counts']);
    }

    public function test_a_reduced_current_ceiling_wins_over_stored_scope(): void
    {
        $row = $this->exercise(['scope_safe'], true, function (): void {
            app(ModeRegistry::class)->register('chat', ['tools' => []]);
        });
        self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $row['counts']);
        self::assertSame([], $row['offers'][1]);
    }

    public function test_an_unrestricted_turn_stores_actual_names_rather_than_a_wildcard(): void
    {
        config()->set('prism-harness.agent.modes.chat.tools', ['*']);
        $row = $this->exercise(null, true, function (Session $session): void {
            $pending = $session->thread()->storedMessages()->where('type', 'assistant')->firstOrFail();
            self::assertContains('scope_safe', $pending->payload['harness_tool_names'] ?? []);
            self::assertContains('scope_ceiling', $pending->payload['harness_tool_names'] ?? []);
            self::assertNotContains('*', $pending->payload['harness_tool_names'] ?? []);
            app(ToolRegistry::class)->register((new Tool)->as('scope_late')->for('Registered later.')->using(fn (): string => 'done'));
        });
        self::assertSame($row['offers'][0], $row['offers'][1]);
        self::assertNotContains('scope_late', $row['offers'][1]);
    }

    public function test_legacy_approval_offers_only_approved_pending_names_with_a_constant_diagnostic(): void
    {
        Log::spy();
        $row = $this->exercise(null, true, function (Session $session): void {
            $pending = $session->thread()->storedMessages()->where('type', 'assistant')->firstOrFail();
            $payload = $pending->payload;
            unset($payload['harness_tool_names']);
            $pending->update(['payload' => $payload]);
        });
        self::assertSame(['scope_safe' => 1, 'scope_ceiling' => 0], $row['counts']);
        self::assertSame(['scope_safe'], $row['offers'][1]);
        Log::shouldHaveReceived('warning')->once()->with('Pending approval has no valid stored tool scope; offering approved requests only.');
    }

    public function test_a_denied_legacy_approval_has_an_empty_offer(): void
    {
        $row = $this->exercise(null, false, function (Session $session): void {
            $pending = $session->thread()->storedMessages()->where('type', 'assistant')->firstOrFail();
            $payload = $pending->payload;
            unset($payload['harness_tool_names']);
            $pending->update(['payload' => $payload]);
        });
        self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $row['counts']);
        self::assertSame([], $row['offers'][1]);
    }

    public function test_a_malformed_stored_scope_cannot_restore_the_ceiling(): void
    {
        $row = $this->exercise(null, false, function (Session $session): void {
            $pending = $session->thread()->storedMessages()->where('type', 'assistant')->firstOrFail();
            $payload = $pending->payload;
            $payload['harness_tool_names'] = ['*'];
            $pending->update(['payload' => $payload]);
            $session->thread()->record([new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse('not-pending', true)])]);
        });
        self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $row['counts']);
        self::assertSame([], $row['offers'][1]);
    }

    public function test_an_empty_stored_scope_is_not_a_missing_scope(): void
    {
        $row = $this->exercise(null, true, function (Session $session): void {
            $pending = $session->thread()->storedMessages()->where('type', 'assistant')->firstOrFail();
            $payload = $pending->payload;
            $payload['harness_tool_names'] = [];
            $pending->update(['payload' => $payload]);
        });
        self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $row['counts']);
        self::assertSame([], $row['offers'][1]);
    }

    public function test_a_completed_turn_does_not_restrict_a_later_turn(): void
    {
        $row = $this->exercise(['scope_safe'], true);
        self::assertNull($row['session']->thread()->approvalToolNames());
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fakeSequence()->push($this->functionResponse('scope_ceiling', 'later'))
            ->push((string) file_get_contents(__DIR__.'/Fixtures/openai/text-reply.json'));
        $row['session']->send('A new turn.');
        $body = json_decode((string) Http::recorded()[0][0]->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertContains('scope_ceiling', array_column($body['tools'], 'name'));
    }

    public function test_refreshed_approval_keeps_its_own_scope_after_waiting_for_the_real_lock(): void
    {
        config()->set('prism-harness.agent.modes.chat.requires_approval', ['scope_safe', 'scope_ceiling']);
        $ceilingCalls = 0;
        app(ToolRegistry::class)->register((new Tool)->as('scope_safe')->for('An inert tool.')->using(fn (): string => 'done'));
        app(ToolRegistry::class)->register((new Tool)->as('scope_ceiling')->for('An inert tool.')->using(function () use (&$ceilingCalls): string {
            $ceilingCalls++;

            return 'done';
        }));
        Http::fakeSequence()->push($this->functionResponse('scope_safe', 'gate'))
            ->push($this->functionResponse('scope_ceiling', 'ceiling'))
            ->push((string) file_get_contents(__DIR__.'/Fixtures/openai/text-reply.json'));
        $participant = Participant::query()->create(['name' => 'Scope scheduling']);
        $firstSession = app(PrismHarness::class)->for($participant)->session('chat');
        $first = $firstSession->send('An initial full-ceiling turn.');
        self::assertCount(1, $first->pendingApprovals());
        $store = new DatabaseSessionStore(DB::connection());
        $fresh = new class($participant, 'chat', $store, $store, runtime: app(AgentRuntime::class)) extends Session
        {
            private bool $scheduled = false;

            /**
             * @template TReturn
             *
             * @param  Closure(Session): TReturn  $callback
             * @return TReturn
             */
            #[Override]
            public function lock(Closure $callback, int $ttlSeconds = 10, int $waitSeconds = 5): mixed
            {
                if (! $this->scheduled) {
                    $this->scheduled = true;
                    // A concurrent durable writer runs before the REAL lock;
                    // all scope lookup and dispatch still use production code.
                    $this->thread()->record([
                        new UserMessage('A concurrent narrowed turn.'),
                        new AssistantMessage('', [new ToolCall('new-call', 'scope_ceiling', [])],
                            toolApprovalRequests: [new ToolApprovalRequest('new-approval', 'new-call')]),
                        new ToolResultMessage(toolApprovalResponses: [new ToolApprovalResponse('new-approval', true)]),
                    ], toolNames: ['scope_safe']);
                }

                return parent::lock($callback, $ttlSeconds, $waitSeconds);
            }
        };
        self::assertNotSame($firstSession, $fresh);
        self::assertSame($firstSession->key(), $fresh->key());
        $fresh->approve($first->pendingApprovals()[0]);
        self::assertSame(0, $ceilingCalls);
        Http::assertSentCount(3);
        foreach (Http::recorded()->slice(1) as [$request]) {
            $body = json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['scope_safe'], array_column($body['tools'] ?? [], 'name'));
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function surfaces(): iterable
    {
        foreach (['structured', 'stream'] as $surface) {
            foreach ([false, true] as $capped) {
                foreach ([false, true] as $initial) {
                    yield $surface.'-'.($capped ? 'capped' : 'uncapped').'-'.($initial ? 'initial' : 'resume') => [$surface, $capped, $initial];
                }
            }
        }
    }

    #[DataProvider('surfaces')]
    public function test_native_surfaces_persist_and_resume_the_actual_offer(string $surface, bool $capped, bool $initial): void
    {
        config()->set('prism.providers.anthropic.api_key', 'synthetic-offline');
        config()->set('prism-harness.agent.provider', 'anthropic');
        config()->set('prism-harness.agent.model', 'budget-model');
        $counts = ['scope_safe' => 0, 'scope_ceiling' => 0];
        foreach (array_keys($counts) as $name) {
            app(ToolRegistry::class)->register((new Tool)->as($name)->for('An inert test tool.')->using(function () use (&$counts, $name): string {
                $counts[$name]++;

                return 'done';
            }));
        }
        $reply = static function (int $index, string $name, bool $stream, bool $final = false): array|string {
            if ($stream) {
                return str_replace('"name":"tick"', '"name":"'.$name.'"', budgetSseReply($index, $final));
            }
            $data = budgetHttpReply($index, $final);
            if (! $final) {
                $data['content'][1]['name'] = $name;
            }

            return $data;
        };
        $sequence = Http::fakeSequence();
        $knownGap = $initial && ($surface === 'structured' || ! $capped);
        $sequence->push($reply(1, 'scope_safe', $initial && $surface === 'stream'), 200,
            $initial && $surface === 'stream' ? ['Content-Type' => 'text/event-stream'] : []);
        foreach ([2, 3] as $index) {
            $stream = ! $knownGap && $surface === 'stream';
            $sequence->push($reply($index, 'scope_ceiling', $stream, $knownGap || $index === 3), 200,
                $stream ? ['Content-Type' => 'text/event-stream'] : []);
        }
        $context = RunContext::root('scope-surface', new RunBudget(8, $capped ? 100.0 : null, maxOutputTokensPerCall: 1000),
            ['anthropic' => ['budget-model' => ['inputPerMTok' => 2, 'outputPerMTok' => 10,
                'cacheWritePerMTok' => 2.5, 'cacheReadPerMTok' => 0.1, 'toolOverheadTokens' => 286]]]);
        $participant = Participant::query()->create(['name' => 'Scope surface']);
        $session = app(PrismHarness::class)->for($participant)->session('chat');
        $approvalId = null;
        if ($initial && $surface === 'stream') {
            foreach ($session->stream('A test turn.', ['scope_safe'], context: $context) as $event) {
                if ($event instanceof ToolApprovalRequestEvent) {
                    $approvalId = $event->approvalId;
                }
            }
        } elseif ($initial) {
            try {
                $session->sendStructured('A test turn.', callBudgetSchema(), ['scope_safe'], context: $context);
            } catch (StructuredSchemaViolation) {
                // Pending structured responses currently reach document
                // validation after recording. Scope must survive that path.
            }
        } else {
            $session->send('A test turn.', ['scope_safe'], context: $context);
        }
        $pending = $session->thread()->storedMessages()->where('type', 'assistant')->get()
            ->last(fn ($row): bool => ($row->payload['tool_approval_requests'] ?? []) !== []);
        if ($knownGap) {
            // G-229.2 tracks these initial-turn transcript gaps. They may be
            // incomplete only AFTER proving the dropped call fails closed.
            self::assertNull($pending);
            self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $counts);
            if ($surface === 'stream') {
                self::assertNotNull($approvalId, 'A native approval event must actually have been emitted.');
            }
            $fresh = app(PrismHarness::class)->for($participant)->session('chat');
            self::assertNotSame($session, $fresh);
            // Structured turns lose the approval id too. Even an affirmative
            // response cannot recover a call absent from durable approval data.
            $fresh->decide([new ToolApprovalResponse($approvalId ?? 'lost-structured-approval', true)], context: $context);
            self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $counts);
            $fresh->send('A later turn.', context: $context);
            self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $counts);
            Http::assertSentCount(3);
            self::markTestIncomplete('G-229.2: initial '.$surface.($capped ? ' capped' : ' uncapped').' approval is not recorded; zero gated calls on initial turn, fresh-worker affirmative resume and later turn.');
        }
        self::assertNotNull($pending, 'The native surface must actually persist a pending approval.');
        self::assertSame(['scope_safe'], $pending->payload['harness_tool_names'] ?? null);
        self::assertCount(1, $pending->payload['tool_approval_requests']);
        self::assertSame(['scope_safe' => 0, 'scope_ceiling' => 0], $counts);
        $reloaded = app(PrismHarness::class)->for($participant)->session('chat');
        self::assertNotSame($session, $reloaded);
        $reloaded->thread()->record([new ToolResultMessage(toolApprovalResponses: [
            new ToolApprovalResponse($pending->payload['tool_approval_requests'][0]['approval_id'], true),
        ])]);
        if ($surface === 'stream') {
            iterator_to_array($reloaded->stream('', context: $context));
        } else {
            $reloaded->sendStructured('', callBudgetSchema(), context: $context);
        }
        self::assertSame(['scope_safe' => 1, 'scope_ceiling' => 0], $counts);
        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            $names = array_column(json_decode((string) $request->body(), true, flags: JSON_THROW_ON_ERROR)['tools'] ?? [], 'name');
            self::assertContains('scope_safe', $names);
            self::assertNotContains('scope_ceiling', $names);
        }
    }
}
