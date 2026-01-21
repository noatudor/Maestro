<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;

/**
 * Listens for Laravel queue job completion events.
 *
 * When an orchestrated job completes successfully, triggers the
 * workflow advancer to evaluate and potentially advance the workflow.
 */
final readonly class JobCompletedListener
{
    public function __construct(
        private WorkflowAdvancer $workflowAdvancer,
    ) {}

    /**
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     */
    public function handle(JobProcessed $jobProcessed): void
    {
        $job = $this->extractOrchestratedJob($jobProcessed);

        if (! $job instanceof OrchestratedJob) {
            return;
        }

        $this->workflowAdvancer->evaluate($job->workflowId);
    }

    private function extractOrchestratedJob(JobProcessed $jobProcessed): ?OrchestratedJob
    {
        $payload = $jobProcessed->job->payload();

        if (! isset($payload['data']['command'])) {
            return null;
        }

        $command = unserialize($payload['data']['command']);

        if (! $command instanceof OrchestratedJob) {
            return null;
        }

        return $command;
    }
}
