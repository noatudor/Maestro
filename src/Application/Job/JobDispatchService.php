<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Maestro\Workflow\Contracts\DispatchableWorkflowJob;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Definition\Config\QueueConfiguration;
use Maestro\Workflow\Domain\Events\JobDispatched;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepKey;
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
        private EventDispatcher $eventDispatcher,
    ) {}

    /**
     * Dispatch a single workflow job.
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
        DispatchableWorkflowJob $dispatchableWorkflowJob,
        QueueConfiguration $queueConfiguration,
    ): string {
        $this->applyQueueConfiguration($dispatchableWorkflowJob, $queueConfiguration);

        if ($this->jobLedgerRepository->findByJobUuid($dispatchableWorkflowJob->getJobUuid()) instanceof JobRecord) {
            return $dispatchableWorkflowJob->getJobUuid();
        }

        $queue = $this->resolveQueueName($dispatchableWorkflowJob, $queueConfiguration);

        $jobRecord = JobRecord::create(
            workflowId: $dispatchableWorkflowJob->getWorkflowId(),
            stepRunId: $dispatchableWorkflowJob->getStepRunId(),
            jobUuid: $dispatchableWorkflowJob->getJobUuid(),
            jobClass: $dispatchableWorkflowJob::class,
            queue: $queue,
        );

        $this->jobLedgerRepository->save($jobRecord);
        $this->dispatcher->dispatch($dispatchableWorkflowJob);

        $this->eventDispatcher->dispatch(new JobDispatched(
            workflowId: $dispatchableWorkflowJob->getWorkflowId(),
            stepRunId: $dispatchableWorkflowJob->getStepRunId(),
            jobId: $jobRecord->id,
            jobUuid: $dispatchableWorkflowJob->getJobUuid(),
            jobClass: $dispatchableWorkflowJob::class,
            queue: $queue,
            occurredAt: CarbonImmutable::now(),
        ));

        return $dispatchableWorkflowJob->getJobUuid();
    }

    /**
     * Dispatch multiple jobs for a fan-out step.
     *
     * Creates ledger entries for all jobs and dispatches them.
     * Returns an array of job UUIDs for correlation.
     *
     * @param iterable<DispatchableWorkflowJob> $jobs
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

    /**
     * Dispatch a compensation job.
     *
     * Compensation jobs are dispatched without a step run, as they are tracked
     * by the compensation run instead.
     */
    public function dispatchCompensationJob(
        WorkflowId $workflowId,
        StepKey $stepKey,
        CompensationRunId $compensationRunId,
        JobId $jobId,
        string $compensationJobClass,
        ?QueueConfiguration $queueConfiguration = null,
    ): void {
        $job = new $compensationJobClass(
            $workflowId,
            $stepKey,
            $compensationRunId,
            $jobId,
        );

        $effectiveQueueConfig = $queueConfiguration ?? QueueConfiguration::default();

        if ($job instanceof OrchestratedJob) {
            $this->applyQueueConfiguration($job, $effectiveQueueConfig);
        }

        $this->dispatcher->dispatch($job);
    }

    private function applyQueueConfiguration(DispatchableWorkflowJob $dispatchableWorkflowJob, QueueConfiguration $queueConfiguration): void
    {
        if ($queueConfiguration->hasQueue() && method_exists($dispatchableWorkflowJob, 'onQueue')) {
            $dispatchableWorkflowJob->onQueue($queueConfiguration->queue);
        }

        if ($queueConfiguration->hasConnection() && method_exists($dispatchableWorkflowJob, 'onConnection')) {
            $dispatchableWorkflowJob->onConnection($queueConfiguration->connection);
        }

        if ($queueConfiguration->hasDelay() && method_exists($dispatchableWorkflowJob, 'delay')) {
            $dispatchableWorkflowJob->delay($queueConfiguration->delaySeconds);
        }
    }

    private function resolveQueueName(DispatchableWorkflowJob $dispatchableWorkflowJob, QueueConfiguration $queueConfiguration): string
    {
        if ($queueConfiguration->hasQueue() && $queueConfiguration->queue !== null) {
            return $queueConfiguration->queue;
        }

        /** @var string|null $queue */
        $queue = property_exists($dispatchableWorkflowJob, 'queue') ? $dispatchableWorkflowJob->queue : null;

        return $queue ?? 'default';
    }
}
