<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Context;

use Illuminate\Contracts\Container\Container;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Factory for creating workflow-scoped WorkflowContextProvider instances.
 */
final readonly class WorkflowContextProviderFactory
{
    public function __construct(
        private Container $container,
    ) {}

    public function forWorkflow(WorkflowId $workflowId, WorkflowDefinition $workflowDefinition): WorkflowContextProvider
    {
        return new WorkflowContextProvider($workflowId, $workflowDefinition, $this->container);
    }
}
