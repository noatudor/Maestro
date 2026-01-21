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

    public function extractJobId(JobLedgerModel $jobLedgerModel): JobId
    {
        return JobId::fromString($jobLedgerModel->id);
    }

    public function extractWorkflowId(JobLedgerModel $jobLedgerModel): WorkflowId
    {
        return WorkflowId::fromString($jobLedgerModel->workflow_id);
    }

    public function extractStepRunId(JobLedgerModel $jobLedgerModel): StepRunId
    {
        return StepRunId::fromString($jobLedgerModel->step_run_id);
    }

    public function extractStatus(JobLedgerModel $jobLedgerModel): JobState
    {
        return JobState::from($jobLedgerModel->status);
    }

    public function extractDispatchedAt(JobLedgerModel $jobLedgerModel): CarbonImmutable
    {
        return $jobLedgerModel->dispatched_at;
    }

    public function extractStartedAt(JobLedgerModel $jobLedgerModel): ?CarbonImmutable
    {
        return $jobLedgerModel->started_at;
    }

    public function extractFinishedAt(JobLedgerModel $jobLedgerModel): ?CarbonImmutable
    {
        return $jobLedgerModel->finished_at;
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
    public function extractAll(JobLedgerModel $jobLedgerModel): array
    {
        return [
            'id' => $this->extractJobId($jobLedgerModel),
            'workflow_id' => $this->extractWorkflowId($jobLedgerModel),
            'step_run_id' => $this->extractStepRunId($jobLedgerModel),
            'job_uuid' => $jobLedgerModel->job_uuid,
            'job_class' => $jobLedgerModel->job_class,
            'queue' => $jobLedgerModel->queue,
            'status' => $this->extractStatus($jobLedgerModel),
            'attempt' => $jobLedgerModel->attempt,
            'dispatched_at' => $jobLedgerModel->dispatched_at,
            'started_at' => $jobLedgerModel->started_at,
            'finished_at' => $jobLedgerModel->finished_at,
            'runtime_ms' => $jobLedgerModel->runtime_ms,
            'failure_class' => $jobLedgerModel->failure_class,
            'failure_message' => $jobLedgerModel->failure_message,
            'failure_trace' => $jobLedgerModel->failure_trace,
            'worker_id' => $jobLedgerModel->worker_id,
            'created_at' => $jobLedgerModel->created_at,
            'updated_at' => $jobLedgerModel->updated_at,
        ];
    }

    public function updateStatus(JobLedgerModel $jobLedgerModel, JobState $jobState): void
    {
        $jobLedgerModel->status = $jobState->value;
    }

    public function markStarted(JobLedgerModel $jobLedgerModel, ?string $workerId = null): void
    {
        $jobLedgerModel->status = JobState::Running->value;
        $jobLedgerModel->started_at = CarbonImmutable::now();
        $jobLedgerModel->worker_id = $workerId;
    }

    public function markSucceeded(JobLedgerModel $jobLedgerModel): void
    {
        $jobLedgerModel->status = JobState::Succeeded->value;
        $jobLedgerModel->finished_at = CarbonImmutable::now();

        if ($jobLedgerModel->started_at !== null) {
            $jobLedgerModel->runtime_ms = (int) $jobLedgerModel->started_at->diffInMilliseconds($jobLedgerModel->finished_at);
        }
    }

    public function markFailed(
        JobLedgerModel $jobLedgerModel,
        ?string $failureClass = null,
        ?string $failureMessage = null,
        ?string $failureTrace = null,
    ): void {
        $jobLedgerModel->status = JobState::Failed->value;
        $jobLedgerModel->finished_at = CarbonImmutable::now();
        $jobLedgerModel->failure_class = $failureClass;
        $jobLedgerModel->failure_message = $failureMessage;
        $jobLedgerModel->failure_trace = $failureTrace;

        if ($jobLedgerModel->started_at !== null) {
            $jobLedgerModel->runtime_ms = (int) $jobLedgerModel->started_at->diffInMilliseconds($jobLedgerModel->finished_at);
        }
    }

    public function incrementAttempt(JobLedgerModel $jobLedgerModel): void
    {
        $jobLedgerModel->attempt++;
    }

    public function toDomain(JobLedgerModel $jobLedgerModel): JobRecord
    {
        return JobRecord::reconstitute(
            jobId: $this->extractJobId($jobLedgerModel),
            workflowId: $this->extractWorkflowId($jobLedgerModel),
            stepRunId: $this->extractStepRunId($jobLedgerModel),
            jobUuid: $jobLedgerModel->job_uuid,
            jobClass: $jobLedgerModel->job_class,
            queue: $jobLedgerModel->queue,
            jobState: $this->extractStatus($jobLedgerModel),
            attempt: $jobLedgerModel->attempt,
            dispatchedAt: $jobLedgerModel->dispatched_at,
            startedAt: $jobLedgerModel->started_at,
            finishedAt: $jobLedgerModel->finished_at,
            runtimeMs: $jobLedgerModel->runtime_ms,
            failureClass: $jobLedgerModel->failure_class,
            failureMessage: $jobLedgerModel->failure_message,
            failureTrace: $jobLedgerModel->failure_trace,
            workerId: $jobLedgerModel->worker_id,
            createdAt: $jobLedgerModel->created_at,
            updatedAt: $jobLedgerModel->updated_at,
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

    public function updateFromDomain(JobLedgerModel $jobLedgerModel, JobRecord $jobRecord): void
    {
        $jobLedgerModel->status = $jobRecord->status()->value;
        $jobLedgerModel->attempt = $jobRecord->attempt();
        $jobLedgerModel->started_at = $jobRecord->startedAt();
        $jobLedgerModel->finished_at = $jobRecord->finishedAt();
        $jobLedgerModel->runtime_ms = $jobRecord->runtimeMs();
        $jobLedgerModel->failure_class = $jobRecord->failureClass();
        $jobLedgerModel->failure_message = $jobRecord->failureMessage();
        $jobLedgerModel->failure_trace = $jobRecord->failureTrace();
        $jobLedgerModel->worker_id = $jobRecord->workerId();
        $jobLedgerModel->updated_at = $jobRecord->updatedAt();
    }
}
