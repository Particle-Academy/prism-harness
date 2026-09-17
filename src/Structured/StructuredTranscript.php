<?php

declare(strict_types=1);

namespace Prism\Harness\Structured;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Structured\Response;
use Prism\Prism\Structured\Step;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * What a structured turn adds to the thread.
 *
 * A text run hands back `$response->messages` and the harness records the ones
 * it did not send. A structured run has no such collection — Prism's structured
 * response carries steps, and each step carries the messages that were SENT to
 * produce it — so the turn is rebuilt here from what the steps did.
 *
 * Rebuilt rather than skipped, because the alternative is a thread that holds
 * the answer and forgets the four tool calls behind it. A later turn reading
 * that conversation sees an agent that knew something for no reason, and a
 * person reading it cannot audit the work.
 *
 * THE DOCUMENT IS STORED AS TEXT, with the parsed object beside it under
 * `structured`. The text is the record and the object is a view of it: every
 * reader that handles a thread today keeps working, and a reader that wants the
 * document parsed has it without re-parsing. No provider message map reads that
 * key, so it stays in storage rather than going back out to a model.
 */
final class StructuredTranscript
{
    /**
     * @return list<Message>
     */
    public static function of(Response $response, ?UserMessage $prompt): array
    {
        $messages = [];

        if ($prompt instanceof UserMessage) {
            $messages[] = $prompt;
        }

        // Every step but the last is a tool round: what the model asked for,
        // and what it got back. The last step is the answer, appended below
        // with the document on it.
        foreach ($response->steps->slice(0, -1) as $step) {
            $messages[] = new AssistantMessage($step->text, $step->toolCalls, $step->additionalContent);

            if ($step->toolResults !== []) {
                $messages[] = new ToolResultMessage($step->toolResults);
            }
        }

        $last = $response->steps->last();

        // A last step that still holds tool results is a run that ended on its
        // step ceiling rather than on an answer. Its results belong in the
        // thread too, before the text the model managed to produce.
        if ($last instanceof Step && $last->toolResults !== []) {
            $messages[] = new AssistantMessage('', $last->toolCalls, $last->additionalContent);
            $messages[] = new ToolResultMessage($last->toolResults);
        }

        $messages[] = new AssistantMessage(
            $response->text,
            [],
            $response->structured === null
                ? $response->additionalContent
                : [...$response->additionalContent, 'structured' => $response->structured],
        );

        return $messages;
    }
}
