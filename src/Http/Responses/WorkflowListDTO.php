<?php

declare(strict_types=1);

namespace Maestro\Workflow\Http\Responses;

use Maestro\Workflow\Domain\WorkflowInstance;

/**
 * Data transfer object for workflow list API responses.
 */
final readonly class WorkflowListDTO
{
    /**
     * @param list<WorkflowStatusDTO> $workflows
     */
    private function __construct(
        public array $workflows,
        public int $total,
    ) {}

    /**
     * @param list<WorkflowInstance> $workflows
     */
    public static function fromWorkflowInstances(array $workflows): self
    {
        $dtos = [];
        foreach ($workflows as $workflow) {
            $dtos[] = WorkflowStatusDTO::fromWorkflowInstance($workflow);
        }

        return new self(
            workflows: $dtos,
            total: count($dtos),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workflows' => array_map(
                static fn (WorkflowStatusDTO $workflowStatusDTO): array => $workflowStatusDTO->toArray(),
                $this->workflows,
            ),
            'total' => $this->total,
        ];
    }
}
