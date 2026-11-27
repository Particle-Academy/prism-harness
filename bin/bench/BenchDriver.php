<?php

declare(strict_types=1);

namespace Prism\Harness\Bench;

use Illuminate\Contracts\Foundation\Application;
use Prism\Harness\Contracts\HasErrorCode;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Harness\Participants\ParticipantAdapters;
use Prism\Harness\Participants\ParticipantRef;
use Prism\Harness\PrismHarness;
use Prism\Harness\Subagents\RunBudget;
use Prism\Harness\Subagents\RunContext;
use Prism\Harness\Tools\ToolRegistry;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolApprovalResponse;
use Throwable;

/**
 * The PHP side of `prism-bench-driver/1` (prism-parity specs/bench-driver.md).
 *
 * Runs ONE benchmark task through the real harness loop: a harness session,
 * the harness's per-call hard cost cap, its step budget and its approval path.
 * Every benchmark tool is a proxy: the call is written to the adapter and the
 * driver blocks until the adapter answers it, so the task's sandbox is only
 * ever touched by the adapter, inside the task container.
 *
 * stdout carries the protocol and nothing else. Nothing here reads, prints or
 * forwards a credential: the provider key reaches Prism core through its own
 * configuration, never through a message.
 */
final class BenchDriver
{
    public const PROTOCOL = 'prism-bench-driver/1';

    /** A receiver refuses a longer line rather than buffering it. */
    public const MAX_LINE_BYTES = 8 * 1024 * 1024;

    private const ID = '/\A[A-Za-z0-9._:-]{1,128}\z/';

    private const TOOL_NAME = '/\A[A-Za-z0-9_-]{1,64}\z/';

    private int $toolCalls = 0;

    private bool $aborted = false;

    private ?RunContext $context = null;

    /** @param resource $in @param resource $out */
    public function __construct(private $in, private $out, private readonly string $harnessVersion = 'unknown') {}

