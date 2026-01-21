<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Infrastructure\Persistence\Models\JobLedgerModel;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Ramsey\Uuid\Uuid;

final readonly class JobLedgerHydrator
{
    /**
     * @param array{
     *     id?: JobId,
     *     workflow_id: WorkflowId,
     *     step_run_id: StepRunId,
     *     job_uuid: string,
     *     job_class: class-string,
     *     queue: string,
     *     status?: JobState,
     * } $data
     */
    public function createModel(array $data): JobLedgerModel
    {
        $model = new JobLedgerModel();
        $model->id = isset($data['id']) ? $data['id']->value : Uuid::uuid7()->toString();
        $model->workflow_id = $data['workflow_id']->value;
        $model->step_run_id = $data['step_run_id']->value;
        $model->job_uuid = $data['job_uuid'];
        $model->job_class = $data['job_class'];
        $model->queue = $data['queue'];
        $model->status = isset($data['status']) ? $data['status']->value : JobState::Dispatched->value;
        $model->attempt = 1;
        $model->dispatched_at = CarbonImmutable::now();

        return $model;
    }

    public function extractJobId(JobLedgerModel $model): JobId
    {
        return JobId::fromString($model->id);
    }

    public function extractWorkflowId(JobLedgerModel $model): WorkflowId
    {
        return WorkflowId::fromString($model->workflow_id);
    }

    public function extractStepRunId(JobLedgerModel $model): StepRunId
    {
        return StepRunId::fromString($model->step_run_id);
    }

    public function extractStatus(JobLedgerModel $model): JobState
    {
        return JobState::from($model->status);
    }

    public function extractDispatchedAt(JobLedgerModel $model): CarbonImmutable
    {
        return $model->dispatched_at;
    }

    public function extractStartedAt(JobLedgerModel $model): ?CarbonImmutable
    {
        return $model->started_at;
    }

    public function extractFinishedAt(JobLedgerModel $model): ?CarbonImmutable
    {
        return $model->finished_at;
    }

    /**
     * @return array{
     *     id: JobId,
     *     workflow_id: WorkflowId,
     *     step_run_id: StepRunId,
     *     job_uuid: string,
     *     job_class: string,
     *     queue: string,
     *     status: JobState,
     *     attempt: int,
     *     dispatched_at: CarbonImmutable,
     *     started_at: CarbonImmutable|null,
     *     finished_at: CarbonImmutable|null,
     *     runtime_ms: int|null,
     *     failure_class: string|null,
     *     failure_message: string|null,
     *     failure_trace: string|null,
     *     worker_id: string|null,
     *     created_at: CarbonImmutable,
     *     updated_at: CarbonImmutable,
     * }
     */
    public function extractAll(JobLedgerModel $model): array
    {
        return [
            'id' => $this->extractJobId($model),
            'workflow_id' => $this->extractWorkflowId($model),
            'step_run_id' => $this->extractStepRunId($model),
            'job_uuid' => $model->job_uuid,
            'job_class' => $model->job_class,
            'queue' => $model->queue,
            'status' => $this->extractStatus($model),
            'attempt' => $model->attempt,
            'dispatched_at' => $model->dispatched_at,
            'started_at' => $model->started_at,
            'finished_at' => $model->finished_at,
            'runtime_ms' => $model->runtime_ms,
            'failure_class' => $model->failure_class,
            'failure_message' => $model->failure_message,
            'failure_trace' => $model->failure_trace,
            'worker_id' => $model->worker_id,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ];
    }

    public function updateStatus(JobLedgerModel $model, JobState $status): void
    {
        $model->status = $status->value;
    }

    public function markStarted(JobLedgerModel $model, ?string $workerId = null): void
    {
        $model->status = JobState::Running->value;
        $model->started_at = CarbonImmutable::now();
        $model->worker_id = $workerId;
    }

    public function markSucceeded(JobLedgerModel $model): void
    {
        $model->status = JobState::Succeeded->value;
        $model->finished_at = CarbonImmutable::now();

        if ($model->started_at !== null) {
            $model->runtime_ms = (int) $model->started_at->diffInMilliseconds($model->finished_at);
        }
    }

    public function markFailed(
        JobLedgerModel $model,
        ?string $failureClass = null,
        ?string $failureMessage = null,
        ?string $failureTrace = null,
    ): void {
        $model->status = JobState::Failed->value;
        $model->finished_at = CarbonImmutable::now();
        $model->failure_class = $failureClass;
        $model->failure_message = $failureMessage;
        $model->failure_trace = $failureTrace;

        if ($model->started_at !== null) {
            $model->runtime_ms = (int) $model->started_at->diffInMilliseconds($model->finished_at);
        }
    }

    public function incrementAttempt(JobLedgerModel $model): void
    {
        $model->attempt++;
    }

    public function toDomain(JobLedgerModel $model): JobRecord
    {
        return JobRecord::reconstitute(
            id: $this->extractJobId($model),
            workflowId: $this->extractWorkflowId($model),
            stepRunId: $this->extractStepRunId($model),
            jobUuid: $model->job_uuid,
            jobClass: $model->job_class,
            queue: $model->queue,
            status: $this->extractStatus($model),
            attempt: $model->attempt,
            dispatchedAt: $model->dispatched_at,
            startedAt: $model->started_at,
            finishedAt: $model->finished_at,
            runtimeMs: $model->runtime_ms,
            failureClass: $model->failure_class,
            failureMessage: $model->failure_message,
            failureTrace: $model->failure_trace,
            workerId: $model->worker_id,
            createdAt: $model->created_at,
            updatedAt: $model->updated_at,
        );
    }

    public function fromDomain(JobRecord $jobRecord): JobLedgerModel
    {
        $model = new JobLedgerModel();
        $model->id = $jobRecord->id->value;
        $model->workflow_id = $jobRecord->workflowId->value;
        $model->step_run_id = $jobRecord->stepRunId->value;
        $model->job_uuid = $jobRecord->jobUuid;
        $model->job_class = $jobRecord->jobClass;
        $model->queue = $jobRecord->queue;
        $model->status = $jobRecord->status()->value;
        $model->attempt = $jobRecord->attempt();
        $model->dispatched_at = $jobRecord->dispatchedAt;
        $model->started_at = $jobRecord->startedAt();
        $model->finished_at = $jobRecord->finishedAt();
        $model->runtime_ms = $jobRecord->runtimeMs();
        $model->failure_class = $jobRecord->failureClass();
        $model->failure_message = $jobRecord->failureMessage();
        $model->failure_trace = $jobRecord->failureTrace();
        $model->worker_id = $jobRecord->workerId();
        $model->created_at = $jobRecord->createdAt;
        $model->updated_at = $jobRecord->updatedAt();

        return $model;
    }

    public function updateFromDomain(JobLedgerModel $model, JobRecord $jobRecord): void
    {
        $model->status = $jobRecord->status()->value;
        $model->attempt = $jobRecord->attempt();
        $model->started_at = $jobRecord->startedAt();
        $model->finished_at = $jobRecord->finishedAt();
        $model->runtime_ms = $jobRecord->runtimeMs();
        $model->failure_class = $jobRecord->failureClass();
        $model->failure_message = $jobRecord->failureMessage();
        $model->failure_trace = $jobRecord->failureTrace();
        $model->worker_id = $jobRecord->workerId();
        $model->updated_at = $jobRecord->updatedAt();
    }
}
