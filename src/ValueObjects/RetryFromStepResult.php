<?php

declare(strict_types=1);

namespace Maestro\Workflow\ValueObjects;

use Maestro\Workflow\Domain\WorkflowInstance;

/**
 * Result of a retry-from-step operation.
 */
final readonly class RetryFromStepResult
{
    /**
     * @param list<StepRunId> $supersededStepRunIds
     * @param list<StepKey> $clearedOutputStepKeys
     */
    private function __construct(
        public WorkflowInstance $workflowInstance,
        public StepKey $retryFromStepKey,
        public StepRunId $newStepRunId,
        public array $supersededStepRunIds,
        public array $clearedOutputStepKeys,
        public bool $compensationExecuted,
    ) {}

    /**
     * @param list<StepRunId> $supersededStepRunIds
     * @param list<StepKey> $clearedOutputStepKeys
     */
    public static function create(
        WorkflowInstance $workflowInstance,
        StepKey $retryFromStepKey,
        StepRunId $newStepRunId,
        array $supersededStepRunIds,
        array $clearedOutputStepKeys,
        bool $compensationExecuted = false,
    ): self {
        return new self(
            workflowInstance: $workflowInstance,
            retryFromStepKey: $retryFromStepKey,
            newStepRunId: $newStepRunId,
            supersededStepRunIds: $supersededStepRunIds,
            clearedOutputStepKeys: $clearedOutputStepKeys,
            compensationExecuted: $compensationExecuted,
        );
    }

    public function supersededCount(): int
    {
        return count($this->supersededStepRunIds);
    }

    public function clearedOutputCount(): int
    {
        return count($this->clearedOutputStepKeys);
    }
}
