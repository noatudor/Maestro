<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Infrastructure\Persistence\Models\StepRunModel;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Ramsey\Uuid\Uuid;

final readonly class StepRunHydrator
{
    /**
     * @param array{
     *     id?: StepRunId,
     *     workflow_id: WorkflowId,
     *     step_key: StepKey,
     *     attempt?: int,
     *     status?: StepState,
     *     total_job_count?: int,
     * } $data
     */
    public function createModel(array $data): StepRunModel
    {
        $model = new StepRunModel();
        $model->id = isset($data['id']) ? $data['id']->value : Uuid::uuid7()->toString();
        $model->workflow_id = $data['workflow_id']->value;
        $model->step_key = $data['step_key']->value;
        $model->attempt = $data['attempt'] ?? 1;
        $model->status = isset($data['status']) ? $data['status']->value : StepState::Pending->value;
        $model->completed_job_count = 0;
        $model->failed_job_count = 0;
        $model->total_job_count = $data['total_job_count'] ?? 0;

        return $model;
    }

    public function extractStepRunId(StepRunModel $model): StepRunId
    {
        return StepRunId::fromString($model->id);
    }

    public function extractWorkflowId(StepRunModel $model): WorkflowId
    {
        return WorkflowId::fromString($model->workflow_id);
    }

    /**
     * @throws \Maestro\Workflow\Exceptions\InvalidStepKeyException
     */
    public function extractStepKey(StepRunModel $model): StepKey
    {
        return StepKey::fromString($model->step_key);
    }

    public function extractStatus(StepRunModel $model): StepState
    {
        return StepState::from($model->status);
    }

    public function extractStartedAt(StepRunModel $model): ?CarbonImmutable
    {
        return $model->started_at;
    }

    public function extractFinishedAt(StepRunModel $model): ?CarbonImmutable
    {
        return $model->finished_at;
    }

    /**
     * @return array{
     *     id: StepRunId,
     *     workflow_id: WorkflowId,
     *     step_key: StepKey,
     *     attempt: int,
     *     status: StepState,
     *     started_at: CarbonImmutable|null,
     *     finished_at: CarbonImmutable|null,
     *     failure_code: string|null,
     *     failure_message: string|null,
     *     completed_job_count: int,
     *     failed_job_count: int,
     *     total_job_count: int,
     *     created_at: CarbonImmutable,
     *     updated_at: CarbonImmutable,
     * }
     *
     * @throws \Maestro\Workflow\Exceptions\InvalidStepKeyException
     */
    public function extractAll(StepRunModel $model): array
    {
        return [
            'id' => $this->extractStepRunId($model),
            'workflow_id' => $this->extractWorkflowId($model),
            'step_key' => $this->extractStepKey($model),
            'attempt' => $model->attempt,
            'status' => $this->extractStatus($model),
            'started_at' => $model->started_at,
            'finished_at' => $model->finished_at,
            'failure_code' => $model->failure_code,
            'failure_message' => $model->failure_message,
            'completed_job_count' => $model->completed_job_count,
            'failed_job_count' => $model->failed_job_count,
            'total_job_count' => $model->total_job_count,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ];
    }

    public function updateStatus(StepRunModel $model, StepState $status): void
    {
        $model->status = $status->value;
    }

    public function markStarted(StepRunModel $model): void
    {
        $model->status = StepState::Running->value;
        $model->started_at = CarbonImmutable::now();
    }

    public function markSucceeded(StepRunModel $model): void
    {
        $model->status = StepState::Succeeded->value;
        $model->finished_at = CarbonImmutable::now();
    }

    public function markFailed(StepRunModel $model, ?string $code = null, ?string $message = null): void
    {
        $model->status = StepState::Failed->value;
        $model->finished_at = CarbonImmutable::now();
        $model->failure_code = $code;
        $model->failure_message = $message;
    }

    public function incrementFailedJobCount(StepRunModel $model): void
    {
        $model->failed_job_count++;
    }

    public function setTotalJobCount(StepRunModel $model, int $count): void
    {
        $model->total_job_count = $count;
    }

    /**
     * @throws \Maestro\Workflow\Exceptions\InvalidStepKeyException
     */
    public function toDomain(StepRunModel $model): StepRun
    {
        return StepRun::reconstitute(
            id: $this->extractStepRunId($model),
            workflowId: $this->extractWorkflowId($model),
            stepKey: $this->extractStepKey($model),
            attempt: $model->attempt,
            status: $this->extractStatus($model),
            startedAt: $model->started_at,
            finishedAt: $model->finished_at,
            failureCode: $model->failure_code,
            failureMessage: $model->failure_message,
            completedJobCount: $model->completed_job_count,
            failedJobCount: $model->failed_job_count,
            totalJobCount: $model->total_job_count,
            createdAt: $model->created_at,
            updatedAt: $model->updated_at,
        );
    }

    public function fromDomain(StepRun $stepRun): StepRunModel
    {
        $model = new StepRunModel();
        $model->id = $stepRun->id->value;
        $model->workflow_id = $stepRun->workflowId->value;
        $model->step_key = $stepRun->stepKey->value;
        $model->attempt = $stepRun->attempt;
        $model->status = $stepRun->status()->value;
        $model->started_at = $stepRun->startedAt();
        $model->finished_at = $stepRun->finishedAt();
        $model->failure_code = $stepRun->failureCode();
        $model->failure_message = $stepRun->failureMessage();
        $model->completed_job_count = $stepRun->completedJobCount();
        $model->failed_job_count = $stepRun->failedJobCount();
        $model->total_job_count = $stepRun->totalJobCount();
        $model->created_at = $stepRun->createdAt;
        $model->updated_at = $stepRun->updatedAt();

        return $model;
    }

    public function updateFromDomain(StepRunModel $model, StepRun $stepRun): void
    {
        $model->status = $stepRun->status()->value;
        $model->started_at = $stepRun->startedAt();
        $model->finished_at = $stepRun->finishedAt();
        $model->failure_code = $stepRun->failureCode();
        $model->failure_message = $stepRun->failureMessage();
        $model->completed_job_count = $stepRun->completedJobCount();
        $model->failed_job_count = $stepRun->failedJobCount();
        $model->total_job_count = $stepRun->totalJobCount();
        $model->updated_at = $stepRun->updatedAt();
    }
}
