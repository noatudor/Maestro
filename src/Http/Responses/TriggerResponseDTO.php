<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Responses;

use Maestro\Workflow\Application\Orchestration\TriggerResult;

/**
 * Data transfer object for trigger API responses.
 */
final readonly class TriggerResponseDTO
{
    private function __construct(
        public bool $success,
        public WorkflowStatusDTO $workflow,
        public ?string $triggerType,
        public ?string $failureReason,
    ) {}

    public static function fromTriggerResult(TriggerResult $triggerResult): self
    {
        return new self(
            success: $triggerResult->isSuccess(),
            workflow: WorkflowStatusDTO::fromWorkflowInstance($triggerResult->workflow()),
            triggerType: $triggerResult->triggerType(),
            failureReason: $triggerResult->failureReason(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'workflow' => $this->workflow->toArray(),
            'trigger_type' => $this->triggerType,
            'failure_reason' => $this->failureReason,
        ];
    }
}
