<?php

declare(strict_types=1);

namespace Maestro\Workflow\Contracts;

use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\WorkflowAlreadyCancelledException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Interface for manual workflow management operations.
 */
interface WorkflowManager
{
    /**
     * Start a new workflow instance.
     *
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     */
    public function startWorkflow(DefinitionKey $definitionKey, ?WorkflowId $workflowId = null): WorkflowInstance;

    /**
     * Pause a running workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function pauseWorkflow(WorkflowId $workflowId, ?string $reason = null): WorkflowInstance;

    /**
     * Resume a paused workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     */
    public function resumeWorkflow(WorkflowId $workflowId): WorkflowInstance;

    /**
     * Cancel a workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     * @throws WorkflowAlreadyCancelledException
     */
    public function cancelWorkflow(WorkflowId $workflowId): WorkflowInstance;

    /**
     * Retry a failed workflow from the failed step.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     */
    public function retryWorkflow(WorkflowId $workflowId): WorkflowInstance;

    /**
     * Get the current status of a workflow.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowStatus(WorkflowId $workflowId): WorkflowInstance;

    /**
     * Check if a workflow exists.
     */
    public function workflowExists(WorkflowId $workflowId): bool;
}
