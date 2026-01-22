<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Contracts\ResolutionDecisionRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\Config\FailureResolutionConfig;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\AutoRetryExhausted;
use Maestro\Workflow\Domain\Events\AutoRetryScheduled;
use Maestro\Workflow\Domain\Events\ResolutionDecisionMade;
use Maestro\Workflow\Domain\Events\WorkflowAwaitingResolution;
use Maestro\Workflow\Domain\ResolutionDecisionRecord;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\CompensationScope;
use Maestro\Workflow\Enums\ResolutionDecision;
use Maestro\Workflow\Enums\RetryMode;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\StepNotFoundException;
use Maestro\Workflow\Exceptions\WorkflowAlreadyCancelledException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFailedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\RetryFromStepRequest;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Handles failure resolution strategies for workflows.
 *
 * This service is invoked after a workflow transitions to FAILED state
 * and applies the configured resolution strategy.
 */
final readonly class FailureResolutionHandler
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private ResolutionDecisionRepository $resolutionDecisionRepository,
        private WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private WorkflowAdvancer $workflowAdvancer,
        private RetryFromStepService $retryFromStepService,
        private CompensationExecutor $compensationExecutor,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Apply the configured failure resolution strategy for a failed workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws InvalidStepKeyException
     */
    public function applyStrategy(WorkflowId $workflowId): void
    {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        if (! $workflowInstance->isFailed()) {
            return;
        }

        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $failureResolutionConfig = $workflowDefinition->failureResolution();

        if ($failureResolutionConfig->autoRetries()) {
            $this->handleAutoRetryStrategy($workflowInstance, $failureResolutionConfig);

            return;
        }

        if ($failureResolutionConfig->autoCompensates()) {
            $this->handleAutoCompensateStrategy($workflowInstance);

            return;
        }

        $this->handleAwaitDecisionStrategy($workflowInstance);
    }

    /**
     * Apply a manual resolution decision to a failed workflow.
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
    public function applyDecision(
        WorkflowId $workflowId,
        ResolutionDecision $resolutionDecision,
        ?string $decidedBy = null,
        ?string $reason = null,
        ?StepKey $retryFromStepKey = null,
        ?array $compensateStepKeys = null,
    ): WorkflowInstance {
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        if (! $workflowInstance->isFailed()) {
            throw WorkflowNotFailedException::withId($workflowId);
        }

        $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $resolutionDecisionRecord = ResolutionDecisionRecord::create(
            workflowId: $workflowId,
            decidedBy: $decidedBy,
            reason: $reason,
            retryFromStepKey: $retryFromStepKey,
            compensateStepKeys: $compensateStepKeys,
            decisionType: $resolutionDecision,
        );

        $this->resolutionDecisionRepository->save($resolutionDecisionRecord);

        $this->eventDispatcher->dispatch(new ResolutionDecisionMade(
            decisionId: $resolutionDecisionRecord->id,
            workflowId: $workflowId,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            decision: $resolutionDecision,
            decidedBy: $decidedBy,
            reason: $reason,
            retryFromStepKey: $retryFromStepKey,
            compensateStepKeys: $compensateStepKeys !== null
                ? array_map(static fn (StepKey $stepKey): string => $stepKey->value, $compensateStepKeys)
                : null,
            occurredAt: CarbonImmutable::now(),
        ));

        return match ($resolutionDecision) {
            ResolutionDecision::Retry => $this->executeRetry($workflowInstance),
            ResolutionDecision::RetryFromStep => $this->executeRetryFromStep($workflowInstance, $retryFromStepKey),
            ResolutionDecision::Compensate => $this->executeCompensate($workflowInstance, $compensateStepKeys),
            ResolutionDecision::Cancel => $this->executeCancel($workflowInstance),
            ResolutionDecision::MarkResolved => $this->executeMarkResolved($workflowInstance),
        };
    }

    /**
     * Process workflows with due auto-retries.
     *
     * Called by the scheduled command to execute pending auto-retries.
     *
     * @return list<WorkflowId> IDs of workflows that were retried
     *
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws WorkflowLockedException
     * @throws WorkflowNotFoundException
     */
    public function processAutoRetries(): array
    {
        $retriedWorkflows = [];
        $failedWorkflows = $this->workflowRepository->findFailed();

        foreach ($failedWorkflows as $failedWorkflow) {
            if (! $failedWorkflow->isAutoRetryDue()) {
                continue;
            }

            $this->executeAutoRetry($failedWorkflow);
            $retriedWorkflows[] = $failedWorkflow->id;
        }

        return $retriedWorkflows;
    }

    /**
     * @throws DefinitionNotFoundException
     * @throws InvalidStepKeyException
     */
    private function handleAutoRetryStrategy(
        WorkflowInstance $workflowInstance,
        FailureResolutionConfig $failureResolutionConfig,
    ): void {
        $autoRetryConfig = $failureResolutionConfig->autoRetryConfig;

        if ($autoRetryConfig->hasReachedMaxRetries($workflowInstance->autoRetryCount())) {
            $this->eventDispatcher->dispatch(new AutoRetryExhausted(
                workflowId: $workflowInstance->id,
                definitionKey: $workflowInstance->definitionKey,
                definitionVersion: $workflowInstance->definitionVersion,
                failedStepKey: $workflowInstance->currentStepKey() ?? StepKey::fromString('unknown'),
                totalAttempts: $workflowInstance->autoRetryCount(),
                fallbackStrategy: $autoRetryConfig->fallbackStrategy,
                occurredAt: CarbonImmutable::now(),
            ));

            if ($autoRetryConfig->shouldFallbackToAwaitDecision()) {
                $this->handleAwaitDecisionStrategy($workflowInstance);
            }

            return;
        }

        $retryNumber = $workflowInstance->autoRetryCount() + 1;
        $delaySeconds = $autoRetryConfig->getDelayForRetry($retryNumber);
        $scheduledFor = CarbonImmutable::now()->addSeconds($delaySeconds);

        $workflowInstance->scheduleAutoRetry($scheduledFor);
        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new AutoRetryScheduled(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            failedStepKey: $workflowInstance->currentStepKey() ?? StepKey::fromString('unknown'),
            retryNumber: $retryNumber,
            maxRetries: $autoRetryConfig->maxRetries,
            delaySeconds: $delaySeconds,
            scheduledFor: $scheduledFor,
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws WorkflowNotFoundException
     */
    private function handleAutoCompensateStrategy(WorkflowInstance $workflowInstance): void
    {
        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $scope = $workflowDefinition->failureResolution()->compensationScope;

        $this->compensationExecutor->initiate(
            workflowId: $workflowInstance->id,
            initiatedBy: 'auto-compensation',
            reason: 'Automatic compensation triggered by failure resolution strategy',
            scope: $scope,
        );
    }

    /**
     * @throws InvalidStepKeyException
     */
    private function handleAwaitDecisionStrategy(WorkflowInstance $workflowInstance): void
    {
        $this->eventDispatcher->dispatch(new WorkflowAwaitingResolution(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            failedStepKey: $workflowInstance->currentStepKey() ?? StepKey::fromString('unknown'),
            failureCode: $workflowInstance->failureCode(),
            failureMessage: $workflowInstance->failureMessage(),
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     * @throws WorkflowLockedException
     * @throws WorkflowNotFoundException
     */
    private function executeAutoRetry(WorkflowInstance $workflowInstance): void
    {
        $workflowInstance->clearAutoRetry();
        $workflowInstance->retry();
        $this->workflowRepository->save($workflowInstance);

        $this->workflowAdvancer->evaluate($workflowInstance->id);
    }

    /**
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     * @throws WorkflowLockedException
     * @throws WorkflowNotFoundException
     */
    private function executeRetry(WorkflowInstance $workflowInstance): WorkflowInstance
    {
        $workflowInstance->resetAutoRetryCount();
        $workflowInstance->retry();
        $this->workflowRepository->save($workflowInstance);

        $this->workflowAdvancer->evaluate($workflowInstance->id);

        return $this->workflowRepository->findOrFail($workflowInstance->id);
    }

    /**
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws StepDependencyException
     * @throws WorkflowLockedException
     * @throws WorkflowNotFoundException
     * @throws StepNotFoundException
     * @throws ConditionEvaluationException
     */
    private function executeRetryFromStep(
        WorkflowInstance $workflowInstance,
        ?StepKey $retryFromStepKey,
    ): WorkflowInstance {
        $workflowInstance->resetAutoRetryCount();
        $this->workflowRepository->save($workflowInstance);

        if (! $retryFromStepKey instanceof StepKey) {
            $currentStepKey = $workflowInstance->currentStepKey();
            if ($currentStepKey instanceof StepKey) {
                $retryFromStepKey = $currentStepKey;
            } else {
                $workflowInstance->retry();
                $this->workflowRepository->save($workflowInstance);
                $this->workflowAdvancer->evaluate($workflowInstance->id);

                return $this->workflowRepository->findOrFail($workflowInstance->id);
            }
        }

        $retryFromStepRequest = RetryFromStepRequest::create(
            workflowId: $workflowInstance->id,
            retryFromStepKey: $retryFromStepKey,
            retryMode: RetryMode::RetryOnly,
        );

        $retryFromStepResult = $this->retryFromStepService->execute($retryFromStepRequest);

        return $retryFromStepResult->workflowInstance;
    }

    /**
     * @param list<StepKey>|null $compensateStepKeys
     *
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws WorkflowNotFoundException
     */
    private function executeCompensate(
        WorkflowInstance $workflowInstance,
        ?array $compensateStepKeys,
    ): WorkflowInstance {
        $workflowInstance->resetAutoRetryCount();
        $this->workflowRepository->save($workflowInstance);

        $scope = $compensateStepKeys !== null
            ? CompensationScope::Partial
            : CompensationScope::All;

        $this->compensationExecutor->initiate(
            workflowId: $workflowInstance->id,
            stepKeys: $compensateStepKeys,
            initiatedBy: 'manual-decision',
            reason: 'Manual compensation decision',
            scope: $scope,
        );

        return $this->workflowRepository->findOrFail($workflowInstance->id);
    }

    /**
     * @throws InvalidStateTransitionException
     * @throws WorkflowAlreadyCancelledException
     */
    private function executeCancel(WorkflowInstance $workflowInstance): WorkflowInstance
    {
        $workflowInstance->resetAutoRetryCount();
        $workflowInstance->cancel();
        $this->workflowRepository->save($workflowInstance);

        return $workflowInstance;
    }

    private function executeMarkResolved(WorkflowInstance $workflowInstance): WorkflowInstance
    {
        $workflowInstance->resetAutoRetryCount();
        $this->workflowRepository->save($workflowInstance);

        return $workflowInstance;
    }
}
