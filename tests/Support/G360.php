<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use Prism\Harness\Models\Thread;
use Prism\Harness\PrismHarness;
use Prism\Harness\Sessions\Session;
use Tests\Fixtures\Participant;

/**
 * Scripted OpenAI Responses for the G-360 plan tests: every model turn is a
 * response built here, served by Prism's real OpenAI handler over faked HTTP
 * with stray requests refused, so no request leaves the process.
 */
final class G360
{
    /** A queue entry that makes that model request fail, as a provider outage does. */
    public const FAIL = '__provider_down__';

    private static int $call = 0;

    /** @var list<string> */
    public static array $queue = [];

    /**
     * Called as each model request arrives, before it is answered, with the
     * 1-based number of that request: what another process does while the
     * provider is thinking (G-285).
     *
     * @var (\Closure(int): void)|null
     */
    public static ?\Closure $onRequest = null;

    private static int $requests = 0;

    public static function reset(): void
    {
        self::$call = 0;
        self::$queue = [];
        self::$onRequest = null;
        self::$requests = 0;
        Http::preventStrayRequests();
        Http::fake(['*' => function ($request) {
            self::$requests++;
            if (self::$onRequest !== null) {
                (self::$onRequest)(self::$requests);
            }
            $next = array_shift(self::$queue);
            if ($next === null) {
                throw new \RuntimeException('The scripted model has no response left.');
            }
            if ($next === self::FAIL) {
                throw new \RuntimeException('provider down');
            }
            if ((json_decode((string) $request->body(), true)['stream'] ?? false) === true) {
                return Http::response(self::sse($next), 200, ['Content-Type' => 'text/event-stream']);
            }

            return Http::response($next);
        }]);
    }

    /**
     * One step's calls. A third element, when given, is the call's id (its
     * item id and its call_id): a provider that repeats ids in one step.
     *
     * @param  list<array{0: string, 1: array<string, mixed>, 2?: string}>  $calls
     */
    public static function calls(array $calls): void
    {
        $output = [];
        foreach ($calls as $call) {
            [$name, $arguments] = $call;
            $id = ++self::$call;
            $output[] = [
                'id' => $call[2] ?? 'fc_'.$id, 'type' => 'function_call', 'status' => 'completed',
                'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'call_id' => $call[2] ?? 'call_g360_'.$id, 'name' => $name,
            ];
        }
        self::$queue[] = self::response($output);
    }

    public static function text(string $text = 'Done.'): void
    {
        self::$queue[] = self::response([[
            'id' => 'msg_'.(++self::$call), 'type' => 'message', 'status' => 'completed', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'annotations' => [], 'text' => $text, 'refusal' => null]],
        ]]);
    }

    /** @param list<array<string, mixed>> $output */
    private static function response(array $output): string
    {
        return (string) json_encode([
            'id' => 'resp_'.(++self::$call), 'object' => 'response', 'created_at' => 1741989983, 'status' => 'completed',
            'model' => 'gpt-4o-2024-08-06', 'output' => $output,
            'usage' => ['input_tokens' => 10, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 5,
                'output_tokens_details' => ['reasoning_tokens' => 0], 'total_tokens' => 15],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The same response as server-sent events, as the Responses API streams it. */
    private static function sse(string $json): string
    {
        $response = json_decode($json, true);
        $events = [['type' => 'response.created', 'response' => [...$response, 'status' => 'in_progress', 'output' => []]]];
        foreach ($response['output'] as $index => $item) {
            if ($item['type'] === 'function_call') {
                $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => [...$item, 'arguments' => '', 'status' => 'in_progress']];
                $events[] = ['type' => 'response.function_call_arguments.delta', 'output_index' => $index, 'item_id' => $item['id'], 'delta' => $item['arguments']];
                $events[] = ['type' => 'response.function_call_arguments.done', 'output_index' => $index, 'item_id' => $item['id'], 'arguments' => $item['arguments']];
                $events[] = ['type' => 'response.output_item.done', 'output_index' => $index, 'item' => $item];

                continue;
            }
            $text = $item['content'][0]['text'];
            $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => [...$item, 'content' => [], 'status' => 'in_progress']];
            $events[] = ['type' => 'response.output_text.delta', 'output_index' => $index, 'item_id' => $item['id'], 'content_index' => 0, 'delta' => $text];
            $events[] = ['type' => 'response.output_text.done', 'output_index' => $index, 'item_id' => $item['id'], 'content_index' => 0, 'text' => $text];
            $events[] = ['type' => 'response.output_item.done', 'output_index' => $index, 'item' => $item];
        }
        $events[] = ['type' => 'response.completed', 'response' => $response];

        $out = '';
        foreach ($events as $event) {
            $out .= 'event: '.$event['type'].PHP_EOL.'data: '.json_encode($event).PHP_EOL.PHP_EOL;
        }

        return str_replace(PHP_EOL, "\n", $out);
    }

    public static function session(string $name = 'Ada'): Session
    {
        return app(PrismHarness::class)->for(Participant::query()->firstOrCreate(['name' => $name]))->session('chat');
    }

    /** The decoded body of the n-th request the model received (0-based). */
    public static function request(int $index): array
    {
        return json_decode((string) Http::recorded()[$index][0]->body(), true);
    }

    public static function lastRequest(): array
    {
        $recorded = Http::recorded();

        return json_decode((string) $recorded[count($recorded) - 1][0]->body(), true);
    }

    /** @return list<string> the tool names offered in the n-th request */
    public static function offered(int $index): array
    {
        return array_values(array_map(fn (array $tool): string => $tool['name'], self::request($index)['tools'] ?? []));
    }

    /** Every stored thread row, as JSON text. */
    public static function threadText(): string
    {
        return Thread::query()->get()->flatMap(fn (Thread $thread) => $thread->storedMessages()->get(['payload']))
            ->map(fn ($row): string => (string) json_encode($row->payload, JSON_UNESCAPED_UNICODE))->implode("\n");
    }

    /** The tool outputs sent to the model in the last request, by call id. */
    public static function outputs(): array
    {
        return collect(self::lastRequest()['input'] ?? [])->where('type', 'function_call_output')->pluck('output', 'call_id')->all();
    }

    /** @return list<array<string, mixed>> */
    public static function steps(int $count, string $title = 'Step'): array
    {
        $steps = [];
        for ($i = 1; $i <= $count; $i++) {
            $steps[] = ['title' => $title.' '.$i, 'instruction' => 'Do part '.$i.' of the work.', 'owner' => 'agent'];
        }

        return $steps;
    }
}
