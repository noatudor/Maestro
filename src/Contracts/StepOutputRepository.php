<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Repository for storing and retrieving step outputs.
 *
 * Implementations must handle concurrent access for MergeableOutput types
 * in fan-out scenarios where multiple jobs may write simultaneously.
 */
interface StepOutputRepository
{
    /**
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T|null
     */
    public function find(WorkflowId $workflowId, string $outputClass): ?StepOutput;

    /**
     * Find and lock an output row for update using SELECT FOR UPDATE.
     *
     * This is used for atomic merge operations in fan-out scenarios.
     *
     * @template T of StepOutput
     *
     * @param class-string<T> $outputClass
     *
     * @return T|null
     */
    public function findForUpdate(WorkflowId $workflowId, string $outputClass): ?StepOutput;

    /**
     * @param class-string<StepOutput> $outputClass
     */
    public function has(WorkflowId $workflowId, string $outputClass): bool;

    public function save(WorkflowId $workflowId, StepOutput $stepOutput): void;

    /**
     * Atomically merge and save a MergeableOutput.
     *
     * This uses pessimistic locking (SELECT FOR UPDATE) and transactions to
     * ensure atomic read-modify-write operations for MergeableOutput in
     * fan-out scenarios where multiple jobs may complete concurrently.
     */
    public function saveWithAtomicMerge(WorkflowId $workflowId, MergeableOutput $mergeableOutput): void;

    /**
     * @return list<StepOutput>
     */
    public function findAllByWorkflowId(WorkflowId $workflowId): array;

    public function deleteByWorkflowId(WorkflowId $workflowId): void;
}
