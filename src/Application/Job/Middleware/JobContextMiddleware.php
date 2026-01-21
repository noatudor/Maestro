<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Job\Middleware;

use Closure;
use Maestro\Workflow\Application\Context\WorkflowContextProviderFactory;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;

/**
 * Middleware that injects workflow context and output store into orchestrated jobs.
 *
 * Loads the workflow definition, creates context provider and output store,
 * and injects them into the job before execution.
 */
final readonly class JobContextMiddleware
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private WorkflowContextProviderFactory $workflowContextProviderFactory,
        private StepOutputStoreFactory $stepOutputStoreFactory,
    ) {}

    /**
     * Handle the job execution with context injection.
     *
     * @param Closure(OrchestratedJob): void $next
     *
     * @throws WorkflowNotFoundException
     * @throws DefinitionNotFoundException
     */
    public function handle(OrchestratedJob $orchestratedJob, Closure $next): void
    {
        $workflowInstance = $this->workflowRepository->findOrFail($orchestratedJob->workflowId);

        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $workflowContextProvider = $this->workflowContextProviderFactory->forWorkflow(
            $orchestratedJob->workflowId,
            $workflowDefinition,
        );

        $stepOutputStore = $this->stepOutputStoreFactory->forWorkflow($orchestratedJob->workflowId);

        $orchestratedJob->setContextProvider($workflowContextProvider);
        $orchestratedJob->setOutputStore($stepOutputStore);

        $next($orchestratedJob);
    }
}
