<?php

declare(strict_types=1);

namespace Prism\Harness\Exceptions;

use RuntimeException;

/**
 * Thrown when a message or content part cannot be stored or rebuilt faithfully.
 *
 * Failing here is deliberate. The alternative — writing the row anyway, minus
 * whatever could not be mapped — produces a thread that loads without error
 * and replays an incomplete conversation to the model. That failure is
 * invisible at the point it happens and surfaces much later as a model that
 * has inexplicably forgotten something.
 */
final class UnmappableContent extends RuntimeException
{
    public static function unknownMessageType(string $type): self
    {
        return new self(
            "Cannot map the message type [{$type}]. The harness stores the four Prism message types "
            .'(system, user, assistant, tool_result); a custom Message implementation needs its own mapping.'
        );
    }

    public static function unknownPartClass(string $class): self
    {
        return new self(
            "Cannot rebuild the stored content part [{$class}]. It must be Prism's Text or a subclass of Media."
        );
    }

    public static function unstorableObject(string $class): self
    {
        return new self(
            "Cannot store a [{$class}] inside a message's additional content. The harness rebuilds "
            ."Prism's own value objects; an object from anywhere else cannot be reconstructed, and "
            .'storing it as a plain array would come back as one and fail inside a provider mapper '
            .'on a later call rather than here.'
        );
    }

    public static function unknownStoredClass(string $class): self
    {
        return new self(
            "Cannot rebuild the stored value [{$class}]. Only classes under "
            .'Prism\Prism\ValueObjects are eligible, and this is not one of them.'
        );
    }

    public static function missingStoredProperty(string $class, string $property): self
    {
        return new self(
            "Cannot rebuild a [{$class}]: the stored record has no value for the required property "
            ."[{$property}]. The value object's shape has changed since this row was written."
        );
    }

    public static function noMediaLocator(string $class): self
    {
        return new self(
            "Cannot rebuild a [{$class}] content part: the stored record has no file id, URL, storage path, "
            .'local path or base64 payload, so there is nothing to resolve it from.'
        );
    }
}
