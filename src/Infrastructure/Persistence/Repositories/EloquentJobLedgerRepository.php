<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Contracts\JobLedgerRepository;
use Maestro\Workflow\Domain\Collections\JobRecordCollection;
use Maestro\Workflow\Domain\JobRecord;
use Maestro\Workflow\Enums\JobState;
use Maestro\Workflow\Exceptions\JobNotFoundException;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\JobLedgerHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Models\JobLedgerModel;
use Maestro\Workflow\ValueObjects\JobId;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class EloquentJobLedgerRepository implements JobLedgerRepository
{
    public function __construct(
        private JobLedgerHydrator $hydrator,
    ) {}

    public function find(JobId $jobId): ?JobRecord
    {
        $model = JobLedgerModel::query()->find($jobId->value);

        if ($model === null) {
            return null;
        }

        return $this->hydrator->toDomain($model);
    }

    /**
     * @throws JobNotFoundException
     */
    public function findOrFail(JobId $jobId): JobRecord
    {
        $jobRecord = $this->find($jobId);

        if ($jobRecord === null) {
            throw JobNotFoundException::withId($jobId);
        }

        return $jobRecord;
    }

    public function save(JobRecord $jobRecord): void
    {
        $existingModel = JobLedgerModel::query()->find($jobRecord->id->value);

        if ($existingModel !== null) {
            $this->hydrator->updateFromDomain($existingModel, $jobRecord);
            $existingModel->save();

            return;
        }

        $model = $this->hydrator->fromDomain($jobRecord);
        $model->save();
    }

    public function findByStepRunId(StepRunId $stepRunId): JobRecordCollection
    {
        $models = JobLedgerModel::query()
            ->forStepRun($stepRunId->value)
            ->orderBy('dispatched_at')
            ->get();

        return new JobRecordCollection($this->hydrateModels($models->all()));
    }

    public function findByWorkflowId(WorkflowId $workflowId): JobRecordCollection
    {
        $models = JobLedgerModel::query()
            ->forWorkflow($workflowId->value)
            ->orderBy('dispatched_at')
            ->get();

        return new JobRecordCollection($this->hydrateModels($models->all()));
    }

    public function findByStepRunIdAndState(StepRunId $stepRunId, JobState $state): JobRecordCollection
    {
        $models = JobLedgerModel::query()
            ->forStepRun($stepRunId->value)
            ->where('status', $state->value)
            ->get();

        return new JobRecordCollection($this->hydrateModels($models->all()));
    }

    public function countByStepRunId(StepRunId $stepRunId): int
    {
        return JobLedgerModel::query()
            ->forStepRun($stepRunId->value)
            ->count();
    }

    public function countByStepRunIdAndState(StepRunId $stepRunId, JobState $state): int
    {
        return JobLedgerModel::query()
            ->forStepRun($stepRunId->value)
            ->where('status', $state->value)
            ->count();
    }

    public function findByJobUuid(string $jobUuid): ?JobRecord
    {
        $model = JobLedgerModel::query()
            ->byJobUuid($jobUuid)
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->hydrator->toDomain($model);
    }

    public function exists(JobId $jobId): bool
    {
        return JobLedgerModel::query()
            ->where('id', $jobId->value)
            ->exists();
    }

    public function existsByJobUuid(string $jobUuid): bool
    {
        return JobLedgerModel::query()
            ->byJobUuid($jobUuid)
            ->exists();
    }

    public function findRunningByStepRunId(StepRunId $stepRunId): JobRecordCollection
    {
        return $this->findByStepRunIdAndState($stepRunId, JobState::Running);
    }

    public function findInProgressByStepRunId(StepRunId $stepRunId): JobRecordCollection
    {
        $models = JobLedgerModel::query()
            ->forStepRun($stepRunId->value)
            ->inProgress()
            ->get();

        return new JobRecordCollection($this->hydrateModels($models->all()));
    }

    public function updateStatusAtomically(JobId $jobId, JobState $fromState, JobState $toState): bool
    {
        $affected = JobLedgerModel::query()
            ->where('id', $jobId->value)
            ->where('status', $fromState->value)
            ->update(['status' => $toState->value]);

        return $affected > 0;
    }

    public function areAllJobsTerminalForStepRun(StepRunId $stepRunId): bool
    {
        $inProgressCount = JobLedgerModel::query()
            ->forStepRun($stepRunId->value)
            ->inProgress()
            ->count();

        return $inProgressCount === 0;
    }

    public function findZombieJobs(CarbonImmutable $threshold): JobRecordCollection
    {
        $models = JobLedgerModel::query()
            ->zombies($threshold)
            ->get();

        return new JobRecordCollection($this->hydrateModels($models->all()));
    }

    public function findStaleDispatchedJobs(CarbonImmutable $threshold): JobRecordCollection
    {
        $models = JobLedgerModel::query()
            ->staleDispatched($threshold)
            ->get();

        return new JobRecordCollection($this->hydrateModels($models->all()));
    }

    /**
     * @param array<int|string, JobLedgerModel> $models
     *
     * @return list<JobRecord>
     */
    private function hydrateModels(array $models): array
    {
        $result = [];
        foreach ($models as $model) {
            $result[] = $this->hydrator->toDomain($model);
        }

        return $result;
    }
}
