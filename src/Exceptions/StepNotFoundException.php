<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class StepNotFoundException extends StepException
{
    private const int CODE = 3002;

    public static function withId(StepRunId $stepRunId): self
    {
        return new self(
            message: sprintf('Step run not found: %s', $stepRunId->value),
            code: self::CODE,
        );
    }

    public static function withKeyInWorkflow(StepKey $stepKey, WorkflowId $workflowId): self
    {
        return new self(
            message: sprintf("Step '%s' not found in workflow '%s'", $stepKey->value, $workflowId->value),
            code: self::CODE,
        );
    }
}
