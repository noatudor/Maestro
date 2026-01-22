<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\Enums\WorkflowState;
use Throwable;

final class ConditionEvaluationException extends MaestroException
{
    public static function forStepCondition(string $conditionClass, Throwable $throwable): self
    {
        return new self(
            sprintf('Failed to evaluate step condition %s: %s', $conditionClass, $throwable->getMessage()),
            0,
            $throwable,
        );
    }

    public static function forBranchCondition(string $conditionClass, Throwable $throwable): self
    {
        return new self(
            sprintf('Failed to evaluate branch condition %s: %s', $conditionClass, $throwable->getMessage()),
            0,
            $throwable,
        );
    }

    public static function forTerminationCondition(string $conditionClass, Throwable $throwable): self
    {
        return new self(
            sprintf('Failed to evaluate termination condition %s: %s', $conditionClass, $throwable->getMessage()),
            0,
            $throwable,
        );
    }

    public static function invalidTerminalState(string $conditionClass, WorkflowState $workflowState): self
    {
        return new self(
            sprintf('Termination condition %s returned invalid terminal state %s. Must be Succeeded or Failed.', $conditionClass, $workflowState->value),
        );
    }

    public static function forResumeCondition(string $conditionClass, Throwable $throwable): self
    {
        return new self(
            sprintf('Failed to evaluate resume condition %s: %s', $conditionClass, $throwable->getMessage()),
            0,
            $throwable,
        );
    }
}
