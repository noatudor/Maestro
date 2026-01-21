<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job;

use Illuminate\Contracts\Bus\Dispatcher;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Ramsey\Uuid\Uuid;

/**
 * Service responsible for dispatching orchestrated jobs.
 *
 * Creates job ledger entries before dispatch and applies queue configuration.
 * Ensures correlation metadata is properly set for lifecycle tracking.
 *
 * Implements idempotent dispatch pattern:
 * - Checks if job UUID already exists in ledger before dispatch
 * - Uses database transaction to ensure atomicity of ledger creation
 * - Dispatches to queue after successful ledger entry
 */
final readonly class JobDispatchService
{
    public function __construct(
        private Dispatcher $dispatcher,
        private JobLedgerRepository $jobLedgerRepository,
    ) {}

    /**
     * Dispatch a single orchestrated job.
     *
     * Creates a ledger entry before dispatching and applies queue configuration.
     * Returns the job UUID for correlation.
     *
     * If a job with the same UUID already exists in the ledger (indicating
     * duplicate dispatch attempt), the job is not dispatched again.
     *
     * @return string The job UUID
     */
    public function dispatch(
        OrchestratedJob $orchestratedJob,
        QueueConfiguration $queueConfiguration,
    ): string {
        $this->applyQueueConfiguration($orchestratedJob, $queueConfiguration);

        if ($this->jobLedgerRepository->findByJobUuid($orchestratedJob->jobUuid) instanceof JobRecord) {
            return $orchestratedJob->jobUuid;
        }

        $jobRecord = JobRecord::create(
            workflowId: $orchestratedJob->workflowId,
            stepRunId: $orchestratedJob->stepRunId,
            jobUuid: $orchestratedJob->jobUuid,
            jobClass: $orchestratedJob::class,
            queue: $this->resolveQueueName($orchestratedJob, $queueConfiguration),
        );

        $this->jobLedgerRepository->save($jobRecord);
        $this->dispatcher->dispatch($orchestratedJob);

        return $orchestratedJob->jobUuid;
    }

    /**
     * Dispatch multiple jobs for a fan-out step.
     *
     * Creates ledger entries for all jobs and dispatches them.
     * Returns an array of job UUIDs for correlation.
     *
     * @param iterable<OrchestratedJob> $jobs
     *
     * @return list<string>
     */
    public function dispatchMany(
        iterable $jobs,
        QueueConfiguration $queueConfiguration,
    ): array {
        $jobUuids = [];

        foreach ($jobs as $job) {
            $jobUuids[] = $this->dispatch($job, $queueConfiguration);
        }

        return $jobUuids;
    }

    /**
     * Create an orchestrated job with a generated UUID.
     *
     * @template T of OrchestratedJob
     *
     * @param class-string<T> $jobClass
     * @param array<string, mixed> $arguments Additional arguments after the required ones
     *
     * @return T
     */
    public function createJob(
        string $jobClass,
        WorkflowId $workflowId,
        StepRunId $stepRunId,
        array $arguments = [],
    ): OrchestratedJob {
        $jobUuid = $this->generateJobUuid();

        return new $jobClass($workflowId, $stepRunId, $jobUuid, ...$arguments);
    }

    /**
     * Generate a unique job UUID.
     *
     * Uses UUIDv7 for time-ordered identifiers.
     */
    public function generateJobUuid(): string
    {
        return Uuid::uuid7()->toString();
    }

    private function applyQueueConfiguration(OrchestratedJob $orchestratedJob, QueueConfiguration $queueConfiguration): void
    {
        if ($queueConfiguration->hasQueue()) {
            $orchestratedJob->onQueue($queueConfiguration->queue);
        }

        if ($queueConfiguration->hasConnection()) {
            $orchestratedJob->onConnection($queueConfiguration->connection);
        }

        if ($queueConfiguration->hasDelay()) {
            $orchestratedJob->delay($queueConfiguration->delaySeconds);
        }
    }

    private function resolveQueueName(OrchestratedJob $orchestratedJob, QueueConfiguration $queueConfiguration): string
    {
        if ($queueConfiguration->hasQueue() && $queueConfiguration->queue !== null) {
            return $queueConfiguration->queue;
        }

        return $orchestratedJob->queue ?? 'default';
    }
}
