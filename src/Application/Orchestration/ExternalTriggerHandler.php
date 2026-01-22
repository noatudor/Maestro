<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Handles external triggers that can advance paused workflows.
 *
 * External triggers include:
 * - Webhooks from external services
 * - User approvals
 * - Timer-based triggers
 * - API calls from third-party systems
 */
final readonly class ExternalTriggerHandler
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private WorkflowAdvancer $workflowAdvancer,
    ) {}

    /**
     * Handle an external trigger for a workflow.
     *
     * If the workflow is paused, it will be resumed and advanced.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     */
    public function handleTrigger(
        WorkflowId $workflowId,
        string $triggerType,
        ?TriggerPayload $triggerPayload = null,
    ): TriggerResult {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        if ($workflowInstance->isTerminal()) {
            return TriggerResult::workflowTerminal($workflowInstance);
        }

        if ($workflowInstance->isPaused()) {
            try {
                $workflowInstance->resume();
                $this->workflowRepository->save($workflowInstance);
            } catch (InvalidStateTransitionException $e) {
                return TriggerResult::transitionFailed($workflowInstance, $e->getMessage());
            }
        }

        $this->workflowAdvancer->evaluate($workflowId);

        $updatedWorkflow = $this->workflowRepository->findOrFail($workflowId);

        return TriggerResult::success($updatedWorkflow, $triggerType, $triggerPayload);
    }

    /**
     * Resume and advance a paused workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     */
    public function resumeAndAdvance(WorkflowId $workflowId): WorkflowInstance
    {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        if ($workflowInstance->isPaused()) {
            $workflowInstance->resume();
            $this->workflowRepository->save($workflowInstance);
        }

        $this->workflowAdvancer->evaluate($workflowId);

        return $this->workflowRepository->findOrFail($workflowId);
    }

    /**
     * Trigger workflow evaluation without state change.
     *
     * Useful for checking if a workflow can proceed after external conditions change.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     */
    public function triggerEvaluation(WorkflowId $workflowId): WorkflowInstance
    {
        $this->workflowRepository->findOrFail($workflowId);
        $this->workflowAdvancer->evaluate($workflowId);

        return $this->workflowRepository->findOrFail($workflowId);
    }
}
