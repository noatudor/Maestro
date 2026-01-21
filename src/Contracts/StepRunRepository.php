<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Carbon\CarbonImmutable;
use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Enums\StepState;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

interface StepRunRepository
{
    public function find(StepRunId $stepRunId): ?StepRun;

    public function save(StepRun $stepRun): void;

    public function findByWorkflowId(WorkflowId $workflowId): StepRunCollection;

    public function findByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): ?StepRun;

    public function findLatestByWorkflowIdAndStepKey(WorkflowId $workflowId, StepKey $stepKey): ?StepRun;

    public function findByWorkflowIdAndState(WorkflowId $workflowId, StepState $state): StepRunCollection;

    /**
     * @throws \Maestro\Workflow\Exceptions\StepNotFoundException
     */
    public function findOrFail(StepRunId $stepRunId): StepRun;

    /**
     * Atomically update step status using conditional UPDATE.
     *
     * This prevents race conditions in fan-in scenarios where multiple workers
     * may try to finalize the same step simultaneously.
     *
     * @return bool True if the update was applied (this worker won the race), false otherwise
     */
    public function updateStatusAtomically(StepRunId $stepRunId, StepState $fromState, StepState $toState): bool;

    /**
     * Atomically finalize a step run to succeeded state.
     *
     * Only succeeds if the step is currently in RUNNING state.
     *
     * @return bool True if the finalization was applied, false if another worker already did it
     */
    public function finalizeAsSucceeded(StepRunId $stepRunId, CarbonImmutable $finishedAt): bool;

    /**
     * Atomically finalize a step run to failed state.
     *
     * Only succeeds if the step is currently in RUNNING state.
     *
     * @return bool True if the finalization was applied, false if another worker already did it
     */
    public function finalizeAsFailed(
        StepRunId $stepRunId,
        string $failureCode,
        string $failureMessage,
        int $failedJobCount,
        CarbonImmutable $finishedAt,
    ): bool;
}
