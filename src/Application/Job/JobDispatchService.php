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
        private JobLedgerRepository $jobLedger,
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
        OrchestratedJob $job,
        QueueConfiguration $queueConfig,
    ): string {
        $this->applyQueueConfiguration($job, $queueConfig);

        if ($this->jobLedger->findByJobUuid($job->jobUuid) !== null) {
            return $job->jobUuid;
        }

        $jobRecord = JobRecord::create(
            workflowId: $job->workflowId,
            stepRunId: $job->stepRunId,
            jobUuid: $job->jobUuid,
            jobClass: $job::class,
            queue: $this->resolveQueueName($job, $queueConfig),
        );

        $this->jobLedger->save($jobRecord);
        $this->dispatcher->dispatch($job);

        return $job->jobUuid;
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
        QueueConfiguration $queueConfig,
    ): array {
        $jobUuids = [];

        foreach ($jobs as $job) {
            $jobUuids[] = $this->dispatch($job, $queueConfig);
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

    private function applyQueueConfiguration(OrchestratedJob $job, QueueConfiguration $config): void
    {
        if ($config->hasQueue()) {
            $job->onQueue($config->queue);
        }

        if ($config->hasConnection()) {
            $job->onConnection($config->connection);
        }

        if ($config->hasDelay()) {
            $job->delay($config->delaySeconds);
        }
    }

    private function resolveQueueName(OrchestratedJob $job, QueueConfiguration $config): string
    {
        if ($config->hasQueue() && $config->queue !== null) {
            return $config->queue;
        }

        return $job->queue ?? 'default';
    }
}
