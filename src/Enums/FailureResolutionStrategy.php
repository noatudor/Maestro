<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Defines how workflow failures are handled at the workflow definition level.
 *
 * This determines the default behavior when a step fails after exhausting
 * its configured retry attempts.
 */
enum FailureResolutionStrategy: string
{
    /**
     * Default: Workflow transitions to FAILED state and awaits manual intervention.
     * Operator must choose: retry, compensate, cancel, or mark resolved.
     */
    case AwaitDecision = 'await_decision';

    /**
     * Automatically retry the failed step according to auto-retry configuration.
     * After max retries exhausted, falls back to AwaitDecision.
     */
    case AutoRetry = 'auto_retry';

    /**
     * Immediately trigger compensation for completed steps on failure.
     * Requires compensation jobs to be defined on steps.
     */
    case AutoCompensate = 'auto_compensate';

    public function awaitsDecision(): bool
    {
        return $this === self::AwaitDecision;
    }

    public function autoRetries(): bool
    {
        return $this === self::AutoRetry;
    }

    public function autoCompensates(): bool
    {
        return $this === self::AutoCompensate;
    }

    public function requiresManualIntervention(): bool
    {
        return $this === self::AwaitDecision;
    }
}
