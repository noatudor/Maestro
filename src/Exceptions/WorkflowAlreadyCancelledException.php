<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\WorkflowId;

final class WorkflowAlreadyCancelledException extends WorkflowException
{
    private const int CODE = 2004;

    public static function withId(WorkflowId $workflowId): self
    {
        return new self(
            message: sprintf("Workflow '%s' has already been cancelled", $workflowId->value),
            code: self::CODE,
        );
    }
}
