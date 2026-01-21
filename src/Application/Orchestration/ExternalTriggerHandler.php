<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
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
        private WorkflowAdvancer $advancer,
    ) {}

    /**
     * Handle an external trigger for a workflow.
     *
     * If the workflow is paused, it will be resumed and advanced.
     *
     * @param array<string, mixed> $payload Optional payload data from the trigger
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws \Maestro\Workflow\Exceptions\DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    public function handleTrigger(
        WorkflowId $workflowId,
        string $triggerType,
        array $payload = [],
    ): TriggerResult {
        $workflow = $this->workflowRepository->findOrFail($workflowId);

        if ($workflow->isTerminal()) {
            return TriggerResult::workflowTerminal($workflow);
        }

        if ($workflow->isPaused()) {
            try {
                $workflow->resume();
                $this->workflowRepository->save($workflow);
            } catch (InvalidStateTransitionException $e) {
                return TriggerResult::transitionFailed($workflow, $e->getMessage());
            }
        }

        $this->advancer->evaluate($workflowId);

        $updatedWorkflow = $this->workflowRepository->findOrFail($workflowId);

        return TriggerResult::success($updatedWorkflow, $triggerType);
    }

    /**
     * Resume and advance a paused workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws \Maestro\Workflow\Exceptions\DefinitionNotFoundException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    public function resumeAndAdvance(WorkflowId $workflowId): WorkflowInstance
    {
        $workflow = $this->workflowRepository->findOrFail($workflowId);

        if ($workflow->isPaused()) {
            $workflow->resume();
            $this->workflowRepository->save($workflow);
        }

        $this->advancer->evaluate($workflowId);

        return $this->workflowRepository->findOrFail($workflowId);
    }

    /**
     * Trigger workflow evaluation without state change.
     *
     * Useful for checking if a workflow can proceed after external conditions change.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws \Maestro\Workflow\Exceptions\DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    public function triggerEvaluation(WorkflowId $workflowId): WorkflowInstance
    {
        $this->workflowRepository->findOrFail($workflowId);
        $this->advancer->evaluate($workflowId);

        return $this->workflowRepository->findOrFail($workflowId);
    }
}
