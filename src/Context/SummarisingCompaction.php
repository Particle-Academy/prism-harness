<?php

declare(strict_types=1);

namespace Prism\Harness\Context;

use Prism\Harness\Context\Budget\RetryOnce;
use Prism\Harness\Contracts\CompactionStrategy;
use Prism\Harness\Contracts\EvictionSink;
use Prism\Harness\Contracts\SummaryBudget;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * Replace the older half of a conversation with a summary a model writes, and
 * RE-WRITE that summary each time rather than appending to it.
 *
 * ## Read this before choosing it
 *
 * **This is the compaction strategy most likely to lose something that matters,
 * and that is measured rather than cautious.** "Governance Decay"
 * (arXiv 2606.22528) finds summarisation-based compaction producing safety
 * violations above 40%, against 25-30% for token truncation and 15-20% for
 * semantic compression — because constraints stated early are progressively
 * lost and nothing reports it. A model rewrites the content; nothing checks
 * what it dropped.
 *
 * {@see KeepRecentTurns} cannot rewrite anything and costs nothing. Reach for
 * this only when a bounded window genuinely is not enough, and pair it with an
 * {@see EvictionSink} so the detail it drops is still
 * reachable — a summary is a lossy view, and this class only becomes safe when
 * something else holds the original.
 *
 * ## Re-written, not appended
 *
 * The summary is regenerated from the previous summary plus the turns now
 * leaving the window. An append-only précis grows without bound while looking
 * like it satisfies "the summary keeps compacting" — it satisfies the wording
 * and fails the requirement. Mastra's working memory is the reference: it stays
 * small because the agent REWRITES it.
 *
 * ## It costs a model call, and says so
 *
 * Every other strategy in this package is free and deterministic. This one bills
 * on the turns where it fires, which is why it is a separate class you select
 * rather than a flag on an existing one — the cost is visible in the name you
 * bind.
 *
 * ## The word budget is a request, and what to do about that is YOURS
 *
 * `$summaryWords` reaches the model as "in at most N words" inside a prompt, and
 * a model does not have to grant a request. Measured from the Lab with nothing
 * checking: a stated 15 came back at 92 words and at 346, and the default 60
 * came back at 205 — the 346 having re-stated every exchange in the conversation
 * one by one, which is exactly the unbounded growth this class rewrites rather
 * than appends in order to avoid.
 *
 * So there are two dials and they are deliberately separate: **how big**
 * (`$summaryWords`) and **how that is enforced** ({@see SummaryBudget}).
 *
 *  - {@see RetryOnce} — the DEFAULT. Counts, and asks once more when
 *    over. Took a 60-word budget from 205 words to 61; costs a second call on
 *    the turns that overshoot, and is allowed to miss.
 *  - {@see Budget\AskOnly} — enforces nothing, the behaviour before v0.6.0. One
 *    call per turn, and the summary may be four times what you asked for.
 *  - {@see Budget\TruncateTo} — guarantees the bound by cutting. Read its
 *    warning: it is the one that can hand the model a fragment reading as a
 *    whole thought.
 *
 * ## What it does NOT touch
 *
 * The most recent `keep` messages are passed through verbatim. Summarising the
 * turn the model is about to answer would replace the question with a
 * paraphrase of the question.
 */
final class SummarisingCompaction implements CompactionStrategy
{
    /**
     * Marks the message this strategy wrote, so the next compaction can find
     * its own previous summary and rewrite it instead of summarising it again.
     *
     * A prefix rather than a metadata flag, because the summary travels to the
     * provider as an ordinary user message and comes back through storage the
     * same way. Anything hung beside it would have to survive that round trip.
     */
    public const MARKER = '[conversation-so-far]';

    private readonly SummaryBudget $budget;

    public function __construct(
        private readonly string $model = 'claude-haiku-4-5-20251001',
        private readonly int $keep = 20,
        private readonly int $summaryWords = 200,
        private readonly Provider $provider = Provider::Anthropic,
        ?SummaryBudget $budget = null,
    ) {
        if ($keep < 1) {
            throw new \InvalidArgumentException('SummarisingCompaction needs to keep at least one message.');
        }

        // Defaulted rather than required, so an existing call site keeps
        // working and gets the behaviour most likely to help without being able
        // to do harm. Swapping it is a decision, not a chore.
        $this->budget = $budget ?? new RetryOnce;
    }

    #[\Override]
    public function compact(array $messages): CompactionOutcome
    {
        if (count($messages) <= $this->keep) {
            return CompactionOutcome::untouched($messages);
        }

        $cut = count($messages) - $this->keep;
        $older = array_slice($messages, 0, $cut);
        $recent = array_slice($messages, $cut);

        $previous = $this->previousSummaryIn($older);
        $summary = $this->write($older, $previous);

        if ($summary === null) {
            // A FAILED SUMMARY MUST NOT SILENTLY DROP THE CONVERSATION.
            //
            // If the model call fails, returning the recent slice alone would
            // evict the older turns and replace them with nothing — the agent
            // would lose the history and have no summary standing in for it,
            // which is worse than not compacting at all. Falling back to the
            // whole conversation costs tokens on that turn and loses nothing.
            return CompactionOutcome::untouched($messages);
        }

        return new CompactionOutcome(
            kept: [new UserMessage(self::MARKER.' '.$summary), ...$recent],
            // The turns the summary now stands for. They go to the sink, which
            // is what makes this recoverable rather than merely smaller.
            evicted: $older,
        );
    }

