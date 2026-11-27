<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Harness\Exceptions\SessionLocked;
use Prism\Harness\Models\ThreadMessage;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\Participant;

// G-392/G-397/G-406: a row a host records through Thread::record() is
// outside every run, whatever run id it names, even while a run is writing
// the step it would supersede. During the run's own write it is serialised
// with the run (refused here, recording nothing); afterwards it supersedes
// the parked step, and each open call gets the one replaced denial.

const HOST_ROW_REPLACED = 'Tool call was not run because a later step replaced it';

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('prism.providers.openai.api_key', 'sk-test');
    config()->set('prism-harness.agent.provider', 'openai');
    config()->set('prism-harness.agent.model', 'gpt-4o');
    config()->set('prism-harness.agent.modes.chat.tools', ['weather']);
    config()->set('prism-harness.agent.modes.chat.requires_approval', ['weather']);

    app(ToolRegistry::class)->registerMany([
        (new Tool)->as('weather')->for('Weather.')->withStringParameter('city', 'City')->using(fn (string $city): string => "Sunny in {$city}"),
    ]);

    $usage = ['input_tokens' => 1, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 1, 'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 2];
    Http::fakeSequence()
        ->push(['id' => 'resp_h', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'c1', 'type' => 'function_call', 'status' => 'completed', 'arguments' => '{"city":"Paris"}', 'call_id' => 'c1', 'name' => 'weather']],
            'usage' => $usage])
        ->whenEmpty(Http::response(['id' => 'resp_h_final', 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => [['id' => 'msg_h', 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Done.', 'annotations' => []]]]], 'usage' => $usage]));
});

function hostRowSession(): Session
{
    return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
}

/** @return list<string> every distinct result stored for the Paris call */
function hostRowOutcomes(): array
{
    $outcomes = [];
    foreach (hostRowSession()->thread()->storedMessages()->reorder('position')->get() as $stored) {
        $message = $stored->toPrismMessage();
        if ($message instanceof ToolResultMessage) {
            foreach ($message->toolResults as $result) {
                $outcomes[] = (string) $result->result;
            }
        }
    }

    return array_values(array_unique($outcomes));
}

it('treats a host row recorded during the run\'s own parked-step write as outside, naming the run id or not', function (bool $namesRun): void {
    $attempts = [];
    ThreadMessage::created(function (ThreadMessage $row) use (&$attempts, $namesRun): void {
        if ($attempts !== [] || $row->type !== 'assistant' || ($row->payload['tool_approval_requests'] ?? []) === []) {
            return;
        }
        try {
            hostRowSession()->thread()->record([new UserMessage('Hurry.')], $namesRun ? $row->run_id : null);
            $attempts[] = 'recorded';
        } catch (SessionLocked) {
            $attempts[] = 'refused';
        }
    });

    hostRowSession()->send('Weather in Paris?');

    // During the run's write the host row was serialised with the run and
    // recorded nothing; the step is parked, nothing ended.
    expect($attempts)->toBe(['refused'])
        ->and(hostRowOutcomes())->toBe([]);

    // Recorded once the run has ended, it is outside the run: it supersedes
    // the parked step, and the open call gets the one replaced denial.
    hostRowSession()->thread()->record([new UserMessage('Hurry.')], $namesRun ? (hostRowSession()->run()['id'] ?? null) : null);

    expect(hostRowOutcomes())->toBe([HOST_ROW_REPLACED]);
})->with(['naming the run id' => [true], 'naming no run' => [false]]);
