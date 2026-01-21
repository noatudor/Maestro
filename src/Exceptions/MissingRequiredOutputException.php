<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final class MissingRequiredOutputException extends ConfigurationException
{
    private const int CODE = 5004;

    /**
     * @param class-string $outputClass
     */
    public static function forStep(StepKey $stepKey, string $outputClass): self
    {
        return new self(
            message: sprintf("Step '%s' requires output '%s' which was not found", $stepKey->value, $outputClass),
            code: self::CODE,
        );
    }

    /**
     * @param class-string $outputClass
     */
    public static function inWorkflow(WorkflowId $workflowId, string $outputClass): self
    {
        return new self(
            message: sprintf("Workflow '%s' is missing required output: %s", $workflowId->value, $outputClass),
            code: self::CODE,
        );
    }
}
