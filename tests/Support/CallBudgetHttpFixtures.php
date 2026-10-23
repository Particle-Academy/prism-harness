<?php

declare(strict_types=1);

function budgetHttpReply(int $index, bool $final = false): array
{
    return ['id' => 'msg-'.$index, 'type' => 'message', 'role' => 'assistant', 'model' => 'budget-model',
        'content' => $final ? [['type' => 'text', 'text' => '{"ok":true}']] : [
            ['type' => 'text', 'text' => 'step '.$index],
            ['type' => 'tool_use', 'id' => 'call-'.$index, 'name' => 'tick', 'input' => new stdClass],
        ], 'stop_reason' => $final ? 'end_turn' : 'tool_use', 'stop_sequence' => null,
        'usage' => ['input_tokens' => 3, 'output_tokens' => 2]];
}

function budgetSseReply(int $index, bool $final = false): string
{
    $reply = budgetHttpReply($index, $final);
    $events = [['type' => 'message_start', 'message' => [...$reply, 'content' => [], 'stop_reason' => null,
        'usage' => ['input_tokens' => 3, 'output_tokens' => 0]]]];
    foreach ($reply['content'] as $blockIndex => $block) {
        $events[] = ['type' => 'content_block_start', 'index' => $blockIndex,
            'content_block' => $block['type'] === 'text' ? ['type' => 'text', 'text' => ''] : $block];
        $events[] = ['type' => 'content_block_delta', 'index' => $blockIndex, 'delta' => $block['type'] === 'text'
            ? ['type' => 'text_delta', 'text' => $block['text']]
            : ['type' => 'input_json_delta', 'partial_json' => '{}']];
        $events[] = ['type' => 'content_block_stop', 'index' => $blockIndex];
    }
    $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $reply['stop_reason'], 'stop_sequence' => null],
        'usage' => ['output_tokens' => 2]];
    $events[] = ['type' => 'message_stop'];

    return implode('', array_map(fn (array $event): string => 'event: '.$event['type']."\ndata: ".json_encode($event)."\n\n", $events));
}
