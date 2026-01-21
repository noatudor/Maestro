<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;

/**
 * Listens for Laravel queue job failure events.
 *
 * When an orchestrated job fails (after exhausting retries),
 * triggers the workflow advancer to evaluate and handle the failure.
 */
final readonly class JobFailedListener
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
    public function handle(JobFailed $jobFailed): void
    {
        $job = $this->extractOrchestratedJob($jobFailed);

        if (! $job instanceof OrchestratedJob) {
            return;
        }

        $this->workflowAdvancer->evaluate($job->workflowId);
    }

    private function extractOrchestratedJob(JobFailed $jobFailed): ?OrchestratedJob
    {
        $payload = $jobFailed->job->payload();

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
