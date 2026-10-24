<?php

declare(strict_types=1);

namespace Prism\Harness\Subagents;

use Illuminate\Contracts\Support\Arrayable;
use Prism\Harness\Exceptions\RunNotPermitted;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Text;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;

/** Trusted, caller-supplied prices. No remote discovery and no guessed model rates. */
final readonly class CallBudget
{
    public const MESSAGE_OVERHEAD = 64;

    /** @param array<string, mixed> $price */
    private function __construct(private array $price, public int $maxOutputTokens) {}

    /** @param array<string, mixed> $configured */
    public static function forRun(RunContext $run, string $provider, string $model, array $configured): self
    {
        $price = $run->pricing[$provider][$model] ?? $configured[$provider][$model] ?? null;
        if (! is_array($price)) {
            throw RunNotPermitted::callBudget('budget_unpriced');
        }
        self::validateRates($price);
        $multiplier = $price['multiplier'] ?? 1;
        if ((! is_int($multiplier) && ! is_float($multiplier)) || ! is_finite((float) $multiplier) || $multiplier <= 0) {
            throw RunNotPermitted::callBudget('budget_unpriced');
        }
        $tiers = $price['tiers'] ?? [];
        if (! is_array($tiers) || ! array_is_list($tiers)) {
            throw RunNotPermitted::callBudget('budget_unpriced');
        }
        $previous = -1;
        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! is_int($tier['aboveInputTokens'] ?? null) || $tier['aboveInputTokens'] <= $previous) {
                throw RunNotPermitted::callBudget('budget_unpriced');
            }
            self::validateRates($tier);
            $previous = $tier['aboveInputTokens'];
        }
        $maxOutput = $run->budget->maxOutputTokensPerCall;
        if ($maxOutput === null || $maxOutput < 1) {
            throw RunNotPermitted::callBudget('budget_unbounded_output');
        }

        return new self($price, $maxOutput);
    }

    public function worstCase(TextRequest|StructuredRequest $request): float
    {
        $input = $this->inputTokenBound($request);
        $rates = $this->ratesAt($input, conservative: true);
        $inputPrice = max($rates['inputPerMTok'], $rates['cacheWritePerMTok'] ?? 0, $rates['cacheReadPerMTok'] ?? 0);

        return ($input * $inputPrice + $this->maxOutputTokens * $rates['outputPerMTok'])
            * ($this->price['multiplier'] ?? 1) / 1_000_000;
    }

    /**
     * A deliberately conservative UTF-8 BYTE bound, not a tokenizer estimate.
     * JSON escaping, keys and duplicate text over-count; admission errs early.
     * Count system/history text, call arguments/results, schemas and provider
     * options, plus 64 tokens per message/tool for protocol framing. Images use
     * an explicit model bound; no bytes-to-tokens guess for other media.
     */
    public function inputTokenBound(TextRequest|StructuredRequest $request): int
    {
        $total = self::MESSAGE_OVERHEAD;
        foreach ([...$request->systemPrompts(), ...$request->messages()] as $message) {
            if (! $message instanceof Arrayable) {
                throw RunNotPermitted::callBudget('budget_input_unbounded');
            }
            $data = $message->toArray();
            if ($message instanceof UserMessage) {
                $parts = [];
                foreach ($message->additionalContent as $part) {
                    if ($part instanceof Text) {
                        $parts[] = $part->toArray();
                    } elseif ($part instanceof Image) {
                        $total += $this->imageBound();
                    } else {
                        throw RunNotPermitted::callBudget('budget_input_unbounded');
                    }
                }
                $data['additional_content'] = $parts;
            }
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $index => $result) {
                    foreach ($result->artifacts as $artifact) {
                        if (! str_starts_with($artifact->mimeType, 'image/')) {
                            throw RunNotPermitted::callBudget('budget_input_unbounded');
                        }
                        $total += $this->imageBound();
                    }
                    $data['tool_results'][$index]['artifacts'] = array_map(fn ($artifact): array => [
                        'id' => $artifact->id, 'mime_type' => $artifact->mimeType, 'metadata' => $artifact->metadata,
                    ], $result->artifacts);
                }
            }
            $total += self::MESSAGE_OVERHEAD + $this->bytes($data);
        }
        foreach ($request->tools() as $tool) {
            $total += self::MESSAGE_OVERHEAD + $this->bytes([
                'name' => $tool->name(), 'description' => $tool->description(),
                'schema' => ['type' => 'object', 'properties' => $tool->parametersAsObject(), 'required' => $tool->requiredParameters()],
            ]);
        }
        if ($request->tools() !== []) {
            $overhead = $this->price['toolOverheadTokens'] ?? null;
            if (! is_int($overhead) || $overhead < 0) {
                throw RunNotPermitted::callBudget('budget_input_unbounded');
            }
            $total += $overhead;
        }
        foreach ($request->providerTools() as $tool) {
            $overhead = $this->price['serverToolOverheadTokens'][$tool->type] ?? null;
            if (! is_int($overhead) || $overhead < 0) {
                throw RunNotPermitted::callBudget('budget_input_unbounded');
            }
            $total += $overhead + $this->bytes([$tool->type, $tool->name, $tool->options]);
        }
        // Hosted MCP responses introduce input the caller cannot bound here.
        if ($request->providerOptions('mcp_servers') !== null && $request->providerOptions('mcp_servers') !== []) {
            throw RunNotPermitted::callBudget('budget_input_unbounded');
        }
        $total += $this->bytes($request->providerOptions());
        if ($request instanceof StructuredRequest) {
            $total += $this->bytes($request->schema()->toArray());
        }

        return $total;
    }

    public function costOf(?Usage $usage): ?float
    {
        if ($usage === null) {
            return null;
        }
        if ($usage->cost !== null) {
            return is_finite($usage->cost) && $usage->cost >= 0 ? $usage->cost : null;
        }
        if (min($usage->promptTokens, $usage->completionTokens, $usage->cacheReadInputTokens ?? 0, $usage->cacheWriteInputTokens ?? 0) < 0) {
            return null;
        }
        // A real call always reads input. No input tokens and no reported cost
        // is a placeholder (core's stream fallback is `new Usage(0, 0)`), not a
        // free call, so it is unknown and charged the reserved maximum.
        if ($usage->promptTokens + ($usage->cacheWriteInputTokens ?? 0) + ($usage->cacheReadInputTokens ?? 0) < 1) {
            return null;
        }
        // Cache counters are separate from ordinary prompt tokens in Prism's
        // usage contract. Thinking is already part of completionTokens.
        $rates = $this->ratesAt($usage->promptTokens + ($usage->cacheWriteInputTokens ?? 0) + ($usage->cacheReadInputTokens ?? 0));
        $cost = ($usage->promptTokens * $rates['inputPerMTok']
            + $usage->completionTokens * $rates['outputPerMTok']
            + ($usage->cacheWriteInputTokens ?? 0) * ($rates['cacheWritePerMTok'] ?? $rates['inputPerMTok'])
            + ($usage->cacheReadInputTokens ?? 0) * ($rates['cacheReadPerMTok'] ?? $rates['inputPerMTok']))
            * ($this->price['multiplier'] ?? 1) / 1_000_000;

        return is_finite($cost) ? $cost : null;
    }

    private function imageBound(): int
    {
        $bound = $this->price['imageTokenBound'] ?? null;
        if (! is_int($bound) || $bound < 1) {
            throw RunNotPermitted::callBudget('budget_input_unbounded');
        }

        return $bound;
    }

    /** @param array<string, mixed> $rates */
    private static function validateRates(array $rates): void
    {
        foreach (['inputPerMTok', 'outputPerMTok', 'cacheWritePerMTok', 'cacheReadPerMTok'] as $key) {
            $value = $rates[$key] ?? null;
            if ($value === null && in_array($key, ['cacheWritePerMTok', 'cacheReadPerMTok'], true)) {
                continue;
            }
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0) {
                throw RunNotPermitted::callBudget('budget_unpriced');
            }
        }
    }

    /** @return array<string, float> */
    private function ratesAt(int $input, bool $conservative = false): array
    {
        $rates = $this->rateValues($this->price);
        foreach ($this->price['tiers'] ?? [] as $tier) {
            if ($input <= $tier['aboveInputTokens']) {
                break;
            }
            $next = $this->rateValues($tier);
            // Even a misconfigured descending tier must not make an upper
            // bound cheaper than a shorter actual prompt could have been.
            foreach ($next as $key => $value) {
                $rates[$key] = $conservative ? max($rates[$key], $value) : $value;
            }
        }

        return $rates;
    }

    /** @param array<string, mixed> $rates
     * @return array<string, float>
     */
    private function rateValues(array $rates): array
    {
        return [
            'inputPerMTok' => (float) $rates['inputPerMTok'],
            'outputPerMTok' => (float) $rates['outputPerMTok'],
            'cacheWritePerMTok' => (float) ($rates['cacheWritePerMTok'] ?? $rates['inputPerMTok']),
            'cacheReadPerMTok' => (float) ($rates['cacheReadPerMTok'] ?? $rates['inputPerMTok']),
        ];
    }

    private function bytes(mixed $value): int
    {
        try {
            return strlen(json_encode($value, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw RunNotPermitted::callBudget('budget_input_unbounded');
        }
    }
}
