<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Defines the action to take when a step fails.
 */
enum FailurePolicy: string
{
    case FailWorkflow = 'fail_workflow';
    case PauseWorkflow = 'pause_workflow';
    case RetryStep = 'retry_step';
    case SkipStep = 'skip_step';
    case ContinueWithPartial = 'continue_with_partial';

    public function shouldFailWorkflow(): bool
    {
        return $this === self::FailWorkflow;
    }

    public function shouldPauseWorkflow(): bool
    {
        return $this === self::PauseWorkflow;
    }

    public function shouldRetryStep(): bool
    {
        return $this === self::RetryStep;
    }

    public function shouldSkipStep(): bool
    {
        return $this === self::SkipStep;
    }

    public function allowsPartialSuccess(): bool
    {
        return $this === self::ContinueWithPartial;
    }
}
