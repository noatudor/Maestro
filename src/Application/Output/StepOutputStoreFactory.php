<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Output;

use Maestro\Workflow\Contracts\StepOutputRepository;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Factory for creating workflow-scoped StepOutputStore instances.
 */
final readonly class StepOutputStoreFactory
{
    public function __construct(
        private StepOutputRepository $repository,
    ) {}

    public function forWorkflow(WorkflowId $workflowId): StepOutputStore
    {
        return new StepOutputStore($workflowId, $this->repository);
    }
}
