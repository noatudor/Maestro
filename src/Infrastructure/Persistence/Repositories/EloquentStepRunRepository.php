<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Exceptions\StepNotFoundException;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\StepRunHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Models\StepRunModel;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class EloquentStepRunRepository implements StepRunRepository
{
    public function __construct(
        private StepRunHydrator $stepRunHydrator,
    ) {}

    /**
     * @throws InvalidStepKeyException
     */
    public function find(StepRunId $stepRunId): ?StepRun
    {
        $model = StepRunModel::query()->find($stepRunId->value);

        if ($model === null) {
            return null;
        }

        return $this->stepRunHydrator->toDomain($model);
    }

    /**
     * @throws StepNotFoundException
     * @throws InvalidStepKeyException
     */
    public function findOrFail(StepRunId $stepRunId): StepRun
    {
        $stepRun = $this->find($stepRunId);

        if (! $stepRun instanceof StepRun) {
            throw StepNotFoundException::withId($stepRunId);
        }

        return $stepRun;
    }

    public function save(StepRun $stepRun): void
    {
        $existingModel = StepRunModel::query()->find($stepRun->id->value);

        if ($existingModel !== null) {
            $this->stepRunHydrator->updateFromDomain($existingModel, $stepRun);
            $existingModel->save();

            return;
        }

        $stepRunModel = $this->stepRunHydrator->fromDomain($stepRun);
        $stepRunModel->save();
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function findByWorkflowId(WorkflowId $workflowId): StepRunCollection
    {
        $models = StepRunModel::query()
            ->forWorkflow($workflowId->value)
            ->orderBy('created_at')
            ->get();

        return new StepRunCollection($this->hydrateModels($models->all()));
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function findByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): ?StepRun
    {
        $model = StepRunModel::query()
            ->forWorkflowAndStep($workflowId->value, $stepKey->value)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->stepRunHydrator->toDomain($model);
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function findLatestByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): ?StepRun
    {
        $model = StepRunModel::query()
            ->forWorkflowAndStep($workflowId->value, $stepKey->value)
            ->latestAttempt()
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->stepRunHydrator->toDomain($model);
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function findByWorkflowIdAndState(WorkflowId $workflowId, StepState $stepState): StepRunCollection
    {
        $models = StepRunModel::query()
            ->forWorkflow($workflowId->value)
            ->where('status', $stepState->value)
            ->get();

        return new StepRunCollection($this->hydrateModels($models->all()));
    }

    public function exists(StepRunId $stepRunId): bool
    {
        return StepRunModel::query()
            ->where('id', $stepRunId->value)
            ->exists();
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function findRunningByWorkflowId(WorkflowId $workflowId): StepRunCollection
    {
        return $this->findByWorkflowIdAndState($workflowId, StepState::Running);
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function findPendingByWorkflowId(WorkflowId $workflowId): StepRunCollection
    {
        return $this->findByWorkflowIdAndState($workflowId, StepState::Pending);
    }

    public function countAttemptsByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): int
    {
        return StepRunModel::query()
            ->forWorkflowAndStep($workflowId->value, $stepKey->value)
            ->count();
    }

    public function getMaxAttemptByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): int
    {
        $max = StepRunModel::query()
            ->forWorkflowAndStep($workflowId->value, $stepKey->value)
            ->max('attempt');

        return is_numeric($max) ? (int) $max : 0;
    }

    public function updateStatusAtomically(StepRunId $stepRunId, StepState $fromState, StepState $toState): bool
    {
        $affected = StepRunModel::query()
            ->where('id', $stepRunId->value)
            ->where('status', $fromState->value)
            ->update(['status' => $toState->value]);

        return $affected > 0;
    }

    public function finalizeAsSucceeded(StepRunId $stepRunId, CarbonImmutable $finishedAt): bool
    {
        $affected = StepRunModel::query()
            ->where('id', $stepRunId->value)
            ->where('status', StepState::Running->value)
            ->update([
                'status' => StepState::Succeeded->value,
                'finished_at' => $finishedAt,
            ]);

        return $affected > 0;
    }

    public function finalizeAsFailed(
        StepRunId $stepRunId,
        string $failureCode,
        string $failureMessage,
        int $failedJobCount,
        CarbonImmutable $finishedAt,
    ): bool {
        $affected = StepRunModel::query()
            ->where('id', $stepRunId->value)
            ->where('status', StepState::Running->value)
            ->update([
                'status' => StepState::Failed->value,
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
                'failed_job_count' => $failedJobCount,
                'finished_at' => $finishedAt,
            ]);

        return $affected > 0;
    }

    public function deleteByWorkflowId(WorkflowId $workflowId): void
    {
        StepRunModel::query()
            ->forWorkflow($workflowId->value)
            ->delete();
    }

    /**
     * @param array<int|string, StepRunModel> $models
     *
     * @return list<StepRun>
     *
     * @throws InvalidStepKeyException
     */
    private function hydrateModels(array $models): array
    {
        $result = [];
        foreach ($models as $model) {
            $result[] = $this->stepRunHydrator->toDomain($model);
        }

        return $result;
    }
}
