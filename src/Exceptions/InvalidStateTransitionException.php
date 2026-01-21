<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Enums\WorkflowState;

final class InvalidStateTransitionException extends WorkflowException
{
    private const int CODE = 2002;

    public static function forWorkflow(WorkflowState $from, WorkflowState $to): self
    {
        return new self(
            message: sprintf("Cannot transition workflow from '%s' to '%s'", $from->value, $to->value),
            code: self::CODE,
        );
    }

    public static function forStep(StepState $from, StepState $to): self
    {
        return new self(
            message: sprintf("Cannot transition step from '%s' to '%s'", $from->value, $to->value),
            code: self::CODE,
        );
    }

    public static function forJob(JobState $from, JobState $to): self
    {
        return new self(
            message: sprintf("Cannot transition job from '%s' to '%s'", $from->value, $to->value),
            code: self::CODE,
        );
    }
}
