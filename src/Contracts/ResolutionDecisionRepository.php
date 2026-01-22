<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Domain\ResolutionDecisionRecord;
use Maestro\Workflow\ValueObjects\ResolutionDecisionId;
use Maestro\Workflow\ValueObjects\WorkflowId;

interface ResolutionDecisionRepository
{
    public function find(ResolutionDecisionId $resolutionDecisionId): ?ResolutionDecisionRecord;

    public function save(ResolutionDecisionRecord $resolutionDecisionRecord): void;

    /**
     * Find all resolution decisions for a workflow, ordered by creation time (newest first).
     *
     * @return list<ResolutionDecisionRecord>
     */
    public function findByWorkflow(WorkflowId $workflowId): array;

    /**
     * Find the most recent resolution decision for a workflow.
     */
    public function findLatestByWorkflow(WorkflowId $workflowId): ?ResolutionDecisionRecord;

    /**
     * Count resolution decisions for a workflow.
     */
    public function countByWorkflow(WorkflowId $workflowId): int;

    /**
     * Delete all resolution decisions for a workflow.
     */
    public function deleteByWorkflow(WorkflowId $workflowId): int;
}
