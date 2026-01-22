<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Responses;

use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;

/**
 * Data transfer object for workflow status API responses.
 */
final readonly class WorkflowStatusDTO
{
    private function __construct(
        public string $id,
        public string $definitionKey,
        public string $definitionVersion,
        public WorkflowState $state,
        public ?string $currentStepKey,
        public ?string $pausedReason,
        public ?string $failureCode,
        public ?string $failureMessage,
        public string $createdAt,
        public string $updatedAt,
        public ?string $pausedAt,
        public ?string $failedAt,
        public ?string $succeededAt,
        public ?string $cancelledAt,
        public bool $isTerminal,
        public bool $isLocked,
    ) {}

    public static function fromWorkflowInstance(WorkflowInstance $workflowInstance): self
    {
        return new self(
            id: $workflowInstance->id->value,
            definitionKey: $workflowInstance->definitionKey->value,
            definitionVersion: $workflowInstance->definitionVersion->toString(),
            state: $workflowInstance->state(),
            currentStepKey: $workflowInstance->currentStepKey()?->value,
            pausedReason: $workflowInstance->pausedReason(),
            failureCode: $workflowInstance->failureCode(),
            failureMessage: $workflowInstance->failureMessage(),
            createdAt: $workflowInstance->createdAt->toIso8601String(),
            updatedAt: $workflowInstance->updatedAt()->toIso8601String(),
            pausedAt: $workflowInstance->pausedAt()?->toIso8601String(),
            failedAt: $workflowInstance->failedAt()?->toIso8601String(),
            succeededAt: $workflowInstance->succeededAt()?->toIso8601String(),
            cancelledAt: $workflowInstance->cancelledAt()?->toIso8601String(),
            isTerminal: $workflowInstance->isTerminal(),
            isLocked: $workflowInstance->isLocked(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'definition_key' => $this->definitionKey,
            'definition_version' => $this->definitionVersion,
            'state' => $this->state->value,
            'current_step_key' => $this->currentStepKey,
            'paused_reason' => $this->pausedReason,
            'failure_code' => $this->failureCode,
            'failure_message' => $this->failureMessage,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'paused_at' => $this->pausedAt,
            'failed_at' => $this->failedAt,
            'succeeded_at' => $this->succeededAt,
            'cancelled_at' => $this->cancelledAt,
            'is_terminal' => $this->isTerminal,
            'is_locked' => $this->isLocked,
        ];
    }
}
