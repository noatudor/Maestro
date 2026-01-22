<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Repositories;

use Maestro\Workflow\Contracts\BranchDecisionRepository;
use Maestro\Workflow\Domain\BranchDecisionRecord;
use Maestro\Workflow\Exceptions\InvalidBranchKeyException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Infrastructure\Persistence\Hydrators\BranchDecisionHydrator;
use Maestro\Workflow\Infrastructure\Persistence\Models\BranchDecisionModel;
use Maestro\Workflow\ValueObjects\BranchDecisionId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class EloquentBranchDecisionRepository implements BranchDecisionRepository
{
    public function __construct(
        private BranchDecisionHydrator $branchDecisionHydrator,
    ) {}

    public function save(BranchDecisionRecord $branchDecisionRecord): void
    {
        $branchDecisionModel = $this->branchDecisionHydrator->fromDomain($branchDecisionRecord);
        $branchDecisionModel->save();
    }

    /**
     * @throws InvalidBranchKeyException
     * @throws InvalidStepKeyException
     */
    public function find(BranchDecisionId $branchDecisionId): ?BranchDecisionRecord
    {
        $model = BranchDecisionModel::find($branchDecisionId->value);

        if (! $model instanceof BranchDecisionModel) {
            return null;
        }

        return $this->branchDecisionHydrator->toDomain($model);
    }

    /**
     * @throws InvalidBranchKeyException
     * @throws InvalidStepKeyException
     */
    public function findByWorkflowAndBranchPoint(
        WorkflowId $workflowId,
        StepKey $stepKey,
    ): ?BranchDecisionRecord {
        $model = BranchDecisionModel::query()
            ->where('workflow_id', $workflowId->value)
            ->where('branch_point_key', $stepKey->value)
            ->orderByDesc('evaluated_at')
            ->first();

        if (! $model instanceof BranchDecisionModel) {
            return null;
        }

        return $this->branchDecisionHydrator->toDomain($model);
    }

    /**
     * @return list<BranchDecisionRecord>
     *
     * @throws InvalidBranchKeyException
     * @throws InvalidStepKeyException
     */
    public function findAllByWorkflowId(WorkflowId $workflowId): array
    {
        $models = BranchDecisionModel::query()
            ->where('workflow_id', $workflowId->value)
            ->orderBy('evaluated_at')
            ->get();

        $decisions = [];

        foreach ($models as $model) {
            $decisions[] = $this->branchDecisionHydrator->toDomain($model);
        }

        return $decisions;
    }

    public function deleteByWorkflowId(WorkflowId $workflowId): void
    {
        BranchDecisionModel::query()
            ->where('workflow_id', $workflowId->value)
            ->delete();
    }
}
