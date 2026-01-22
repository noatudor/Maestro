<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Defines the scope of compensation when compensation is triggered.
 */
enum CompensationScope: string
{
    /**
     * Compensate all completed steps that have compensation defined.
     */
    case All = 'all';

    /**
     * Compensate only the step that failed (if it has compensation defined).
     */
    case FailedStepOnly = 'failed_step_only';

    /**
     * Compensate from a specific step onwards (used by retry-from-step).
     */
    case FromStep = 'from_step';

    /**
     * Compensate only specific steps (manual selection).
     */
    case Partial = 'partial';

    public function compensatesAll(): bool
    {
        return $this === self::All;
    }

    public function compensatesFailedStepOnly(): bool
    {
        return $this === self::FailedStepOnly;
    }

    public function compensatesFromStep(): bool
    {
        return $this === self::FromStep;
    }

    public function compensatesPartial(): bool
    {
        return $this === self::Partial;
    }

    public function requiresStepKeys(): bool
    {
        return $this === self::FromStep || $this === self::Partial;
    }
}
