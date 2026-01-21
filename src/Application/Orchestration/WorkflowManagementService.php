<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowAlreadyCancelledException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Service for manual workflow management operations.
 *
 * Provides the public API for starting, pausing, resuming,
 * cancelling, and retrying workflows.
 */
final readonly class WorkflowManagementService
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private WorkflowDefinitionRegistry $definitionRegistry,
        private WorkflowAdvancer $advancer,
    ) {}

    /**
     * Start a new workflow instance.
     *
     * @throws DefinitionNotFoundException
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    public function startWorkflow(
        DefinitionKey $definitionKey,
        ?WorkflowId $workflowId = null,
    ): WorkflowInstance {
        $definition = $this->definitionRegistry->getLatest($definitionKey);

        $workflow = WorkflowInstance::create(
            definitionKey: $definition->key(),
            definitionVersion: $definition->version(),
            id: $workflowId,
        );

        $this->workflowRepository->save($workflow);

        $this->advancer->evaluate($workflow->id);

        return $workflow;
    }

    /**
     * Pause a running workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function pauseWorkflow(WorkflowId $workflowId, ?string $reason = null): WorkflowInstance
    {
        $workflow = $this->workflowRepository->findOrFail($workflowId);
        $workflow->pause($reason);
        $this->workflowRepository->save($workflow);

        return $workflow;
    }

    /**
     * Resume a paused workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    public function resumeWorkflow(WorkflowId $workflowId): WorkflowInstance
    {
        $workflow = $this->workflowRepository->findOrFail($workflowId);
        $workflow->resume();
        $this->workflowRepository->save($workflow);

        $this->advancer->evaluate($workflowId);

        return $workflow;
    }

    /**
     * Cancel a workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     * @throws WorkflowAlreadyCancelledException
     */
    public function cancelWorkflow(WorkflowId $workflowId): WorkflowInstance
    {
        $workflow = $this->workflowRepository->findOrFail($workflowId);
        $workflow->cancel();
        $this->workflowRepository->save($workflow);

        return $workflow;
    }

    /**
     * Retry a failed workflow from the failed step.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    public function retryWorkflow(WorkflowId $workflowId): WorkflowInstance
    {
        $workflow = $this->workflowRepository->findOrFail($workflowId);
        $workflow->retry();
        $this->workflowRepository->save($workflow);

        $this->advancer->evaluate($workflowId);

        return $workflow;
    }

    /**
     * Get the current status of a workflow.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowStatus(WorkflowId $workflowId): WorkflowInstance
    {
        return $this->workflowRepository->findOrFail($workflowId);
    }

    /**
     * Check if a workflow exists.
     */
    public function workflowExists(WorkflowId $workflowId): bool
    {
        return $this->workflowRepository->exists($workflowId);
    }
}
