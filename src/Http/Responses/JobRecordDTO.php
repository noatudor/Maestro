<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Responses;

use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;

/**
 * Data transfer object for job record API responses.
 */
final readonly class JobRecordDTO
{
    private function __construct(
        public string $id,
        public string $workflowId,
        public string $stepRunId,
        public string $jobUuid,
        public string $jobClass,
        public string $queue,
        public JobState $status,
        public int $attempt,
        public string $dispatchedAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?int $runtimeMs,
        public ?int $queueWaitTimeMs,
        public ?string $failureClass,
        public ?string $failureMessage,
        public ?string $workerId,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromJobRecord(JobRecord $jobRecord): self
    {
        return new self(
            id: $jobRecord->id->value,
            workflowId: $jobRecord->workflowId->value,
            stepRunId: $jobRecord->stepRunId->value,
            jobUuid: $jobRecord->jobUuid,
            jobClass: $jobRecord->jobClass,
            queue: $jobRecord->queue,
            status: $jobRecord->status(),
            attempt: $jobRecord->attempt(),
            dispatchedAt: $jobRecord->dispatchedAt->toIso8601String(),
            startedAt: $jobRecord->startedAt()?->toIso8601String(),
            finishedAt: $jobRecord->finishedAt()?->toIso8601String(),
            runtimeMs: $jobRecord->runtimeMs(),
            queueWaitTimeMs: $jobRecord->queueWaitTime(),
            failureClass: $jobRecord->failureClass(),
            failureMessage: $jobRecord->failureMessage(),
            workerId: $jobRecord->workerId(),
            createdAt: $jobRecord->createdAt->toIso8601String(),
            updatedAt: $jobRecord->updatedAt()->toIso8601String(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflowId,
            'step_run_id' => $this->stepRunId,
            'job_uuid' => $this->jobUuid,
            'job_class' => $this->jobClass,
            'queue' => $this->queue,
            'status' => $this->status->value,
            'attempt' => $this->attempt,
            'dispatched_at' => $this->dispatchedAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'runtime_ms' => $this->runtimeMs,
            'queue_wait_time_ms' => $this->queueWaitTimeMs,
            'failure_class' => $this->failureClass,
            'failure_message' => $this->failureMessage,
            'worker_id' => $this->workerId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
