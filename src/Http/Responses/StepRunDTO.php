<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Responses;

use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;

/**
 * Data transfer object for step run API responses.
 */
final readonly class StepRunDTO
{
    private function __construct(
        public string $id,
        public string $workflowId,
        public string $stepKey,
        public int $attempt,
        public StepState $status,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $failureCode,
        public ?string $failureMessage,
        public int $completedJobCount,
        public int $failedJobCount,
        public int $totalJobCount,
        public int $succeededJobCount,
        public ?int $durationMs,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromStepRun(StepRun $stepRun): self
    {
        return new self(
            id: $stepRun->id->value,
            workflowId: $stepRun->workflowId->value,
            stepKey: $stepRun->stepKey->value,
            attempt: $stepRun->attempt,
            status: $stepRun->status(),
            startedAt: $stepRun->startedAt()?->toIso8601String(),
            finishedAt: $stepRun->finishedAt()?->toIso8601String(),
            failureCode: $stepRun->failureCode(),
            failureMessage: $stepRun->failureMessage(),
            completedJobCount: $stepRun->completedJobCount(),
            failedJobCount: $stepRun->failedJobCount(),
            totalJobCount: $stepRun->totalJobCount(),
            succeededJobCount: $stepRun->succeededJobCount(),
            durationMs: $stepRun->duration(),
            createdAt: $stepRun->createdAt->toIso8601String(),
            updatedAt: $stepRun->updatedAt()->toIso8601String(),
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
            'step_key' => $this->stepKey,
            'attempt' => $this->attempt,
            'status' => $this->status->value,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'failure_code' => $this->failureCode,
            'failure_message' => $this->failureMessage,
            'completed_job_count' => $this->completedJobCount,
            'failed_job_count' => $this->failedJobCount,
            'total_job_count' => $this->totalJobCount,
            'succeeded_job_count' => $this->succeededJobCount,
            'duration_ms' => $this->durationMs,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
