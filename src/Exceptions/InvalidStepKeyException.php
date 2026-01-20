<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

final class InvalidStepKeyException extends StepException
{
    private const int CODE = 3001;

    public static function empty(): self
    {
        return new self(
            message: 'Step key cannot be empty',
            code: self::CODE,
        );
    }

    public static function invalidFormat(string $value): self
    {
        return new self(
            message: sprintf("Step key '%s' has invalid format. Must start with lowercase letter and contain only lowercase letters, numbers, and hyphens.", $value),
            code: self::CODE,
        );
    }
}
