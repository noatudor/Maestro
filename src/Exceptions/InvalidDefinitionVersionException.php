<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

final class InvalidDefinitionVersionException extends ConfigurationException
{
    private const int CODE = 5002;

    public static function empty(): self
    {
        return new self(
            message: 'Definition version cannot be empty',
            code: self::CODE,
        );
    }

    public static function invalidFormat(string $value): self
    {
        return new self(
            message: sprintf("Definition version '%s' has invalid format. Must be in semantic versioning format (e.g., 1.0.0).", $value),
            code: self::CODE,
        );
    }
}
