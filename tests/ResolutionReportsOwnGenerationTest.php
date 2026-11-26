<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Tests\Support\G360;

// A resumed run reports the approved calls it ran inside its OWN next
// generation's trace. A stream hands control to the host between its
// resolution events and its generation; nothing the host does there (its own
// generation, another session's stream) may take this run's reports, which
// carry the call's arguments and result when content capture is on.

beforeEach(function (): void {
    G360::reset();
    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', true);
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['search']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['search']);
    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('search')->for('Search.')->withStringParameter('query', 'Query')
            ->using(fn (string $query): string => 'found '.$query),
    ]);
    // Which session each generation trace belongs to, and where each report went.
    $this->traces = [];
    $this->reports = [];
    Event::listen(GenerationStarted::class, function (GenerationStarted $event): void {
        $this->traces[$event->context->traceId] = $event->context->sessionId ?? 'host';
    });
    Event::listen(ToolInvoked::class, function (ToolInvoked $event): void {
        $this->reports[] = [$event->toolCall?->arguments()['query'] ?? null, $this->traces[$event->context->traceId] ?? 'none'];
    });
});

/** A session parked on one approved call whose decision is recorded, not yet resolved. */
function parkedWithRecordedApproval(string $name, string $query): Session
{
    $session = G360::session($name);
    G360::calls([['search', ['query' => $query]]]);
    $pending = $session->send('go')->pendingApprovals();
    $decisions = array_map(fn ($approval) => new ToolApprovalResponse($approval->approvalId, true), $pending);
    (fn () => $this->recordDecisions($decisions))->call($session);

    return $session;
}

it('keeps a run report out of a host generation started between its resolution and its generation', function (): void {
    $ada = parkedWithRecordedApproval('Ada', 'ada-private');
    G360::text('Host summary.');
    G360::text('Done.');

    $stream = $ada->stream('');
    $stream->current();
    Prism::text()->using('openai', 'gpt-4o')->withPrompt('host summary')->asText();
    iterator_to_array($stream, false);

    expect($this->reports)->toBe([['ada-private', $ada->key()]]);
});

it('keeps each session report in its own generation when two streams interleave', function (): void {
    $ada = parkedWithRecordedApproval('Ada', 'ada-private');
    $bob = parkedWithRecordedApproval('Bob', 'bob-private');
    G360::text('Done for Ada.');
    G360::text('Done for Bob.');

    $sa = $ada->stream('');
    $sa->current();
    $sb = $bob->stream('');
    $sb->current();
    iterator_to_array($sa, false);
    iterator_to_array($sb, false);

    expect($this->reports)->toBe([['ada-private', $ada->key()], ['bob-private', $bob->key()]]);
});

it('keeps a run report out of a generation a host listener starts inside the run generation', function (): void {
    $ada = parkedWithRecordedApproval('Ada', 'ada-private');
    G360::text('Done.');
    G360::text('Nested.');
    $nested = false;
    Event::listen(GenerationStarted::class, function (GenerationStarted $event) use (&$nested): void {
        if (! $nested && $event->context->sessionId !== null) {
            $nested = true;
            Prism::text()->using('openai', 'gpt-4o')->withPrompt('nested')->asText();
        }
    });

    iterator_to_array($ada->stream(''), false);

    expect($this->reports)->toBe([['ada-private', $ada->key()]]);
});
