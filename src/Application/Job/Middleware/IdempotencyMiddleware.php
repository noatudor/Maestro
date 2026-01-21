<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job\Middleware;

use Closure;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Domain\JobRecord;

/**
 * Middleware that prevents duplicate job execution.
 *
 * Checks if a job has already been completed and skips execution if so.
 * This handles cases where jobs may be dispatched multiple times due to
 * queue driver retries or network issues.
 */
final readonly class IdempotencyMiddleware
{
    public function __construct(
        private JobLedgerRepository $jobLedgerRepository,
    ) {}

    /**
     * Handle the job with idempotency checking.
     *
     * @param Closure(OrchestratedJob): void $next
     */
    public function handle(OrchestratedJob $orchestratedJob, Closure $next): void
    {
        $existingJob = $this->jobLedgerRepository->findByJobUuid($orchestratedJob->jobUuid);

        if ($existingJob instanceof JobRecord && $existingJob->isTerminal()) {
            return;
        }

        $next($orchestratedJob);
    }
}
