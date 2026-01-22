<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Domain\CompensationRun;
use Maestro\Workflow\Enums\CompensationRunStatus;
use Maestro\Workflow\ValueObjects\CompensationRunId;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

interface CompensationRunRepository
{
    public function find(CompensationRunId $compensationRunId): ?CompensationRun;

    public function save(CompensationRun $compensationRun): void;

    /**
     * Find all compensation runs for a workflow, ordered by execution order.
     *
     * @return list<CompensationRun>
     */
    public function findByWorkflow(WorkflowId $workflowId): array;

    /**
     * Find compensation run by workflow and step key.
     */
    public function findByWorkflowAndStep(WorkflowId $workflowId, StepKey $stepKey): ?CompensationRun;

    /**
     * Find compensation runs by status for a workflow.
     *
     * @return list<CompensationRun>
     */
    public function findByWorkflowAndStatus(WorkflowId $workflowId, CompensationRunStatus $compensationRunStatus): array;

    /**
     * Find the next pending compensation run for a workflow.
     *
     * Returns the compensation run with the lowest execution order that is in pending status.
     */
    public function findNextPending(WorkflowId $workflowId): ?CompensationRun;

    /**
     * Check if all compensation runs for a workflow are terminal (succeeded, failed, or skipped).
     */
    public function allTerminal(WorkflowId $workflowId): bool;

    /**
     * Check if all compensation runs for a workflow are successful (succeeded or skipped).
     */
    public function allSuccessful(WorkflowId $workflowId): bool;

    /**
     * Check if any compensation run for a workflow has failed.
     */
    public function anyFailed(WorkflowId $workflowId): bool;

    /**
     * Count compensation runs for a workflow.
     */
    public function countByWorkflow(WorkflowId $workflowId): int;

    /**
     * Delete all compensation runs for a workflow.
     */
    public function deleteByWorkflow(WorkflowId $workflowId): int;
}
