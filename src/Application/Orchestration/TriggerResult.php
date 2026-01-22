<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\ValueObjects\TriggerPayload;

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
        private ?TriggerPayload $triggerPayload,
    ) {}

    public static function success(
        WorkflowInstance $workflowInstance,
        string $triggerType,
        ?TriggerPayload $triggerPayload = null,
    ): self {
        return new self(true, $workflowInstance, $triggerType, null, $triggerPayload);
    }

    public static function workflowTerminal(WorkflowInstance $workflowInstance): self
    {
        return new self(
            false,
            $workflowInstance,
            null,
            sprintf('Workflow is in terminal state: %s', $workflowInstance->state()->value),
            null,
        );
    }

    public static function transitionFailed(WorkflowInstance $workflowInstance, string $reason): self
    {
        return new self(false, $workflowInstance, null, $reason, null);
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

    public function payload(): ?TriggerPayload
    {
        return $this->triggerPayload;
    }
}
