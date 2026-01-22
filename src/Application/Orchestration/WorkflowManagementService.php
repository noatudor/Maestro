<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\WorkflowCancelled;
use Maestro\Workflow\Domain\Events\WorkflowCreated;
use Maestro\Workflow\Domain\Events\WorkflowPaused;
use Maestro\Workflow\Domain\Events\WorkflowResumed;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\ResolutionDecision;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\StepNotFoundException;
use Maestro\Workflow\Exceptions\WorkflowAlreadyCancelledException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFailedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\DefinitionKey;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Service for manual workflow management operations.
 *
 * Provides the public API for starting, pausing, resuming,
 * cancelling, and retrying workflows.
 */
final readonly class WorkflowManagementService implements WorkflowManager
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private WorkflowAdvancer $workflowAdvancer,
        private FailureResolutionHandler $failureResolutionHandler,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Start a new workflow instance.
     *
     * @throws DefinitionNotFoundException
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     */
    public function startWorkflow(
        DefinitionKey $definitionKey,
        ?WorkflowId $workflowId = null,
    ): WorkflowInstance {
        $workflowDefinition = $this->workflowDefinitionRegistry->getLatest($definitionKey);

        $workflowInstance = WorkflowInstance::create(
            definitionKey: $workflowDefinition->key(),
            definitionVersion: $workflowDefinition->version(),
            workflowId: $workflowId,
        );

        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowCreated(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            occurredAt: CarbonImmutable::now(),
        ));

        $this->workflowAdvancer->evaluate($workflowInstance->id);

        return $workflowInstance;
    }

    /**
     * Pause a running workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws InvalidStateTransitionException
     */
    public function pauseWorkflow(WorkflowId $workflowId, ?string $reason = null): WorkflowInstance
    {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);
        $workflowInstance->pause($reason);
        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowPaused(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            reason: $reason,
            occurredAt: CarbonImmutable::now(),
        ));

        return $workflowInstance;
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
    public function resumeWorkflow(WorkflowId $workflowId): WorkflowInstance
    {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);
        $workflowInstance->resume();
        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowResumed(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            occurredAt: CarbonImmutable::now(),
        ));

        $this->workflowAdvancer->evaluate($workflowId);

        return $workflowInstance;
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
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);
        $workflowInstance->cancel();
        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowCancelled(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            occurredAt: CarbonImmutable::now(),
        ));

        return $workflowInstance;
    }

    /**
     * Retry a failed workflow from the failed step.
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     */
    public function retryWorkflow(WorkflowId $workflowId): WorkflowInstance
    {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);
        $workflowInstance->retry();
        $this->workflowRepository->save($workflowInstance);

        $this->workflowAdvancer->evaluate($workflowId);

        return $workflowInstance;
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

    /**
     * Resolve a failed workflow by applying a resolution decision.
     *
     * @param list<StepKey>|null $compensateStepKeys
     *
     * @throws WorkflowNotFoundException
     * @throws WorkflowNotFailedException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     * @throws WorkflowLockedException
     * @throws WorkflowAlreadyCancelledException
     * @throws StepNotFoundException
     * @throws ConditionEvaluationException
     */
    public function resolveFailure(
        WorkflowId $workflowId,
        ResolutionDecision $resolutionDecision,
        ?string $decidedBy = null,
        ?string $reason = null,
        ?StepKey $retryFromStepKey = null,
        ?array $compensateStepKeys = null,
    ): WorkflowInstance {
        return $this->failureResolutionHandler->applyDecision(
            workflowId: $workflowId,
            decidedBy: $decidedBy,
            reason: $reason,
            retryFromStepKey: $retryFromStepKey,
            compensateStepKeys: $compensateStepKeys,
            decision: $resolutionDecision,
        );
    }
}
