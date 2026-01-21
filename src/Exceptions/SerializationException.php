<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

final class SerializationException extends MaestroException
{
    private const int CODE = 6001;

    /**
     * @param class-string $expectedClass
     */
    public static function deserializationFailed(string $expectedClass, string $reason): self
    {
        return new self(
            message: sprintf("Failed to deserialize output '%s': %s", $expectedClass, $reason),
            code: self::CODE,
        );
    }

    /**
     * @param class-string $actualClass
     * @param class-string $expectedClass
     */
    public static function typeMismatch(string $actualClass, string $expectedClass): self
    {
        return new self(
            message: sprintf("Deserialized output type '%s' does not match expected type '%s'", $actualClass, $expectedClass),
            code: self::CODE,
        );
    }

    public static function serializationFailed(string $reason): self
    {
        return new self(
            message: sprintf('Failed to serialize output: %s', $reason),
            code: self::CODE,
        );
    }
}
