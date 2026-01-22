<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * The manual resolution decision made for a failed workflow.
 *
 * These are the options available when a workflow is in FAILED state
 * awaiting intervention.
 */
enum ResolutionDecision: string
{
    /**
     * Re-run only the step that failed.
     * Outputs from previous steps are preserved.
     */
    case Retry = 'retry';

    /**
     * Re-run workflow starting from a specific step.
     * Outputs from steps after the retry point are cleared.
     */
    case RetryFromStep = 'retry_from_step';

    /**
     * Execute compensation jobs for completed steps.
     * Workflow transitions to COMPENSATING state.
     */
    case Compensate = 'compensate';

    /**
     * Mark workflow as CANCELLED.
     * No compensation; side effects remain.
     */
    case Cancel = 'cancel';

    /**
     * Mark workflow as terminal FAILED with resolution notes.
     * Use when manual cleanup is done outside the system.
     */
    case MarkResolved = 'mark_resolved';

    public function retriesFailedStep(): bool
    {
        return $this === self::Retry;
    }

    public function retriesFromSpecificStep(): bool
    {
        return $this === self::RetryFromStep;
    }

    public function triggersCompensation(): bool
    {
        return $this === self::Compensate;
    }

    public function cancelsWorkflow(): bool
    {
        return $this === self::Cancel;
    }

    public function marksAsResolved(): bool
    {
        return $this === self::MarkResolved;
    }

    public function continuesExecution(): bool
    {
        return $this === self::Retry || $this === self::RetryFromStep;
    }

    public function isTerminal(): bool
    {
        return $this === self::Cancel || $this === self::MarkResolved;
    }
}
