<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Prism\Harness\Events\RunFailed;
use Prism\Harness\Events\RunFinished;
use Prism\Harness\Exceptions\StructuredSchemaViolation;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Structured\Step;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Tests\Fixtures\Participant;

/*
| A turn whose answer is a document, asked for by the connector-lab planning
| agent, whose whole output is one JSON document. Before this they asked for
| JSON in the brief and parsed AgentResponse::text(), tolerating a fence and
| refusing anything else — the refusal is still right, and this removes the
| parse rather than the decision.
*/

function planHarness(): PrismHarness
{
    return app(PrismHarness::class);
}

function planSchema(): ObjectSchema
{
    return new ObjectSchema(
        name: 'plan',
        description: 'A proposed plan',
        properties: [
            new StringSchema('title', 'What the plan is called'),
            new ArraySchema('steps', 'What to do', new StringSchema('step', 'One step')),
            new NumberSchema('confidence', 'How sure the model is'),
        ],
        requiredFields: ['title', 'steps'],
    );
}

function planDocument(): array
{
    return ['title' => 'Ship it', 'steps' => ['write', 'test'], 'confidence' => 0.8];
}

function askForAPlan(string $prompt = 'Plan the release'): mixed
{
    return planHarness()
        ->for(Participant::query()->firstOrCreate(['name' => 'Ada']))
        ->session('chat')
        ->sendStructured($prompt, planSchema());
}

/** @return list<array{type: string, payload: array<string, mixed>}> */
function storedRows(): array
{
    return Thread::query()->firstOrFail()->storedMessages()->orderBy('position')
        ->get()->map(fn ($row): array => ['type' => $row->type, 'payload' => $row->payload])->all();
}

it('returns the parsed document and the text it was read from', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withText(json_encode(planDocument()))
            ->withStructured(planDocument()),
    ]);

    $response = askForAPlan();

    expect($response->structured())->toBe(planDocument())
        ->and($response->text())->toBe(json_encode(planDocument()))
        ->and($response->runId)->toStartWith('run_')
        ->and($response->correlation()['root_run_id'])->toBe($response->runId);
});

it('records the document as TEXT, with the parsed object beside it', function (): void {
    // The thread stays readable by everything that reads text today. A
    // transcript that differs by the SHAPE of the request that produced it is
    // the same defect as one that differs when streamed, which this package
    // already refuses to have.
    Prism::fake([
        StructuredResponseFake::make()
            ->withText(json_encode(planDocument()))
            ->withStructured(planDocument()),
    ]);

    askForAPlan();

    $rows = storedRows();

    expect(array_column($rows, 'type'))->toBe(['user', 'assistant'])
        ->and($rows[1]['payload']['content'])->toBe(json_encode(planDocument()))
        ->and($rows[1]['payload']['additional_content']['structured'])->toBe(planDocument());
});

it('replays to a later turn as the text the model wrote', function (): void {
    // The point of storing the document as text: the NEXT turn sends it to the
    // provider as an ordinary assistant message, so a conversation that
    // included a structured answer reads back like any other.
    $fake = Prism::fake([
        StructuredResponseFake::make()->withText(json_encode(planDocument()))->withStructured(planDocument()),
        TextResponseFake::make()->withText('Yes, two steps.'),
    ]);

    $session = planHarness()->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $session->sendStructured('Plan the release', planSchema());
    $session->send('Is that all?');

    $fake->assertRequest(function (array $requests): void {
        $replayed = collect($requests[1]->messages())
            ->filter(fn ($message): bool => $message instanceof AssistantMessage)
            ->map(fn (AssistantMessage $message): string => $message->content)
            ->values()
            ->all();

        expect($replayed)->toBe([json_encode(planDocument())]);
    });
});

