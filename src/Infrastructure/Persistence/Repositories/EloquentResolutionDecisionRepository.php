<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Maestro\Workflow\Contracts\ResolutionDecisionRepository;
use Maestro\Workflow\Domain\ResolutionDecisionRecord;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\ResolutionDecisionHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Models\ResolutionDecisionModel;
use Maestro\Workflow\ValueObjects\ResolutionDecisionId;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class EloquentResolutionDecisionRepository implements ResolutionDecisionRepository
{
    public function __construct(
        private ResolutionDecisionHydrator $resolutionDecisionHydrator,
    ) {}

    /**
     * @throws InvalidStepKeyException
     */
    public function find(ResolutionDecisionId $resolutionDecisionId): ?ResolutionDecisionRecord
    {
        $model = ResolutionDecisionModel::query()->find($resolutionDecisionId->value);

        if ($model === null) {
            return null;
        }

        return $this->resolutionDecisionHydrator->toDomain($model);
    }

    public function save(ResolutionDecisionRecord $resolutionDecisionRecord): void
    {
        $existingModel = ResolutionDecisionModel::query()->find($resolutionDecisionRecord->id->value);

        if ($existingModel !== null) {
            $this->resolutionDecisionHydrator->updateFromDomain($existingModel, $resolutionDecisionRecord);
            $existingModel->save();

            return;
        }

        $resolutionDecisionModel = $this->resolutionDecisionHydrator->fromDomain($resolutionDecisionRecord);
        $resolutionDecisionModel->save();
    }

    /**
     * @return list<ResolutionDecisionRecord>
     *
     * @throws InvalidStepKeyException
     */
    public function findByWorkflow(WorkflowId $workflowId): array
    {
        $models = ResolutionDecisionModel::query()
            ->where('workflow_id', $workflowId->value)
            ->orderByDesc('created_at')
            ->get();

        $records = [];
        foreach ($models as $model) {
            $records[] = $this->resolutionDecisionHydrator->toDomain($model);
        }

        return $records;
    }

    /**
     * @throws InvalidStepKeyException
     */
    public function findLatestByWorkflow(WorkflowId $workflowId): ?ResolutionDecisionRecord
    {
        $model = ResolutionDecisionModel::query()
            ->where('workflow_id', $workflowId->value)
            ->orderByDesc('created_at')
            ->first();

        if ($model === null) {
            return null;
        }

        return $this->resolutionDecisionHydrator->toDomain($model);
    }

    public function countByWorkflow(WorkflowId $workflowId): int
    {
        return ResolutionDecisionModel::query()
            ->where('workflow_id', $workflowId->value)
            ->count();
    }

    public function deleteByWorkflow(WorkflowId $workflowId): int
    {
        /** @var int */
        return ResolutionDecisionModel::query()
            ->where('workflow_id', $workflowId->value)
            ->delete();
    }
}
