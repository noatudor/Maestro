<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration\Listeners;

use Illuminate\Queue\Events\JobProcessed;
use Maestro\Workflow\Application\Job\OrchestratedJob;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;

/**
 * Listens for Laravel queue job completion events.
 *
 * When an orchestrated job completes successfully, triggers the
 * workflow advancer to evaluate and potentially advance the workflow.
 */
final readonly class JobCompletedListener
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
    public function handle(JobProcessed $event): void
    {
        $job = $this->extractOrchestratedJob($event);

        if ($job === null) {
            return;
        }

        $this->advancer->evaluate($job->workflowId);
    }

    private function extractOrchestratedJob(JobProcessed $event): ?OrchestratedJob
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
