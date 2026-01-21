<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Domain\WorkflowInstance;

/**
 * Result of an external trigger operation.
 */
final readonly class TriggerResult
{
    private function __construct(
        private bool $success,
        private WorkflowInstance $workflowInstance,
        private ?string $triggerType,
        private ?string $failureReason,
    ) {}

    public static function success(WorkflowInstance $workflowInstance, string $triggerType): self
    {
        return new self(true, $workflowInstance, $triggerType, null);
    }

    public static function workflowTerminal(WorkflowInstance $workflowInstance): self
    {
        return new self(
            false,
            $workflowInstance,
            null,
            sprintf('Workflow is in terminal state: %s', $workflowInstance->state()->value),
        );
    }

    public static function transitionFailed(WorkflowInstance $workflowInstance, string $reason): self
    {
        return new self(false, $workflowInstance, null, $reason);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isTerminal(): bool
    {
        return $this->workflowInstance->isTerminal();
    }

    public function workflow(): WorkflowInstance
    {
        return $this->workflowInstance;
    }

    public function triggerType(): ?string
    {
        return $this->triggerType;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }
}
