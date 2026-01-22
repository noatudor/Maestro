<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

final class InvalidBranchKeyException extends MaestroException
{
    public static function empty(): self
    {
        return new self('Branch key cannot be empty');
    }

    public static function invalidFormat(string $value): self
    {
        return new self(
            sprintf("Branch key '%s' has invalid format. Must start with lowercase letter and contain only lowercase letters, numbers, and hyphens.", $value),
        );
    }
}