it('REFUSES a document that misses the schema, and says every way it missed', function (): void {
    // Not coerced, not trimmed to what fits, and never an empty success: a plan
    // with no steps settles a batch as done, which is indistinguishable from a
    // considered answer.
    $wrong = ['title' => 'Ship it', 'confidence' => 'very'];

    Prism::fake([
        StructuredResponseFake::make()->withText(json_encode($wrong))->withStructured($wrong),
    ]);

    try {
        askForAPlan();
        $this->fail('The turn returned a document the schema refuses.');
    } catch (StructuredSchemaViolation $violation) {
        expect($violation->code())->toBe('structured_schema_violation')
            ->and($violation->document())->toBe(json_encode($wrong))
            ->and($violation->problems())->toHaveCount(2)
            ->and($violation->problems()[0])->toContain('plan.steps is required and missing')
            ->and($violation->problems()[1])->toContain('plan.confidence');
    }
});

it('REFUSES text that holds no document at all', function (): void {
    Prism::fake([
        StructuredResponseFake::make()->withText('I am afraid I cannot help with that.')->withStructured(null),
    ]);

    try {
        askForAPlan();
        $this->fail('The turn returned something other than a document.');
    } catch (StructuredSchemaViolation $violation) {
        expect($violation->code())->toBe('structured_unreadable')
            ->and($violation->document())->toBe('I am afraid I cannot help with that.');
    }
});

it('records the refused document too, and fails the run', function (): void {
    // The exchange happened. A thread that omits the answer it did not like
    // cannot explain the retry sitting next to it.
    Event::fake([RunFailed::class, RunFinished::class]);

    $wrong = ['title' => 'Ship it'];
    Prism::fake([
        StructuredResponseFake::make()->withText(json_encode($wrong))->withStructured($wrong),
    ]);

    expect(fn () => askForAPlan())->toThrow(StructuredSchemaViolation::class);

    expect(array_column(storedRows(), 'type'))->toBe(['user', 'assistant']);

    Event::assertDispatched(RunFailed::class);
    Event::assertNotDispatched(RunFinished::class);
});

it('records the tool rounds behind the answer', function (): void {
    // A thread holding the document and forgetting the four tool calls behind
    // it shows a later turn an agent that knew something for no reason.
    $call = new ToolCall(id: 'call_1', name: 'search', arguments: ['query' => 'release']);
    $result = new ToolResult(toolCallId: 'call_1', toolName: 'search', args: ['query' => 'release'], result: 'the notes');

    Prism::fake([
        StructuredResponseFake::make()
            ->withText(json_encode(planDocument()))
            ->withStructured(planDocument())
            ->withSteps(collect([
                new Step(
                    text: '',
                    finishReason: FinishReason::ToolCalls,
                    usage: new Usage(1, 1),
                    meta: new Meta('fake', 'fake'),
                    messages: [],
                    systemPrompts: [],
                    structured: [],
                    toolCalls: [$call],
                    toolResults: [$result],
                ),
                new Step(
                    text: json_encode(planDocument()),
                    finishReason: FinishReason::Stop,
                    usage: new Usage(1, 1),
                    meta: new Meta('fake', 'fake'),
                    messages: [],
                    systemPrompts: [],
                    structured: planDocument(),
                ),
            ])),
    ]);

    askForAPlan();

    $rows = storedRows();

    expect(array_column($rows, 'type'))->toBe(['user', 'assistant', 'tool_result', 'assistant'])
        ->and($rows[1]['payload']['tool_calls'][0]['name'])->toBe('search')
        ->and($rows[3]['payload']['additional_content']['structured'])->toBe(planDocument());
});

it('takes the mode into account, like any other turn', function (): void {
    config()->set('prism-harness.agent.modes.plan.system_prompt', 'You plan, briefly.');

    Prism::fake([
        StructuredResponseFake::make()->withText(json_encode(planDocument()))->withStructured(planDocument()),
    ]);

    $session = planHarness()->for(Participant::query()->firstOrCreate(['name' => 'Ada']))->session('chat');
    $session->usingMode('plan');
    $session->sendStructured('Plan the release', planSchema());

    expect($session->run()['mode'] ?? null)->toBe('plan');
});
