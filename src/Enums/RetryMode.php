<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * The mode for retry-from-step operations.
 *
 * Determines whether compensation should run before retrying.
 */
enum RetryMode: string
{
    /**
     * Clear outputs and re-run steps without compensation.
     * Use when steps have no external side effects.
     */
    case RetryOnly = 'retry_only';

    /**
     * Compensate affected steps first, then re-run.
     * Use when steps have side effects that must be undone.
     */
    case CompensateThenRetry = 'compensate_then_retry';

    public function requiresCompensation(): bool
    {
        return $this === self::CompensateThenRetry;
    }

    public function skipsCompensation(): bool
    {
        return $this === self::RetryOnly;
    }
}
