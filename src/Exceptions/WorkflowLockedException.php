<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\WorkflowId;

final class WorkflowLockedException extends WorkflowException
{
    private const int CODE = 2003;

    public static function withId(WorkflowId $workflowId, string $lockedBy): self
    {
        return new self(
            message: sprintf("Workflow '%s' is locked by another process: %s", $workflowId->value, $lockedBy),
            code: self::CODE,
        );
    }

    public static function lockTimeout(WorkflowId $workflowId): self
    {
        return new self(
            message: sprintf("Timed out waiting to acquire lock on workflow '%s'", $workflowId->value),
            code: self::CODE,
        );
    }
}
