<?php

declare(strict_types=1);

namespace Maestro\Workflow\Enums;

/**
 * Defines which jobs to retry when a step is retried.
 */
enum RetryScope: string
{
    case All = 'all';
    case FailedOnly = 'failed_only';

    public function retriesAllJobs(): bool
    {
        return $this === self::All;
    }

    public function retriesFailedJobsOnly(): bool
    {
        return $this === self::FailedOnly;
    }
}
