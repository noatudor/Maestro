<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Deprecated;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinition;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
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
        private StepConditionEvaluator $stepConditionEvaluator,
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
            $this->stepDispatcher->dispatchStep($workflowInstance, $stepDefinition);

            return;
        }

        if ($stepRun->isRunning()) {
            $finalizationResult = $this->stepFinalizer->tryFinalize($stepRun, $stepDefinition);

            if (! $finalizationResult->wonRace()) {
                return;
            }

            $stepRun = $finalizationResult->stepRun();
        }

        if ($stepRun->isFailed()) {
            $this->failurePolicyHandler->handle($workflowInstance, $stepRun, $stepDefinition);

            return;
        }

        if ($stepRun->isSucceeded()) {
            $this->advanceToNextStep($workflowInstance, $workflowDefinition);
        }
    }

    /**
     * Start a workflow by dispatching its first step.
     *
     * @throws DefinitionNotFoundException
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     */
    private function startWorkflow(WorkflowInstance $workflowInstance): void
    {
        $workflowDefinition = $this->workflowDefinitionRegistry->get(
            $workflowInstance->definitionKey,
            $workflowInstance->definitionVersion,
        );

        $firstStep = $this->findNextExecutableStep($workflowInstance, $workflowDefinition, null);

        if (! $firstStep instanceof StepDefinition) {
            $workflowInstance->succeedImmediately();
            $this->workflowRepository->save($workflowInstance);

            return;
        }

        $workflowInstance->start($firstStep->key());
        $this->workflowRepository->save($workflowInstance);

        $this->stepDispatcher->dispatchStep($workflowInstance, $firstStep);
    }

    /**
     * Advance workflow to the next step or mark as completed.
     *
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws DefinitionNotFoundException
     */
    private function advanceToNextStep(
        WorkflowInstance $workflowInstance,
        WorkflowDefinition $workflowDefinition,
    ): void {
        $currentStepKey = $workflowInstance->currentStepKey();
        if (! $currentStepKey instanceof StepKey) {
            return;
        }

        $nextStep = $this->findNextExecutableStep($workflowInstance, $workflowDefinition, $currentStepKey);

        if (! $nextStep instanceof StepDefinition) {
            $workflowInstance->succeed();
            $this->workflowRepository->save($workflowInstance);

            return;
        }

        $workflowInstance->advanceToStep($nextStep->key());
        $this->workflowRepository->save($workflowInstance);

        $this->stepDispatcher->dispatchStep($workflowInstance, $nextStep);
    }

    /**
     * Find the next executable step considering conditions.
     *
     * Skips steps whose conditions evaluate to false.
     */
    private function findNextExecutableStep(
        WorkflowInstance $workflowInstance,
        WorkflowDefinition $workflowDefinition,
        ?StepKey $currentStepKey,
    ): ?StepDefinition {
        $candidateStep = $currentStepKey === null
            ? $workflowDefinition->getFirstStep()
            : $workflowDefinition->getNextStep($currentStepKey);

        while ($candidateStep instanceof StepDefinition) {
            $shouldExecute = $this->stepConditionEvaluator->shouldExecute(
                $workflowInstance,
                $workflowDefinition,
                $candidateStep,
            );

            if ($shouldExecute) {
                return $candidateStep;
            }

            $candidateStep = $workflowDefinition->getNextStep($candidateStep->key());
        }

        return null;
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
