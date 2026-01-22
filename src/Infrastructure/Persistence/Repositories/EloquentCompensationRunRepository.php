<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Maestro\Workflow\Contracts\CompensationRunRepository;
use Maestro\Workflow\Domain\CompensationRun;
use Maestro\Workflow\Enums\CompensationRunStatus;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\CompensationRunHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Models\CompensationRunModel;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class EloquentCompensationRunRepository implements CompensationRunRepository
{
    public function __construct(
        private CompensationRunHydrator $compensationRunHydrator,
    ) {}

    /**
     * @throws InvalidStepKeyException
     */
    public function find(CompensationRunId $compensationRunId): ?CompensationRun
    {
        $model = CompensationRunModel::query()->find($compensationRunId->value);

        if ($model === null) {
            return null;
        }

        return $this->compensationRunHydrator->toDomain($model);
    }

    public function save(CompensationRun $compensationRun): void
    {
        $existingModel = CompensationRunModel::query()->find($compensationRun->id->value);

        if ($existingModel !== null) {
            $this->compensationRunHydrator->updateFromDomain($existingModel, $compensationRun);
            $existingModel->save();

            return;
        }

        $compensationRunModel = $this->compensationRunHydrator->fromDomain($compensationRun);
        $compensationRunModel->save();
    }

    /**
     * @return list<CompensationRun>
     *
     * @throws InvalidStepKeyException
     */
    public function findByWorkflow(WorkflowId $workflowId): array
    {
        $models = CompensationRunModel::query()
            ->where('workflow_id', $workflowId->value)
            ->orderBy('execution_order')
            ->get();

        $runs = [];
        foreach ($models as $model) {
            $runs[] = $this->compensationRunHydrator->toDomain($model);
        }

        return $runs;
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function findByWorkflowAndStep(WorkflowId $workflowId, StepKey $stepKey): ?CompensationRun
    {
        $model = CompensationRunModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('step_key', $stepKey->value)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->compensationRunHydrator->toDomain($model);
    }

    /**
     * @return list<CompensationRun>
     *
     * @throws InvalidStepKeyException
     */
    public function findByWorkflowAndStatus(WorkflowId $workflowId, CompensationRunStatus $compensationRunStatus): array
    {
        $models = CompensationRunModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('status', $compensationRunStatus->value)
            ->orderBy('execution_order')
            ->get();

        $runs = [];
        foreach ($models as $model) {
            $runs[] = $this->compensationRunHydrator->toDomain($model);
        }

        return $runs;
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function findNextPending(WorkflowId $workflowId): ?CompensationRun
    {
        $model = CompensationRunModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('status', CompensationRunStatus::Pending->value)
            ->orderBy('execution_order')
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->compensationRunHydrator->toDomain($model);
    }

    public function allTerminal(WorkflowId $workflowId): bool
    {
        $terminalStatuses = [
            CompensationRunStatus::Succeeded->value,
            CompensationRunStatus::Failed->value,
            CompensationRunStatus::Skipped->value,
        ];

        $nonTerminalCount = CompensationRunModel::query()
            ->where('workflow_id', $workflowId->value)
            ->whereNotIn('status', $terminalStatuses)
            ->count();

        return $nonTerminalCount === 0;
    }

    public function allSuccessful(WorkflowId $workflowId): bool
    {
        $successStatuses = [
            CompensationRunStatus::Succeeded->value,
            CompensationRunStatus::Skipped->value,
        ];

        $failedCount = CompensationRunModel::query()
            ->where('workflow_id', $workflowId->value)
            ->whereNotIn('status', $successStatuses)
            ->count();

        return $failedCount === 0;
    }

    public function anyFailed(WorkflowId $workflowId): bool
    {
        return CompensationRunModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('status', CompensationRunStatus::Failed->value)
            ->exists();
    }

    public function countByWorkflow(WorkflowId $workflowId): int
    {
        return CompensationRunModel::query()
            ->where('workflow_id', $workflowId->value)
            ->count();
    }

    public function deleteByWorkflow(WorkflowId $workflowId): int
    {
        /** @var int */
        return CompensationRunModel::query()
            ->where('workflow_id', $workflowId->value)
            ->delete();
    }
}
