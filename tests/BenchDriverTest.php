<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Harness\Bench\BenchDriver;
use Prism\Harness\Participants\ParticipantAdapters;

foreach (['ProtocolError', 'InvalidStart', 'UsageReporter', 'BenchDriver'] as $benchClass) {
    require_once dirname(__DIR__).'/bin/bench/'.$benchClass.'.php';
}

// G-393: the PHP side of prism-bench-driver/1 (prism-parity specs/bench-driver.md).
// The fixture is vendored from prism-parity; the driver must produce its
// driver-side lines when the provider gives the fixture's replies.

/** @return list<array{dir: string, msg: array<string, mixed>, volatile?: list<string>}> */
function benchFixture(): array
{
    $lines = file(__DIR__.'/Fixtures/bench-driver/conformance.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return array_map(fn (string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR), $lines);
}

/** @param list<string> $adapterLines @return array{exit: int, lines: list<array<string, mixed>>} */
function runBenchDriver(array $adapterLines): array
{
    config()->set('prism.providers.anthropic.api_key', 'test-only');
    $in = fopen('php://temp', 'w+b');
    fwrite($in, implode('', array_map(fn (string $line): string => $line."\n", $adapterLines)));
    rewind($in);
    $out = fopen('php://temp', 'w+b');
    $exit = (new BenchDriver($in, $out, 'test'))->run(app());
    rewind($out);
    $lines = array_map(fn (string $line): array => json_decode($line, true, 64, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", (string) stream_get_contents($out)))));

    return ['exit' => $exit, 'lines' => $lines];
}

function benchStart(array $overrides = []): array
{
    $start = collect(benchFixture())->firstWhere('msg.type', 'start')['msg'];

    return array_replace_recursive($start, $overrides);
}

function fakeBenchReplies(): void
{
    $replies = json_decode((string) file_get_contents(__DIR__.'/Fixtures/bench-driver/replies.json'), true, 64, JSON_THROW_ON_ERROR);
    $sequence = Http::fakeSequence();
    foreach ($replies as $reply) {
        $sequence->push($reply);
    }
}

it('produces every driver-side line of the parity fixture, in order', function (): void {
    Http::preventStrayRequests();
    fakeBenchReplies();
    $fixture = benchFixture();
    $adapter = array_map(fn (array $line): string => json_encode($line['msg'], JSON_THROW_ON_ERROR),
        array_values(array_filter($fixture, fn (array $line): bool => $line['dir'] === 'adapter')));

    $run = runBenchDriver($adapter);

    $expected = array_values(array_filter($fixture, fn (array $line): bool => $line['dir'] === 'driver'));
    expect($run['exit'])->toBe(0)->and($run['lines'])->toHaveCount(count($expected));
    foreach ($expected as $index => $line) {
        $actual = $run['lines'][$index];
        foreach ($line['volatile'] ?? [] as $path) {
            expect(data_get($actual, $path))->not->toBeNull("{$line['msg']['type']}.{$path} must be present");
            data_set($actual, $path, data_get($line['msg'], $path));
        }
        expect($actual)->toEqual($line['msg']);
    }
    // The tool output the adapter returned is what the model saw next.
    $second = Http::recorded()[1][0];
    expect($second->body())->toContain('README.md');
});

it('refuses to start without a positive cost cap, before any provider request', function (mixed $cap): void {
    Http::preventStrayRequests();
    Http::fake();
    $start = benchStart();
    $start['limits']['max_cost_usd'] = '__CAP__';
    // Spliced in as raw JSON, so a value json_encode cannot produce (1e400
    // decodes to INF) still reaches the driver.
    $line = str_replace('"__CAP__"', $cap, json_encode($start, JSON_THROW_ON_ERROR));

    $run = runBenchDriver([$line]);

    Http::assertNothingSent();
    expect($run['exit'])->toBe(3)->and($run['lines'][1])->toMatchArray(['type' => 'end', 'status' => 'refused', 'steps' => 0]);
})->with(['0', '-1', 'null', '"1.0"', 'true', '1e400', '-0.0']);

it('refuses a policy other than auto_grant_all, and malformed tools', function (array $overrides): void {
    Http::preventStrayRequests();
    Http::fake();

    $run = runBenchDriver([json_encode(benchStart($overrides))]);

    Http::assertNothingSent();
    expect($run['lines'][1])->toMatchArray(['type' => 'end', 'status' => 'refused']);
})->with([
    'policy' => [['approval_policy' => 'ask']],
    'tool name' => [['tools' => [['name' => 'bash ', 'description' => 'x', 'parameters' => ['type' => 'object']]]]],
    'run id' => [['run_id' => '../../etc']],
]);

it('ends protocol_error on a line that is not the expected message', function (string $line): void {
    Http::preventStrayRequests();
    Http::fake();

    $run = runBenchDriver([$line]);

    expect($run['exit'])->toBe(2)->and($run['lines'][1])->toMatchArray(['type' => 'end', 'status' => 'protocol_error']);
})->with(['not json', '[1]', '{"v":2,"type":"start"}', '{"v":1,"type":"tool_result"}']);

it('ends protocol_error when a tool result answers a different call', function (): void {
    Http::preventStrayRequests();
    fakeBenchReplies();

    $run = runBenchDriver([json_encode(benchStart()),
        json_encode(['v' => 1, 'type' => 'tool_result', 'call_id' => 't9', 'output' => 'x', 'is_error' => false])]);

    expect($run['exit'])->toBe(2)->and(end($run['lines']))->toMatchArray(['type' => 'end', 'status' => 'protocol_error'])
        ->and(Http::recorded())->toHaveCount(1);
});

it('makes no further model call after an abort', function (): void {
    Http::preventStrayRequests();
    fakeBenchReplies();

    $run = runBenchDriver([json_encode(benchStart()), json_encode(['v' => 1, 'type' => 'abort', 'reason' => 'arm cap'])]);

    expect(Http::recorded())->toHaveCount(1)
        ->and(end($run['lines']))->toMatchArray(['type' => 'end', 'status' => 'aborted', 'steps' => 1]);
});

it('stops at the per-call cost cap rather than spending past it', function (): void {
    Http::preventStrayRequests();
    fakeBenchReplies();
    // Each call reserves its worst case: 256 output tokens at $5/M is $0.00128
    // before input, so a $0.001 cap cannot admit even the first call.
    $start = benchStart(['limits' => ['max_cost_usd' => 0.001]]);

    $run = runBenchDriver([json_encode($start)]);

    Http::assertNothingSent();
    expect(end($run['lines']))->toMatchArray(['type' => 'end', 'status' => 'budget_stopped', 'code' => 'call_budget_exceeded', 'steps' => 0]);
});

it('ends step_limit when the step budget runs out mid-task', function (): void {
    Http::preventStrayRequests();
    fakeBenchReplies();
    $start = benchStart(['limits' => ['max_steps' => 1]]);
    $result = ['v' => 1, 'type' => 'tool_result', 'call_id' => 't1', 'output' => 'README.md', 'is_error' => false];

    $run = runBenchDriver([json_encode($start), json_encode($result)]);

    expect(Http::recorded())->toHaveCount(1)
        ->and(end($run['lines']))->toMatchArray(['type' => 'end', 'status' => 'step_limit', 'steps' => 1]);
});

it('never puts a credential on the wire it writes', function (): void {
    Http::preventStrayRequests();
    fakeBenchReplies();
    config()->set('prism.providers.anthropic.api_key', 'sk-ant-should-never-appear');
    $fixture = benchFixture();
    $adapter = array_map(fn (array $line): string => json_encode($line['msg']),
        array_values(array_filter($fixture, fn (array $line): bool => $line['dir'] === 'adapter')));
    $in = fopen('php://temp', 'w+b');
    fwrite($in, implode("\n", $adapter)."\n");
    rewind($in);
    $out = fopen('php://temp', 'w+b');

    (new BenchDriver($in, $out, 'test'))->run(app());
    rewind($out);

    expect((string) stream_get_contents($out))->not->toContain('sk-ant-should-never-appear');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'sk-ant-should-never-appear'));
});

it('still ends the exchange when setup fails, naming only the class', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    app(ParticipantAdapters::class)->register('bench', static fn (): object => new stdClass);

    $run = runBenchDriver([json_encode(benchStart())]);

    Http::assertNothingSent();
    expect($run['exit'])->toBe(1)->and(end($run['lines']))->toMatchArray(['type' => 'end', 'status' => 'error', 'code' => LogicException::class]);
});

it('keeps one ledger across user turns and refuses an empty user turn', function (): void {
    Http::preventStrayRequests();
    fakeBenchReplies();
    $result = ['v' => 1, 'type' => 'tool_result', 'call_id' => 't1', 'output' => 'README.md', 'is_error' => false];

    $run = runBenchDriver([json_encode(benchStart()), json_encode($result), json_encode(['v' => 1, 'type' => 'user', 'text' => ''])]);

    expect($run['exit'])->toBe(2)
        ->and(collect($run['lines'])->where('type', 'reply')->count())->toBe(1)
        ->and(end($run['lines']))->toMatchArray(['type' => 'end', 'status' => 'protocol_error', 'steps' => 2]);
});