    /**
     * The summary this strategy wrote last time, if it is still in the slice
     * being compacted.
     *
     * @param  list<Message>  $older
     */
    private function previousSummaryIn(array $older): ?string
    {
        foreach (array_reverse($older) as $message) {
            if ($message instanceof UserMessage && str_starts_with($message->content, self::MARKER)) {
                return trim(substr($message->content, strlen(self::MARKER)));
            }
        }

        return null;
    }

    /**
     * @param  list<Message>  $older
     */
    private function write(array $older, ?string $previous): ?string
    {
        $transcript = $this->transcribe($older);

        if (trim($transcript) === '') {
            return null;
        }

        $instruction = $previous === null
            ? "Summarise this conversation so far in at most {$this->summaryWords} words."
            : "Here is the summary so far:\n\n{$previous}\n\n"
                .'REWRITE it to also cover the newer exchange below, still in at most '
                ."{$this->summaryWords} words. Do not append — produce one summary that "
                .'replaces the old one.';

        $summary = $this->ask($instruction."\n\n".$transcript);

        if ($summary === null) {
            return null;
        }

        // THE BUDGET IS ENFORCED BY SOMETHING YOU CHOOSE, NOT BY THIS CLASS.
        //
        // `$summaryWords` reaches the model as "in at most N words" inside the
        // instruction above, and a model does not have to grant a request. A
        // live Lab probe measured it with nothing checking: a stated 15 came
        // back at 92 and at 346, and the default 60 came back at 205 -- the 346
        // having re-stated every exchange one by one, which is the unbounded
        // growth this class rewrites rather than appends in order to avoid.
        //
        // What to DO about that is not the harness's call. It depends on what a
        // turn may cost and what the conversation is worth, and only the
        // application knows both -- so it is a contract with three shipped
        // answers, the same shape as the compaction strategy itself.
        $bounded = $this->budget->apply(
            $summary,
            $this->summaryWords,
            fn (string $prompt): ?string => $this->ask($prompt),
        );

        // THE CONTRACT SAYS "NEVER EMPTY", AND THIS DOES NOT TRUST IT.
        //
        // A budget is application code. One that returns '' — a truncation to a
        // zero limit, a bug, a well-meant "I could not fix it" — would otherwise
        // evict the older turns and put an empty marker message in their place,
        // which is the single worst outcome this class has: history gone, and
        // nothing standing in for it.
        //
        // Treated exactly like a failed model call, so a third-party budget
        // cannot do more damage than the provider being down.
        return trim($bounded) === '' ? null : $bounded;
    }

    /**
     * One summarising call. Null when it failed or came back empty.
     *
     * Handed to the {@see SummaryBudget} as a callable so an implementation can
     * ask again without knowing anything about providers, models or how this
     * class talks to them.
     */
    private function ask(string $prompt): ?string
    {
        try {
            $response = Prism::text()
                ->using($this->provider, $this->model)
                ->withSystemPrompt(
                    'You compress a conversation so an assistant can continue it. Keep '
                    .'names, numbers, identifiers, decisions and anything the user asked '
                    .'to be remembered — those are what a later turn will need and what a '
                    .'summary most often loses. Drop pleasantries and restatement. Write '
                    .'plain prose in the third person, no preamble, no headings.'
                )
                ->withPrompt($prompt)
                ->asText();
        } catch (Throwable $failure) {
            // THE TRANSCRIPT IS IN THIS FRAME. Under
            // `zend.exception_ignore_args=0` — off in a PHP with no ini file,
            // though `php.ini-production` turns it on — the trace records
            // `$prompt`, which is the conversation. Reporting a tidier
            // exception instead does not help: it would be constructed in this
            // same frame and inherit the same arguments, which was measured on
            // the voice path and holds identically here. The controls are the
            // ini setting and the reporter's own scrubbing.
            //
            // Reported rather than thrown because a summariser that cannot
            // reach its provider must not take the conversation down with it —
            // the caller gets no summary and the window falls back to its
            // bounded behaviour.
            report($failure);

            return null;
        }

        $summary = trim($response->text);

        return $summary === '' ? null : $summary;
    }

    /**
     * The turns being summarised, as plain text.
     *
     * Tool results are included and labelled. On an agentic transcript they are
     * the majority of what happened — one consumer measured 93% — so a summary
     * built from prose alone would be summarising the smaller half of the
     * conversation while appearing to cover all of it.
     *
     * @param  list<Message>  $messages
     */
    private function transcribe(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            if ($message instanceof UserMessage) {
                $lines[] = 'User: '.$message->content;

                continue;
            }

            if ($message instanceof AssistantMessage) {
                if (trim($message->content) !== '') {
                    $lines[] = 'Assistant: '.$message->content;
                }

                foreach ($message->toolCalls as $call) {
                    $lines[] = 'Assistant called tool: '.$call->name;
                }

                continue;
            }

            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults as $result) {
                    $payload = is_array($result->result)
                        ? json_encode($result->result)
                        : (string) $result->result;

                    $lines[] = 'Tool '.$result->toolName.' returned: '.$payload;
                }
            }
        }

        return implode("\n", $lines);
    }
}
