<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\WorkflowId;

final class WorkflowNotFailedException extends WorkflowException
{
    private const int CODE = 2005;

    public static function withId(WorkflowId $workflowId): self
    {
        return new self(
            message: sprintf("Workflow '%s' is not in failed state and cannot be resolved", $workflowId->value),
            code: self::CODE,
        );
    }
}
