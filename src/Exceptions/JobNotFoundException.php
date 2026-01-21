<?php

declare(strict_types=1);

namespace Maestro\Workflow\Exceptions;

use Maestro\Workflow\ValueObjects\JobId;

final class JobNotFoundException extends JobException
{
    private const int CODE = 4001;

    public static function withId(JobId $jobId): self
    {
        return new self(
            message: sprintf('Job not found: %s', $jobId->value),
            code: self::CODE,
        );
    }
}
