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
        private WorkflowInstance $workflow,
        private ?string $triggerType,
        private ?string $failureReason,
    ) {}

    public static function success(WorkflowInstance $workflow, string $triggerType): self
    {
        return new self(true, $workflow, $triggerType, null);
    }

    public static function workflowTerminal(WorkflowInstance $workflow): self
    {
        return new self(
            false,
            $workflow,
            null,
            sprintf('Workflow is in terminal state: %s', $workflow->state()->value),
        );
    }

    public static function transitionFailed(WorkflowInstance $workflow, string $reason): self
    {
        return new self(false, $workflow, null, $reason);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function workflow(): WorkflowInstance
    {
        return $this->workflow;
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
