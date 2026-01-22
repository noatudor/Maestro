<?php

declare(strict_types=1);

namespace Maestro\Workflow\Infrastructure\Persistence\Hydrators;

use Maestro\Workflow\Contracts\BranchCondition;
use Maestro\Workflow\Domain\BranchDecisionRecord;
use Maestro\Workflow\Exceptions\InvalidBranchKeyException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Infrastructure\Persistence\Models\BranchDecisionModel;
use Maestro\Workflow\ValueObjects\BranchDecisionId;
use Maestro\Workflow\ValueObjects\BranchKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

final readonly class BranchDecisionHydrator
{
    /**
     * @throws InvalidStepKeyException
     * @throws InvalidBranchKeyException
     */
    public function toDomain(BranchDecisionModel $branchDecisionModel): BranchDecisionRecord
    {
        /** @var class-string<BranchCondition> $conditionClass */
        $conditionClass = $branchDecisionModel->condition_class;

        return BranchDecisionRecord::reconstitute(
            workflowId: WorkflowId::fromString($branchDecisionModel->workflow_id),
            conditionClass: $conditionClass,
            selectedBranches: $this->extractSelectedBranches($branchDecisionModel),
            evaluatedAt: $branchDecisionModel->evaluated_at,
            inputSummary: $branchDecisionModel->input_summary,
            createdAt: $branchDecisionModel->created_at,
            id: BranchDecisionId::fromString($branchDecisionModel->id),
            branchPointKey: StepKey::fromString($branchDecisionModel->branch_point_key),
        );
    }

    public function fromDomain(BranchDecisionRecord $branchDecisionRecord): BranchDecisionModel
    {
        $model = new BranchDecisionModel();
        $model->id = $branchDecisionRecord->id->value;
        $model->workflow_id = $branchDecisionRecord->workflowId->value;
        $model->branch_point_key = $branchDecisionRecord->branchPointKey->value;
        $model->condition_class = $branchDecisionRecord->conditionClass;
        $model->selected_branches = $branchDecisionRecord->selectedBranchKeys();
        $model->evaluated_at = $branchDecisionRecord->evaluatedAt;
        $model->input_summary = $branchDecisionRecord->inputSummary;
        $model->created_at = $branchDecisionRecord->createdAt;

        return $model;
    }

    /**
     * @return list<BranchKey>
     *
     * @throws InvalidBranchKeyException
     */
    private function extractSelectedBranches(BranchDecisionModel $branchDecisionModel): array
    {
        $branches = [];

        foreach ($branchDecisionModel->selected_branches as $branchKey) {
            $branches[] = BranchKey::fromString($branchKey);
        }

        return $branches;
    }
}
