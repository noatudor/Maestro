<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;

/**
 * Listens for Laravel queue job failure events.
 *
 * When an orchestrated job fails (after exhausting retries),
 * triggers the workflow advancer to evaluate and handle the failure.
 */
final readonly class JobFailedListener
{
    public function __construct(
        private WorkflowAdvancer $advancer,
    ) {}

    /**
     * @throws \Maestro\Workflow\Exceptions\WorkflowNotFoundException
     * @throws \Maestro\Workflow\Exceptions\WorkflowLockedException
     * @throws \Maestro\Workflow\Exceptions\DefinitionNotFoundException
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    public function handle(JobFailed $event): void
    {
        $job = $this->extractOrchestratedJob($event);

        if ($job === null) {
            return;
        }

        $this->advancer->evaluate($job->workflowId);
    }

    private function extractOrchestratedJob(JobFailed $event): ?OrchestratedJob
    {
        $payload = $event->job->payload();

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
