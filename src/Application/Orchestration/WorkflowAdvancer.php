<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Deprecated;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Application\Branching\ConditionEvaluator;
use Maestro\Workflow\Application\Output\StepOutputStoreFactory;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\Config\PauseTriggerDefinition;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\Events\WorkflowFailed;
use Maestro\Workflow\Domain\Events\WorkflowStarted;
use Maestro\Workflow\Domain\Events\WorkflowSucceeded;
use Maestro\Workflow\Domain\Events\WorkflowTerminatedEarly;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;
use Ramsey\Uuid\Uuid;

/**
 * The stateless workflow advancer that evaluates workflow state and takes appropriate action.
 *
 * This is the core orchestration engine. It is triggered by events (job completion,
 * external triggers, manual actions) and evaluates what action to take next.
 *
 * Supports conditional branching and early termination through step conditions
 * and termination conditions.
 */
final readonly class WorkflowAdvancer
{
    private const int DEFAULT_LOCK_TIMEOUT_SECONDS = 5;

    public function __construct(
        private WorkflowRepository $workflowRepository,
        private StepRunRepository $stepRunRepository,
        private WorkflowDefinitionRegistry $workflowDefinitionRegistry,
        private StepFinalizer $stepFinalizer,
        private StepDispatcher $stepDispatcher,
        private FailurePolicyHandler $failurePolicyHandler,
        private ConditionEvaluator $conditionEvaluator,
        private StepOutputStoreFactory $stepOutputStoreFactory,
        private Dispatcher $eventDispatcher,
        private ?PauseTriggerHandler $pauseTriggerHandler = null,
    ) {}

    /**
     * Evaluate a workflow and advance it if appropriate.
     *
     * This is the main entry point for the orchestration engine.
     * It uses database-level pessimistic locking (SELECT FOR UPDATE) to prevent
     * concurrent evaluation of the same workflow.
     *
     * @throws WorkflowNotFoundException
     * @throws DefinitionNotFoundException
     * @throws WorkflowLockedException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     */
    public function evaluate(WorkflowId $workflowId): void
    {
        $this->workflowRepository->withLockedWorkflow(
            $workflowId,
            fn (WorkflowInstance $workflowInstance) => $this->doEvaluate($workflowInstance),
            self::DEFAULT_LOCK_TIMEOUT_SECONDS,
        );
    }

    /**
     * Evaluate a workflow with application-level locking (legacy behavior).
     *
     * This method uses application-level locks stored in the database columns.
     * Prefer evaluate() for most use cases as it provides stronger guarantees.
     *
     * @throws WorkflowNotFoundException
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws ConditionEvaluationException
     */
    #[Deprecated(message: 'Use evaluate() instead for database-level locking')]
    public function evaluateWithApplicationLock(WorkflowId $workflowId): void
    {
        $lockId = $this->generateLockId();
        $workflowInstance = $this->workflowRepository->findOrFail($workflowId);

        if (! $this->acquireApplicationLock($workflowInstance, $lockId)) {
            return;
        }

        try {
            $this->doEvaluate($workflowInstance);
        } finally {
            $this->releaseApplicationLock($workflowInstance, $lockId);
        }
    }

    /**
     * Perform the actual evaluation logic.
     *
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws ConditionEvaluationException
     */
    private function doEvaluate(WorkflowInstance $workflowInstance): void
    {
        if ($workflowInstance->isTerminal() || $workflowInstance->isPaused()) {
            return;
        }

        if ($workflowInstance->isPending()) {
            $this->startWorkflow($workflowInstance);

            return;
        }

        $currentStepKey = $workflowInstance->currentStepKey();
        if (! $currentStepKey instanceof StepKey) {
            return;
        }

        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $stepDefinition = $workflowDefinition->getStep($currentStepKey);
        if (! $stepDefinition instanceof StepDefinition) {
            return;
        }

        $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
            $workflowInstance->id,
            $currentStepKey,
        );

        if (! $stepRun instanceof StepRun) {
            $dispatchResult = $this->stepDispatcher->dispatchStepWithResult($workflowInstance, $stepDefinition);

            if ($dispatchResult->wasSkipped()) {
                $this->advanceToNextStep($workflowInstance, $workflowDefinition);
            }

            return;
        }

        if ($stepRun->isSkipped()) {
            $this->advanceToNextStep($workflowInstance, $workflowDefinition);

            return;
        }

        if ($stepRun->isPolling()) {
            return;
        }

        if ($stepRun->isRunning()) {
            $finalizationResult = $this->stepFinalizer->tryFinalize($stepRun, $stepDefinition);

            if (! $finalizationResult->wonRace()) {
                return;
            }

            $stepRun = $finalizationResult->stepRun();
        }

        if ($stepRun->isFailed() || $stepRun->isTimedOut()) {
            $this->failurePolicyHandler->handle($workflowInstance, $stepRun, $stepDefinition);

            return;
        }

        if ($stepRun->isSucceeded()) {
            if ($stepDefinition->hasTerminationCondition()) {
                $terminationResult = $this->evaluateTerminationCondition(
                    $workflowInstance,
                    $stepDefinition,
                );

                if ($terminationResult) {
                    return;
                }
            }

            if ($stepDefinition->hasPauseTrigger()) {
                $pauseTrigger = $stepDefinition->pauseTrigger();
                if ($pauseTrigger instanceof PauseTriggerDefinition && $this->pauseTriggerHandler instanceof PauseTriggerHandler) {
                    $this->pauseTriggerHandler->pauseForTrigger(
                        $workflowInstance,
                        $stepDefinition->key(),
                        $pauseTrigger,
                    );

                    return;
                }
            }

            $this->advanceToNextStep($workflowInstance, $workflowDefinition);
        }
    }

    /**
     * Evaluate a termination condition after a step succeeds.
     *
     * @return bool True if workflow was terminated, false to continue
     *
     * @throws InvalidStateTransitionException
     * @throws ConditionEvaluationException
     */
    private function evaluateTerminationCondition(
        WorkflowInstance $workflowInstance,
        StepDefinition $stepDefinition,
    ): bool {
        $terminationConditionClass = $stepDefinition->terminationConditionClass();
        if ($terminationConditionClass === null) {
            return false;
        }

        $stepOutputStore = $this->stepOutputStoreFactory->forWorkflow($workflowInstance->id);
        $terminationResult = $this->conditionEvaluator->evaluateTerminationCondition(
            $terminationConditionClass,
            $stepOutputStore,
        );

        if ($terminationResult->shouldContinue()) {
            return false;
        }

        $terminalState = $terminationResult->terminalState() ?? WorkflowState::Failed;
        $reason = $terminationResult->reason() ?? 'Termination condition met';

        if ($terminalState === WorkflowState::Succeeded) {
            $workflowInstance->succeed();
        } else {
            $workflowInstance->fail('EARLY_TERMINATION', $reason);
        }

        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowTerminatedEarly(
            workflowId: $workflowInstance->id,
            lastStepKey: $stepDefinition->key(),
            conditionClass: $terminationConditionClass,
            terminalState: $terminalState,
            reason: $reason,
            occurredAt: CarbonImmutable::now(),
        ));

        if ($terminalState === WorkflowState::Succeeded) {
            $this->eventDispatcher->dispatch(new WorkflowSucceeded(
                workflowId: $workflowInstance->id,
                definitionKey: $workflowInstance->definitionKey,
                definitionVersion: $workflowInstance->definitionVersion,
                occurredAt: CarbonImmutable::now(),
            ));
        } else {
            $this->eventDispatcher->dispatch(new WorkflowFailed(
                workflowId: $workflowInstance->id,
                definitionKey: $workflowInstance->definitionKey,
                definitionVersion: $workflowInstance->definitionVersion,
                failureCode: 'EARLY_TERMINATION',
                failureMessage: $reason,
                occurredAt: CarbonImmutable::now(),
            ));
        }

        return true;
    }

    /**
     * Start a workflow by dispatching its first step.
     *
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws ConditionEvaluationException
     */
    private function startWorkflow(WorkflowInstance $workflowInstance): void
    {
        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $firstStep = $workflowDefinition->getFirstStep();
        if (! $firstStep instanceof StepDefinition) {
            $workflowInstance->succeedImmediately();
            $this->workflowRepository->save($workflowInstance);

            $this->eventDispatcher->dispatch(new WorkflowSucceeded(
                workflowId: $workflowInstance->id,
                definitionKey: $workflowInstance->definitionKey,
                definitionVersion: $workflowInstance->definitionVersion,
                occurredAt: CarbonImmutable::now(),
            ));

            return;
        }

        $workflowInstance->start($firstStep->key());
        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowStarted(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            firstStepKey: $firstStep->key(),
            occurredAt: CarbonImmutable::now(),
        ));

        $stepDispatchResult = $this->stepDispatcher->dispatchStepWithResult($workflowInstance, $firstStep);

        if ($stepDispatchResult->wasSkipped()) {
            $this->advanceToNextStep($workflowInstance, $workflowDefinition);
        }
    }

    /**
     * Advance workflow to the next step or mark as completed.
     *
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws DefinitionNotFoundException
     * @throws ConditionEvaluationException
     */
    private function advanceToNextStep(
        WorkflowInstance $workflowInstance,
        WorkflowDefinition $workflowDefinition,
    ): void {
        $currentStepKey = $workflowInstance->currentStepKey();
        if (! $currentStepKey instanceof StepKey) {
            return;
        }

        if ($workflowDefinition->isLastStep($currentStepKey)) {
            $workflowInstance->succeed();
            $this->workflowRepository->save($workflowInstance);

            $this->eventDispatcher->dispatch(new WorkflowSucceeded(
                workflowId: $workflowInstance->id,
                definitionKey: $workflowInstance->definitionKey,
                definitionVersion: $workflowInstance->definitionVersion,
                occurredAt: CarbonImmutable::now(),
            ));

            return;
        }

        $nextStep = $workflowDefinition->getNextStep($currentStepKey);
        if (! $nextStep instanceof StepDefinition) {
            $workflowInstance->succeed();
            $this->workflowRepository->save($workflowInstance);

            $this->eventDispatcher->dispatch(new WorkflowSucceeded(
                workflowId: $workflowInstance->id,
                definitionKey: $workflowInstance->definitionKey,
                definitionVersion: $workflowInstance->definitionVersion,
                occurredAt: CarbonImmutable::now(),
            ));

            return;
        }

        $workflowInstance->advanceToStep($nextStep->key());
        $this->workflowRepository->save($workflowInstance);

        $stepDispatchResult = $this->stepDispatcher->dispatchStepWithResult($workflowInstance, $nextStep);

        if ($stepDispatchResult->wasSkipped()) {
            $this->advanceToNextStep($workflowInstance, $workflowDefinition);
        }
    }

    private function acquireApplicationLock(WorkflowInstance $workflowInstance, string $lockId): bool
    {
        try {
            $workflowInstance->acquireLock($lockId);
            $this->workflowRepository->save($workflowInstance);

            return true;
        } catch (WorkflowLockedException) {
            return false;
        }
    }

    private function releaseApplicationLock(WorkflowInstance $workflowInstance, string $lockId): void
    {
        $workflowInstance->releaseLock($lockId);
        $this->workflowRepository->save($workflowInstance);
    }

    private function generateLockId(): string
    {
        return Uuid::uuid7()->toString();
    }
}
