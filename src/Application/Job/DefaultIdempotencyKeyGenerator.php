<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job;

use Maestro\Workflow\Contracts\IdempotencyKeyGenerator;

/**
 * Default idempotency key generator using job UUID.
 *
 * The job UUID is already unique per dispatch, making it an ideal
 * base for idempotency checking.
 */
final readonly class DefaultIdempotencyKeyGenerator implements IdempotencyKeyGenerator
{
    private const string PREFIX = 'maestro:job:';

    public function generate(OrchestratedJob $orchestratedJob): string
    {
        return self::PREFIX.$orchestratedJob->jobUuid;
    }
}
