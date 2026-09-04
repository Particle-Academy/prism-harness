<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use Prism\Harness\Contracts\AgentTask;
use RuntimeException;

/**
 * Thrown when a record cannot be read as an {@see AgentTask}.
 *
 * The alternative — filling in a default for whatever could not be read — is
 * the failure {@see UnmappableContent} describes in the thread layer, and it is
 * worse here. A model whose `state` column holds something this package does
 * not recognise would silently read as `todo` and be handed to a worker that
 * runs it again; a missing `instruction` would be handed to the model as an
 * empty string, and an empty instruction is answered rather than rejected.
 *
 * So it fails at the point the record is read, naming the column and the
 * override that would fix it.
 */
final class UnmappableTask extends RuntimeException
{
    public static function missingColumn(string $model, string $column, string $override): self
    {
        return new self(
            "The task model [{$model}] has no usable value in [{$column}]. The trait reads conventional "
            ."column names; if yours differ, override {$override}() on the model rather than renaming the "
            .'column — the package ships no schema and no migration, so the columns are yours.'
        );
    }

    public static function unknownState(string $model, string $column, string $value): self
    {
        return new self(
            "The task model [{$model}] holds [{$value}] in [{$column}], which is not one of the four task "
            .'states (todo, claimed, done, failed). There are four and there are no others: a fifth state '
            .'read as one of the four would run finished work again or strand unfinished work forever, '
            .'depending which one it silently became.'
        );
    }

    public static function corruptRecord(string $source, string $detail): self
    {
        return new self(
            "The stored task list [{$source}] cannot be read: {$detail}. The list is not defaulted around a "
            .'row it does not understand — a state this package does not recognise, read as [todo], hands '
            .'finished work back to a worker and reports nothing.'
        );
    }

    public static function unreadableTimestamp(string $model, string $column): self
    {
        return new self(
            "The task model [{$model}] holds a value in [{$column}] that is neither null, an integer Unix "
            .'timestamp, nor a date. A lease expiry that cannot be read cannot be enforced, and a lease '
            .'that is not enforced is what lets one task be handed to two workers.'
        );
    }
}
