<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * The source of a step retry operation.
 *
 * Used to track why a step run was superseded and retried.
 */
enum RetrySource: string
{
    /**
     * Retry triggered after a step failure (normal retry).
     */
    case FailedRetry = 'failed_retry';

    /**
     * Retry triggered from a specific earlier step (retry-from-step).
     */
    case RetryFromStep = 'retry_from_step';

    /**
     * Retry triggered automatically by the auto-retry strategy.
     */
    case AutoRetry = 'auto_retry';

    public function isManual(): bool
    {
        return $this === self::FailedRetry || $this === self::RetryFromStep;
    }

    public function isAutomatic(): bool
    {
        return $this === self::AutoRetry;
    }

    public function isFromEarlierStep(): bool
    {
        return $this === self::RetryFromStep;
    }
}
