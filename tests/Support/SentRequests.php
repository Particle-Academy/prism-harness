<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Providers\Anthropic\Maps\MessageMap as AnthropicMap;
use Prism\Prism\Providers\OpenAI\Maps\ChatCompletionsMessageMap;
use Prism\Prism\Providers\OpenAI\Maps\MessageMap as ResponsesMap;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

/**
 * What is wrong with the provider requests a test actually SENT (G-387
 * round 17): read from the faked HTTP, never from the stored rows, because a
 * replay that answers every call somewhere in a segment can still send a
 * request no provider accepts.
 *
 * The rule every provider shares: a run of tool calls is followed AT ONCE by
 * exactly one output per call (a multiset), before any other item, and
 * every call and output carries a provider-valid id. Shapes read: OpenAI
 * Responses (`input` items), OpenAI Chat Completions (`messages`, `role:
 * tool`) and Anthropic Messages (`tool_use` / `tool_result` blocks).
 */
final class SentRequests
{
    /**
     * @param  array<array-key, mixed>  $request  a decoded request body
     * @param  bool  $ids  whether ids are checked too (false: only the pairing, for ids a test generates empty)
     * @return list<string> empty when the request is valid
     */
    public static function problems(array $request, bool $ids = true): array
    {
        $problems = self::shapes($request);

        return $ids ? $problems : array_values(array_filter($problems, fn (string $problem): bool => ! str_contains($problem, ' id')));
    }

    /**
     * @param  array<array-key, mixed>  $request
     * @return list<string>
     */
    private static function shapes(array $request): array
    {
        if (is_array($request['input'] ?? null)) {
            return self::responses(array_values($request['input']));
        }
        if (is_array($request['messages'] ?? null)) {
            $messages = array_values($request['messages']);
            foreach ($messages as $message) {
                foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                    if (in_array($block['type'] ?? null, ['tool_use', 'tool_result'], true)) {
                        return self::anthropic($messages);
                    }
                }
            }

            return self::chat($messages);
        }

