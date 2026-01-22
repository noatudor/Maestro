<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Maestro\Workflow\Domain\CompensationRun;
use Maestro\Workflow\Enums\CompensationRunStatus;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Infrastructure\Persistence\Models\CompensationRunModel;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class CompensationRunHydrator
{
    /**
     * @throws InvalidStepKeyException
     */
    public function toDomain(CompensationRunModel $compensationRunModel): CompensationRun
    {
        $currentJobId = $compensationRunModel->current_job_id !== null
            ? JobId::fromString($compensationRunModel->current_job_id)
            : null;

        return CompensationRun::reconstitute(
            workflowId: WorkflowId::fromString($compensationRunModel->workflow_id),
            stepKey: StepKey::fromString($compensationRunModel->step_key),
            compensationJobClass: $compensationRunModel->compensation_job_class,
            executionOrder: $compensationRunModel->execution_order,
            attempt: $compensationRunModel->attempt,
            maxAttempts: $compensationRunModel->max_attempts,
            currentJobId: $currentJobId,
            startedAt: $compensationRunModel->started_at,
            finishedAt: $compensationRunModel->finished_at,
            failureMessage: $compensationRunModel->failure_message,
            failureTrace: $compensationRunModel->failure_trace,
            createdAt: $compensationRunModel->created_at,
            updatedAt: $compensationRunModel->updated_at,
            id: CompensationRunId::fromString($compensationRunModel->id),
            status: CompensationRunStatus::from($compensationRunModel->status),
        );
    }

    public function fromDomain(CompensationRun $compensationRun): CompensationRunModel
    {
        $model = new CompensationRunModel();
        $this->updateFromDomain($model, $compensationRun);

        return $model;
    }

    public function updateFromDomain(CompensationRunModel $compensationRunModel, CompensationRun $compensationRun): void
    {
        $compensationRunModel->id = $compensationRun->id->value;
        $compensationRunModel->workflow_id = $compensationRun->workflowId->value;
        $compensationRunModel->step_key = $compensationRun->stepKey->value;
        $compensationRunModel->compensation_job_class = $compensationRun->compensationJobClass;
        $compensationRunModel->execution_order = $compensationRun->executionOrder;
        $compensationRunModel->status = $compensationRun->status()->value;
        $compensationRunModel->attempt = $compensationRun->attempt();
        $compensationRunModel->max_attempts = $compensationRun->maxAttempts();
        $compensationRunModel->current_job_id = $compensationRun->currentJobId()?->value;
        $compensationRunModel->started_at = $compensationRun->startedAt();
        $compensationRunModel->finished_at = $compensationRun->finishedAt();
        $compensationRunModel->failure_message = $compensationRun->failureMessage();
        $compensationRunModel->failure_trace = $compensationRun->failureTrace();
    }
}
