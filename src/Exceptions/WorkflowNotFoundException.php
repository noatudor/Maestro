<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\WorkflowId;

final class WorkflowNotFoundException extends WorkflowException
{
    private const int CODE = 2001;

    public static function withId(WorkflowId $workflowId): self
    {
        return new self(
            message: 'Workflow not found: '.$workflowId->value,
            code: self::CODE,
        );
    }
}
