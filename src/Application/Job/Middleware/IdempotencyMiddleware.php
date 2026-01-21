<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job\Middleware;

use Closure;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Contracts\JobLedgerRepository;

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
        private JobLedgerRepository $jobLedger,
    ) {}

    /**
     * Handle the job with idempotency checking.
     *
     * @param Closure(OrchestratedJob): void $next
     */
    public function handle(OrchestratedJob $job, Closure $next): void
    {
        $existingJob = $this->jobLedger->findByJobUuid($job->jobUuid);

        if ($existingJob !== null && $existingJob->isTerminal()) {
            return;
        }

        $next($job);
    }
}
