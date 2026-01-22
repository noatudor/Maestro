<?php

declare(strict_types=1);

namespace Maestro\Workflow;

use Maestro\Workflow\Application\Orchestration\ExternalTriggerHandler;
use Maestro\Workflow\Application\Orchestration\TriggerResult;
use Maestro\Workflow\Application\Query\WorkflowQueryService;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Domain\Collections\JobRecordCollection;
use Maestro\Workflow\Domain\Collections\StepRunCollection;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidDefinitionKeyException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\WorkflowAlreadyCancelledException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\Http\Responses\WorkflowDetailDTO;
use Maestro\Workflow\Http\Responses\WorkflowListDTO;
use Maestro\Workflow\Http\Responses\WorkflowStatusDTO;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\TriggerPayload;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Main entry point for the Maestro workflow orchestration package.
 *
 * This class provides a unified API for workflow management and querying.
 */
final readonly class Maestro
{
    public function __construct(
        private WorkflowManager $workflowManager,
        private WorkflowQueryService $workflowQueryService,
        private ExternalTriggerHandler $externalTriggerHandler,
    ) {}

    public function version(): string
    {
        return '1.0.0';
    }

    /**
     * Start a new workflow instance.
     *
     * @throws InvalidDefinitionKeyException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     */
    public function startWorkflow(
        string|DefinitionKey $definitionKey,
        string|WorkflowId|null $workflowId = null,
    ): WorkflowInstance {
        $definitionKey = $definitionKey instanceof DefinitionKey
            ? $definitionKey
            : DefinitionKey::fromString($definitionKey);

        $workflowId = $workflowId instanceof WorkflowId || $workflowId === null
            ? $workflowId
            : WorkflowId::fromString($workflowId);

        return $this->workflowManager->startWorkflow($definitionKey, $workflowId);
    }

    /**
     * Pause a running workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function pauseWorkflow(string|WorkflowId $workflowId, ?string $reason = null): WorkflowInstance
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->workflowManager->pauseWorkflow($workflowId, $reason);
    }

    /**
     * Resume a paused workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     */
    public function resumeWorkflow(string|WorkflowId $workflowId): WorkflowInstance
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->workflowManager->resumeWorkflow($workflowId);
    }

    /**
     * Cancel a workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     * @throws WorkflowAlreadyCancelledException
     */
    public function cancelWorkflow(string|WorkflowId $workflowId): WorkflowInstance
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->workflowManager->cancelWorkflow($workflowId);
    }

    /**
     * Retry a failed workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     */
    public function retryWorkflow(string|WorkflowId $workflowId): WorkflowInstance
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->workflowManager->retryWorkflow($workflowId);
    }

    /**
     * Get workflow status.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowStatus(string|WorkflowId $workflowId): WorkflowStatusDTO
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->workflowQueryService->getWorkflowStatus($workflowId);
    }

    /**
     * Get detailed workflow information including steps and jobs.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowDetail(string|WorkflowId $workflowId): WorkflowDetailDTO
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->workflowQueryService->getWorkflowDetail($workflowId);
    }

    /**
     * Get workflows by state.
     */
    public function getWorkflowsByState(WorkflowState $workflowState): WorkflowListDTO
    {
        return $this->workflowQueryService->getWorkflowsByState($workflowState);
    }

    /**
     * Get all running workflows.
     */
    public function getRunningWorkflows(): WorkflowListDTO
    {
        return $this->workflowQueryService->getRunningWorkflows();
    }

    /**
     * Get all paused workflows.
     */
    public function getPausedWorkflows(): WorkflowListDTO
    {
        return $this->workflowQueryService->getPausedWorkflows();
    }

    /**
     * Get all failed workflows.
     */
    public function getFailedWorkflows(): WorkflowListDTO
    {
        return $this->workflowQueryService->getFailedWorkflows();
    }

    /**
     * Get workflows by definition key.
     *
     * @throws InvalidDefinitionKeyException
     */
    public function getWorkflowsByDefinition(string|DefinitionKey $definitionKey): WorkflowListDTO
    {
        $definitionKey = $definitionKey instanceof DefinitionKey
            ? $definitionKey
            : DefinitionKey::fromString($definitionKey);

        return $this->workflowQueryService->getWorkflowsByDefinition($definitionKey);
    }

    /**
     * Get step runs for a workflow.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowSteps(string|WorkflowId $workflowId): StepRunCollection
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->workflowQueryService->getWorkflowSteps($workflowId);
    }

    /**
     * Get job records for a workflow.
     *
     * @throws WorkflowNotFoundException
     */
    public function getWorkflowJobs(string|WorkflowId $workflowId): JobRecordCollection
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->workflowQueryService->getWorkflowJobs($workflowId);
    }

    /**
     * Check if a workflow exists.
     */
    public function workflowExists(string|WorkflowId $workflowId): bool
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->workflowQueryService->workflowExists($workflowId);
    }

    /**
     * Handle an external trigger for a workflow.
     *
     * @param array<string, mixed>|TriggerPayload|null $payload
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     */
    public function trigger(
        string|WorkflowId $workflowId,
        string $triggerType,
        array|TriggerPayload|null $payload = null,
    ): TriggerResult {
        $workflowId = $this->resolveWorkflowId($workflowId);

        $triggerPayload = match (true) {
            $payload instanceof TriggerPayload => $payload,
            is_array($payload) => TriggerPayload::fromArray($payload),
            default => null,
        };

        return $this->externalTriggerHandler->handleTrigger($workflowId, $triggerType, $triggerPayload);
    }

    /**
     * Resume and advance a paused workflow via trigger.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     */
    public function resumeAndAdvance(string|WorkflowId $workflowId): WorkflowInstance
    {
        $workflowId = $this->resolveWorkflowId($workflowId);

        return $this->externalTriggerHandler->resumeAndAdvance($workflowId);
    }

    private function resolveWorkflowId(string|WorkflowId $workflowId): WorkflowId
    {
        return $workflowId instanceof WorkflowId
            ? $workflowId
            : WorkflowId::fromString($workflowId);
    }
}
