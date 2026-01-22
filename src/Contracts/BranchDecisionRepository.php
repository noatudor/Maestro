<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Domain\BranchDecisionRecord;
use Maestro\Workflow\ValueObjects\BranchDecisionId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

interface BranchDecisionRepository
{
    /**
     * Save a branch decision record.
     */
    public function save(BranchDecisionRecord $branchDecisionRecord): void;

    /**
     * Find a branch decision by its ID.
     */
    public function find(BranchDecisionId $branchDecisionId): ?BranchDecisionRecord;

    /**
     * Find a branch decision by workflow and branch point.
     */
    public function findByWorkflowAndBranchPoint(
        WorkflowId $workflowId,
        StepKey $stepKey,
    ): ?BranchDecisionRecord;

    /**
     * Find all branch decisions for a workflow.
     *
     * @return list<BranchDecisionRecord>
     */
    public function findAllByWorkflowId(WorkflowId $workflowId): array;

    /**
     * Delete all branch decisions for a workflow.
     */
    public function deleteByWorkflowId(WorkflowId $workflowId): void;
}