        return [];
    }

    /** @return list<string> the problems of every request recorded from the `$from`-th (0-based) on, each with its number */
    public static function since(int $from = 0, bool $ids = true): array
    {
        $problems = [];
        foreach (Http::recorded() as $k => [$request]) {
            if ($k >= $from) {
                foreach (self::problems((array) json_decode((string) $request->body(), true), $ids) as $problem) {
                    $problems[] = "request {$k}: {$problem}";
                }
            }
        }

        return $problems;
    }

    /**
     * A replay as each provider map would send it: OpenAI Responses, OpenAI
     * Chat Completions and Anthropic Messages (which takes system messages
     * only as its system prompt, so they are left out of its array).
     *
     * @param  iterable<Message>  $messages
     * @return array<string, array<string, mixed>>
     */
    public static function mapped(iterable $messages): array
    {
        $messages = is_array($messages) ? array_values($messages) : iterator_to_array($messages, false);
        $anthropic = array_values(array_filter($messages, fn (Message $message): bool => ! $message instanceof SystemMessage));

        return [
            'openai responses' => ['input' => (new ResponsesMap($messages, []))()],
            'openai chat' => ['messages' => (new ChatCompletionsMessageMap($messages, []))()],
            'anthropic' => ['messages' => AnthropicMap::map($anthropic)],
        ];
    }

    /**
     * @param  iterable<Message>  $messages
     * @return list<string> the problems of a replay under every provider map, each with the map's name
     */
    public static function ofReplay(iterable $messages): array
    {
        $problems = [];
        foreach (self::mapped($messages) as $map => $request) {
            foreach (self::problems($request) as $problem) {
                $problems[] = "{$map}: {$problem}";
            }
        }

        return $problems;
    }

    /** @return list<int> the numbers of the requests recorded from the `$from`-th on that have a problem */
    public static function invalid(int $from = 0, bool $ids = true): array
    {
        $out = [];
        foreach (Http::recorded() as $k => [$request]) {
            if ($k >= $from && self::problems((array) json_decode((string) $request->body(), true), $ids) !== []) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /** A one-line outline of the n-th request sent (0-based): each item's kind and id, for a failure report. */
    public static function outline(int $index): string
    {
        $recorded = Http::recorded();
        if (! isset($recorded[$index])) {
            return '';
        }
        $request = (array) json_decode((string) $recorded[$index][0]->body(), true);
        $out = [];
        foreach ($request['input'] ?? $request['messages'] ?? [] as $item) {
            $out[] = ($item['type'] ?? $item['role'] ?? '?').(isset($item['call_id']) ? ':'.$item['call_id'] : '')
                .(($item['type'] ?? null) === 'message' ? ':'.($item['role'] ?? '') : '');
        }

        return implode(' ', $out);
    }

    public static function count(): int
    {
        return count(Http::recorded());
    }

    /**
     * @param  list<array<array-key, mixed>>  $items
     * @return list<string>
     */
    private static function responses(array $items): array
    {
        $problems = [];
        $n = count($items);
        $i = 0;
        while ($i < $n) {
            $type = $items[$i]['type'] ?? 'message';
            if ($type === 'function_call_output') {
                $problems[] = 'an output with no call run before it at '.$i.' ('.json_encode($items[$i]['call_id'] ?? null).')';
                $i++;

                continue;
            }
            if ($type !== 'function_call') {
                $i++;

                continue;
            }
            $calls = [];
            while ($i < $n && ($items[$i]['type'] ?? null) === 'function_call') {
                if (! self::id($items[$i]['call_id'] ?? null)) {
                    $problems[] = 'a call with no provider call id at '.$i;
                }
                if (! self::id($items[$i]['id'] ?? null)) {
                    $problems[] = 'a call item with no id at '.$i;
                }
                $calls[] = self::key($items[$i]['call_id'] ?? null);
                $i++;
            }
            $outputs = [];
            while ($i < $n && ($items[$i]['type'] ?? null) === 'function_call_output') {
                $outputs[] = self::key($items[$i]['call_id'] ?? null);
                $i++;
            }
            $problems = [...$problems, ...self::compare($calls, $outputs, $i)];
        }

        return $problems;
    }

    /**
     * @param  list<array<array-key, mixed>>  $messages
     * @return list<string>
     */
    private static function chat(array $messages): array
    {
        $problems = [];
        $n = count($messages);
        $i = 0;
        while ($i < $n) {
            $message = $messages[$i];
            $i++;
            if (($message['role'] ?? null) === 'tool') {
                $problems[] = 'a tool message with no call before it at '.($i - 1);

                continue;
            }
            if (($message['role'] ?? null) !== 'assistant' || ! is_array($message['tool_calls'] ?? null) || $message['tool_calls'] === []) {
                continue;
            }
            $calls = [];
            foreach ($message['tool_calls'] as $call) {
                if (! self::id($call['id'] ?? null)) {
                    $problems[] = 'a call with no id at '.($i - 1);
                }
                $calls[] = self::key($call['id'] ?? null);
            }
            $outputs = [];
            while ($i < $n && ($messages[$i]['role'] ?? null) === 'tool') {
                $outputs[] = self::key($messages[$i]['tool_call_id'] ?? null);
                $i++;
            }
            $problems = [...$problems, ...self::compare($calls, $outputs, $i)];
        }

        return $problems;
    }

    /**
     * @param  list<array<array-key, mixed>>  $messages
     * @return list<string>
     */
    private static function anthropic(array $messages): array
    {
        $problems = [];
        foreach ($messages as $i => $message) {
            $blocks = is_array($message['content'] ?? null) ? $message['content'] : [];
            $uses = [];
            foreach ($blocks as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    if (! is_string($block['id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]+$/', $block['id']) !== 1) {
                        $problems[] = 'a tool_use with an id no provider accepts at '.$i.' ('.json_encode($block['id'] ?? null).')';
                    }
                    $uses[] = self::key($block['id'] ?? null);
                }
            }
            if ($uses !== []) {
                $results = [];
                foreach (is_array($messages[$i + 1]['content'] ?? null) ? $messages[$i + 1]['content'] : [] as $block) {
                    if (($block['type'] ?? null) === 'tool_result') {
                        $results[] = self::key($block['tool_use_id'] ?? null);
                    }
                }
                $problems = [...$problems, ...self::compare($uses, $results, $i + 1)];
            }
            $previous = [];
            foreach ($i > 0 && is_array($messages[$i - 1]['content'] ?? null) ? $messages[$i - 1]['content'] : [] as $block) {
                if (($block['type'] ?? null) === 'tool_use') {
                    $previous[] = $block['id'] ?? null;
                }
            }
            foreach ($blocks as $block) {
                if (($block['type'] ?? null) === 'tool_result' && ! in_array($block['tool_use_id'] ?? null, $previous, true)) {
                    $problems[] = 'a tool_result not answering the message before it at '.$i;
                }
            }
        }

        return $problems;
    }

    /** An id as compared: a missing and an empty id are the same (no id; the id check reports it). */
    private static function key(mixed $id): string
    {
        return $id === null || $id === '' ? '-' : (string) json_encode($id);
    }

    private static function id(mixed $id): bool
    {
        return is_string($id) && $id !== '';
    }

    /**
     * @param  list<string>  $calls
     * @param  list<string>  $outputs
     * @return list<string>
     */
    private static function compare(array $calls, array $outputs, int $at): array
    {
        sort($calls);
        sort($outputs);

        return $calls === $outputs ? [] : ['calls ['.implode(',', $calls).'] answered by ['.implode(',', $outputs).'] before item '.$at];
    }
}
