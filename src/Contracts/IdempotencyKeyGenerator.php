<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Application\Job\OrchestratedJob;

/**
 * Generates idempotency keys for orchestrated jobs.
 *
 * The generated key should uniquely identify a specific job execution
 * to prevent duplicate processing of the same work.
 */
interface IdempotencyKeyGenerator
{
    /**
     * Generate an idempotency key for the given job.
     *
     * The key should be deterministic - calling this method multiple times
     * with the same job should return the same key.
     */
    public function generate(OrchestratedJob $orchestratedJob): string;
}
