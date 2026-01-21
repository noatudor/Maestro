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
        private WorkflowDefinitionRegistry $definitionRegistry,
        private WorkflowContextProviderFactory $contextProviderFactory,
        private StepOutputStoreFactory $outputStoreFactory,
    ) {}

    /**
     * Handle the job execution with context injection.
     *
     * @param Closure(OrchestratedJob): void $next
     *
     * @throws WorkflowNotFoundException
     * @throws DefinitionNotFoundException
     */
    public function handle(OrchestratedJob $job, Closure $next): void
    {
        $workflow = $this->workflowRepository->findOrFail($job->workflowId);

        $definition = $this->definitionRegistry->get(
            $workflow->definitionKey,
            $workflow->definitionVersion,
        );

        $contextProvider = $this->contextProviderFactory->forWorkflow(
            $job->workflowId,
            $definition,
        );

        $outputStore = $this->outputStoreFactory->forWorkflow($job->workflowId);

        $job->setContextProvider($contextProvider);
        $job->setOutputStore($outputStore);

        $next($job);
    }
}
