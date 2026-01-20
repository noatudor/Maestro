<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\Enums\WorkflowState;

final class InvalidStateTransitionException extends WorkflowException
{
    private const int CODE = 2002;

    public static function forWorkflow(WorkflowState $from, WorkflowState $to): self
    {
        return new self(
            message: "Cannot transition workflow from '{$from->value}' to '{$to->value}'",
            code: self::CODE,
        );
    }
}
