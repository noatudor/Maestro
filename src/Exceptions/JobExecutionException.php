<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\JobId;
use Throwable;

final class JobExecutionException extends JobException
{
    private const int CODE = 4002;

    public static function failed(JobId $jobId, Throwable $cause): self
    {
        return new self(
            message: sprintf("Job '%s' execution failed: %s", $jobId->value, $cause->getMessage()),
            code: self::CODE,
            previous: $cause,
        );
    }

    public static function timeout(JobId $jobId, int $timeoutSeconds): self
    {
        return new self(
            message: sprintf("Job '%s' exceeded timeout of %d seconds", $jobId->value, $timeoutSeconds),
            code: self::CODE,
        );
    }
}
