<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Maestro\Workflow\Contracts\PollAttemptRepository;
use Maestro\Workflow\Domain\PollAttempt;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\PollAttemptHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Models\PollAttemptModel;
use Maestro\Workflow\ValueObjects\PollAttemptId;
use Maestro\Workflow\ValueObjects\StepRunId;

final readonly class EloquentPollAttemptRepository implements PollAttemptRepository
{
    public function __construct(
        private PollAttemptHydrator $pollAttemptHydrator,
    ) {}

    public function find(PollAttemptId $pollAttemptId): ?PollAttempt
    {
        $model = PollAttemptModel::query()->find($pollAttemptId->value);

        if ($model === null) {
            return null;
        }

        return $this->pollAttemptHydrator->toDomain($model);
    }

    public function save(PollAttempt $pollAttempt): void
    {
        $existingModel = PollAttemptModel::query()->find($pollAttempt->id->value);

        if ($existingModel !== null) {
            $this->pollAttemptHydrator->updateFromDomain($existingModel, $pollAttempt);
            $existingModel->save();

            return;
        }

        $pollAttemptModel = $this->pollAttemptHydrator->fromDomain($pollAttempt);
        $pollAttemptModel->save();
    }

    /**
     * @return list<PollAttempt>
     */
    public function findByStepRun(StepRunId $stepRunId): array
    {
        $models = PollAttemptModel::query()
            ->where('step_run_id', $stepRunId->value)
            ->orderBy('attempt_number')
            ->get();

        $attempts = [];
        foreach ($models as $model) {
            $attempts[] = $this->pollAttemptHydrator->toDomain($model);
        }

        return $attempts;
    }

    public function findLatestByStepRun(StepRunId $stepRunId): ?PollAttempt
    {
        $model = PollAttemptModel::query()
            ->where('step_run_id', $stepRunId->value)
            ->orderByDesc('attempt_number')
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->pollAttemptHydrator->toDomain($model);
    }

    public function countByStepRun(StepRunId $stepRunId): int
    {
        return PollAttemptModel::query()
            ->where('step_run_id', $stepRunId->value)
            ->count();
    }

    public function deleteByStepRun(StepRunId $stepRunId): int
    {
        /** @var int */
        return PollAttemptModel::query()
            ->where('step_run_id', $stepRunId->value)
            ->delete();
    }
}
