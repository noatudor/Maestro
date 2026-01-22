<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Illuminate\Contracts\Queue\ShouldQueue;
use Maestro\Workflow\ValueObjects\StepRunId;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Contract for jobs that can be dispatched within a workflow.
 *
 * Both regular orchestrated jobs and polling jobs implement this interface,
 * allowing the JobDispatchService to work with either type.
 */
interface DispatchableWorkflowJob extends ShouldQueue
{
    /**
     * Get the workflow ID this job belongs to.
     */
    public function getWorkflowId(): WorkflowId;

    /**
     * Get the step run ID this job is part of.
     */
    public function getStepRunId(): StepRunId;

    /**
     * Get the unique job UUID.
     */
    public function getJobUuid(): string;

    /**
     * Get the correlation metadata for this job.
     *
     * @return array{workflow_id: string, step_run_id: string, job_uuid: string}
     */
    public function correlationMetadata(): array;
}