    /** Runs the whole exchange and returns the process exit code. */
    public function run(Application $app): int
    {
        $this->emit('hello', [
            'protocol' => self::PROTOCOL,
            'driver' => ['name' => 'prism-harness-php', 'harness_version' => $this->harnessVersion, 'runtime' => 'php '.PHP_VERSION],
        ]);

        try {
            $start = $this->read('start');
            $task = $this->validate($start);
        } catch (ProtocolError $error) {
            return $this->end('protocol_error', $error->getMessage(), '', 0, 0.0, 2);
        } catch (InvalidStart $error) {
            return $this->end('refused', $error->getMessage(), '', 0, 0.0, 3);
        }

        try {
            return $this->execute($app, $task);
        } catch (ProtocolError $error) {
            return $this->end('protocol_error', $error->getMessage(), '', $this->steps(), $this->charged(), 2);
        } catch (Throwable $failure) {
            // Setup failed (a host app that already owns the `bench` adapter or
            // a tool name, say). The adapter still gets an `end`, and only the
            // class: a message can carry a request URL or a key.
            fwrite(STDERR, '[prism-harness-bench] setup failed: '.$failure::class.'
');

            return $this->end('error', $failure::class, '', $this->steps(), $this->charged(), 1);
        }
    }

    /** @param array<string, mixed> $task */
    private function execute(Application $app, array $task): int
    {
        $config = $app['config'];
        $toolNames = array_map(fn (array $tool): string => $tool['name'], $task['tools']);
        $limits = $task['limits'];
        $config->set('prism-harness.agent.provider', $task['model']['provider']);
        $config->set('prism-harness.agent.model', $task['model']['id']);
        $config->set('prism-harness.agent.max_steps_ceiling', $limits['max_steps']);
        $config->set('prism-harness.agent.max_output_tokens_per_call', $limits['max_output_tokens_per_call']);
        $config->set('prism-harness.agent.default', 'bench');
        $config->set('prism-harness.agent.modes.bench', [
            'system_prompt' => $task['system_prompt'] ?? 'You are an autonomous agent solving a task. Use the tools offered to complete it, then stop.',
            'tools' => $toolNames,
            'skills' => [],
            'max_steps' => $limits['max_steps'],
            'provider_options' => $task['model']['provider_options'] ?? [],
        ]);

        $registry = $app->make(ToolRegistry::class);
        foreach ($task['tools'] as $declared) {
            $registry->register($this->proxy($declared), 'bench');
        }

        $app->make(ParticipantAdapters::class)->register('bench', static fn (ParticipantRef $ref): object => $ref);
        $observer = new UsageReporter(fn (string $type, array $fields) => $this->emit($type, $fields));
        $this->context = RunContext::root($task['run_id'], new RunBudget(
            maxSteps: $limits['max_steps'],
            maxCostUsd: (float) $limits['max_cost_usd'],
            maxOutputTokensPerCall: $limits['max_output_tokens_per_call'],
        ), [$task['model']['provider'] => [$task['model']['id'] => $task['pricing']]], $observer);

        $session = $app->make(PrismHarness::class)
            ->session(new ParticipantRef('bench', $task['task_id']), 'bench:'.$task['run_id']);

        // One run, one or more user turns on the same session and the same
        // ledger: the cap and the step budget are per RUN, never per turn.
        $prompt = $task['instruction'];
        while (true) {
            try {
                $response = $session->send($prompt, context: $this->context);
                // A benchmark has no human. Every gated call is granted, and every
                // grant is reported, so no result hides that approvals were automatic.
                while ($response->awaitingApproval()) {
                    $decisions = [];
                    foreach ($response->pendingApprovals() as $pending) {
                        $this->emit('approval', ['approval_id' => $pending->approvalId, 'tool_call_id' => $pending->toolCallId,
                            'decision' => 'granted', 'policy' => 'auto_grant_all']);
                        $decisions[] = new ToolApprovalResponse($pending->approvalId, approved: true);
                    }
                    $response = $session->decide($decisions, context: $this->context);
                }
            } catch (ProtocolError $error) {
                throw $error;
            } catch (Throwable $failure) {
                return $this->stopped($failure);
            }

            // A run that ran out of steps RETURNS rather than throws: the model's
            // last word was a tool call it never saw answered. Calling that
            // `completed` would credit the harness with a stop it did not make.
            $status = match (true) {
                $this->aborted => 'aborted',
                $response->response->finishReason === FinishReason::ToolCalls => 'step_limit',
                default => 'completed',
            };
            if ($status !== 'completed') {
                return $this->end($status, null, $response->text(), $this->steps(), $this->charged(), 0);
            }

            $this->emit('reply', ['text' => $response->text()]);
            $next = $this->read('user', 'close');
            if ($next['type'] === 'close') {
                return $this->end('completed', null, $response->text(), $this->steps(), $this->charged(), 0);
            }
            if (! is_string($next['text'] ?? null) || $next['text'] === '') {
                throw new ProtocolError('user.text must be a non-empty string');
            }
            $prompt = $next['text'];
        }
    }

    /** A refusal from the harness, classified by what actually stopped it. */
    private function stopped(Throwable $failure): int
    {
        $code = $failure instanceof HasErrorCode ? $failure->code() : null;
        $budget = $this->context?->budget;
        $ledger = $this->context?->ledger;
        $status = match (true) {
            $this->aborted => 'aborted',
            $failure instanceof RunNotPermitted && $code === 'call_budget_exceeded' => 'budget_stopped',
            $failure instanceof RunNotPermitted && $budget !== null && $ledger !== null && $ledger->steps() >= $budget->maxSteps => 'step_limit',
            $failure instanceof RunNotPermitted && $budget?->maxCostUsd !== null && $ledger !== null
                && $ledger->costUsd() >= $budget->maxCostUsd => 'budget_stopped',
            $failure instanceof RunNotPermitted && $code !== 'run_not_permitted' => 'refused',
            default => 'error',
        };
        fwrite(STDERR, '[prism-harness-bench] run stopped: '.$failure::class.($code !== null ? ' ('.$code.')' : '')."\n");

        return $this->end($status, $code ?? $failure::class, '', $this->steps(), $this->charged(), 0);
    }

    /** @param array{name: string, description: string, parameters: array<string, mixed>} $declared */
    private function proxy(array $declared): Tool
    {
        $tool = (new Tool)->as($declared['name'])->for($declared['description'] === '' ? $declared['name'] : $declared['description'])
            ->using(fn (mixed ...$arguments): string => $this->call($declared['name'], $arguments))
            ->withoutErrorHandling();
        $required = $declared['parameters']['required'] ?? [];
        foreach ($declared['parameters']['properties'] ?? [] as $name => $schema) {
            $tool->withParameter(new RawSchema((string) $name, is_array($schema) ? $schema : []), in_array($name, $required, true));
        }

        return $tool;
    }

    /** @param array<int|string, mixed> $arguments */
    private function call(string $name, array $arguments): string
    {
        if ($this->aborted) {
            return 'The run was stopped.';
        }
        $callId = 't'.(++$this->toolCalls);
        $this->emit('tool_call', ['call_id' => $callId, 'name' => $name, 'arguments' => (object) $arguments]);
        $reply = $this->read('tool_result', 'abort');
        if ($reply['type'] === 'abort') {
            // Cooperative: the in-flight call has nothing to return, and the
            // cancelled ledger refuses the next model call before transport.
            $this->aborted = true;
            $this->context?->ledger->cancel('aborted by the benchmark adapter');

            return 'The run was stopped.';
        }
        if (($reply['call_id'] ?? null) !== $callId || ! is_string($reply['output'] ?? null) || ! is_bool($reply['is_error'] ?? null)) {
            throw new ProtocolError('tool_result does not answer '.$callId);
        }

        return $reply['is_error'] ? 'ERROR: '.$reply['output'] : $reply['output'];
    }

    /**
     * @param  array<string, mixed>  $start
     * @return array<string, mixed>
     */
    private function validate(array $start): array
    {
        foreach (['run_id', 'task_id'] as $id) {
            if (! is_string($start[$id] ?? null) || preg_match(self::ID, $start[$id]) !== 1) {
                throw new InvalidStart("start.{$id} is missing or malformed");
            }
        }
        if (! is_string($start['instruction'] ?? null) || $start['instruction'] === '') {
            throw new InvalidStart('start.instruction is required');
        }
        if (array_key_exists('system_prompt', $start) && ! is_string($start['system_prompt'])) {
            throw new InvalidStart('start.system_prompt must be a string');
        }
        if (($start['approval_policy'] ?? null) !== 'auto_grant_all') {
            throw new InvalidStart('start.approval_policy must be auto_grant_all in v1');
        }
        $model = $start['model'] ?? null;
        if (! is_array($model) || ! is_string($model['provider'] ?? null) || ! is_string($model['id'] ?? null)
            || $model['provider'] === '' || $model['id'] === ''
            || (isset($model['provider_options']) && (! is_array($model['provider_options']) || array_is_list($model['provider_options']) && $model['provider_options'] !== []))) {
            throw new InvalidStart('start.model needs provider and id; provider_options must be an object');
        }
        $limits = $start['limits'] ?? null;
        $cap = is_array($limits) ? ($limits['max_cost_usd'] ?? null) : null;
        if (! (is_int($cap) || is_float($cap)) || ! is_finite((float) $cap) || $cap <= 0) {
            throw new InvalidStart('start.limits.max_cost_usd is required and must be positive: this driver never runs uncapped');
        }
        foreach (['max_steps', 'max_output_tokens_per_call'] as $limit) {
            if (! is_int($limits[$limit] ?? null) || $limits[$limit] < 1) {
                throw new InvalidStart("start.limits.{$limit} must be a positive integer");
            }
        }
        $tools = $start['tools'] ?? null;
        if (! is_array($tools) || ! array_is_list($tools)) {
            throw new InvalidStart('start.tools must be a list');
        }
        $seen = [];
        foreach ($tools as $tool) {
            if (! is_array($tool) || ! is_string($tool['name'] ?? null) || preg_match(self::TOOL_NAME, $tool['name']) !== 1
                || isset($seen[$tool['name']]) || ! is_string($tool['description'] ?? null)
                || ! is_array($tool['parameters'] ?? null) || ($tool['parameters']['type'] ?? null) !== 'object'
                || (isset($tool['parameters']['properties']) && ! is_array($tool['parameters']['properties']))
                || (isset($tool['parameters']['required']) && ! is_array($tool['parameters']['required']))) {
                throw new InvalidStart('start.tools has a malformed or duplicate tool');
            }
            $seen[$tool['name']] = true;
        }
        $pricing = $start['pricing'] ?? null;
        if (! is_array($pricing) || array_is_list($pricing)) {
            throw new InvalidStart('start.pricing is required');
        }

        return $start;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ProtocolError
     */
    private function read(string ...$types): array
    {
        $line = fgets($this->in, self::MAX_LINE_BYTES + 2);
        if ($line === false) {
            throw new ProtocolError('the adapter closed stdin while '.implode(' or ', $types).' was expected');
        }
        if (! str_ends_with($line, "\n") && ! feof($this->in)) {
            throw new ProtocolError('a line exceeded '.self::MAX_LINE_BYTES.' bytes');
        }
        try {
            $message = json_decode(rtrim($line, "\r\n"), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProtocolError('a line was not JSON');
        }
        if (! is_array($message) || array_is_list($message) || ($message['v'] ?? null) !== 1 || ! in_array($message['type'] ?? null, $types, true)) {
            throw new ProtocolError('expected '.implode(' or ', $types).' (v 1)');
        }

        return $message;
    }

    /** @param array<string, mixed> $fields */
    private function emit(string $type, array $fields): void
    {
        fwrite($this->out, json_encode(['v' => 1, 'type' => $type, ...$fields], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        fflush($this->out);
    }

    private function end(string $status, ?string $code, string $finalText, int $steps, float $charged, int $exit): int
    {
        $this->emit('end', ['status' => $status, 'code' => $code, 'final_text' => $finalText, 'steps' => $steps, 'charged_usd' => round($charged, 8)]);

        return $exit;
    }

    private function steps(): int
    {
        return $this->context?->ledger->steps() ?? 0;
    }

    private function charged(): float
    {
        return $this->context?->ledger->costUsd() ?? 0.0;
    }
}
