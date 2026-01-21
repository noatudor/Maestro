<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Contracts\StepRunRepository;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Definition\WorkflowDefinitionRegistry;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
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
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private StepRunRepository $stepRunRepository,
        private WorkflowDefinitionRegistry $definitionRegistry,
        private StepFinalizer $stepFinalizer,
        private StepDispatcher $stepDispatcher,
        private FailurePolicyHandler $failurePolicyHandler,
    ) {}

    /**
     * Evaluate a workflow and advance it if appropriate.
     *
     * This is the main entry point for the orchestration engine.
     * It acquires a lock, evaluates the workflow state, and takes action.
     *
     * @throws WorkflowNotFoundException
     * @throws DefinitionNotFoundException
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    public function evaluate(WorkflowId $workflowId): void
    {
        $lockId = $this->generateLockId();
        $workflow = $this->workflowRepository->findOrFail($workflowId);

        if (! $this->acquireLock($workflow, $lockId)) {
            return;
        }

        try {
            $this->doEvaluate($workflow);
        } finally {
            $this->releaseLock($workflow, $lockId);
        }
    }

    /**
     * Perform the actual evaluation logic.
     *
     * @throws DefinitionNotFoundException
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    private function doEvaluate(WorkflowInstance $workflow): void
    {
        if ($workflow->isTerminal() || $workflow->isPaused()) {
            return;
        }

        if ($workflow->isPending()) {
            $this->startWorkflow($workflow);

            return;
        }

        $currentStepKey = $workflow->currentStepKey();
        if ($currentStepKey === null) {
            return;
        }

        $definition = $this->definitionRegistry->get(
            $workflow->definitionKey,
            $workflow->definitionVersion,
        );

        $stepDefinition = $definition->getStep($currentStepKey);
        if ($stepDefinition === null) {
            return;
        }

        $stepRun = $this->stepRunRepository->findLatestByWorkflowIdAndStepKey(
            $workflow->id,
            $currentStepKey,
        );

        if ($stepRun === null) {
            $this->stepDispatcher->dispatchStep($workflow, $stepDefinition);

            return;
        }

        if ($stepRun->isRunning()) {
            $finalizationResult = $this->stepFinalizer->tryFinalize($stepRun, $stepDefinition);

            if (! $finalizationResult->isFinalized()) {
                return;
            }

            $stepRun = $finalizationResult->stepRun();
        }

        if ($stepRun->isFailed()) {
            $this->failurePolicyHandler->handle($workflow, $stepRun, $stepDefinition);

            return;
        }

        if ($stepRun->isSucceeded()) {
            $this->advanceToNextStep($workflow, $definition);
        }
    }

    /**
     * Start a workflow by dispatching its first step.
     *
     * @throws DefinitionNotFoundException
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     */
    private function startWorkflow(WorkflowInstance $workflow): void
    {
        $definition = $this->definitionRegistry->get(
            $workflow->definitionKey,
            $workflow->definitionVersion,
        );

        $firstStep = $definition->getFirstStep();
        if ($firstStep === null) {
            $workflow->succeed();
            $this->workflowRepository->save($workflow);

            return;
        }

        $workflow->start($firstStep->key());
        $this->workflowRepository->save($workflow);

        $this->stepDispatcher->dispatchStep($workflow, $firstStep);
    }

    /**
     * Advance workflow to the next step or mark as completed.
     *
     * @throws \Maestro\Workflow\Exceptions\InvalidStateTransitionException
     * @throws \Maestro\Workflow\Exceptions\StepDependencyException
     * @throws DefinitionNotFoundException
     */
    private function advanceToNextStep(
        WorkflowInstance $workflow,
        \Maestro\Workflow\Definition\WorkflowDefinition $definition,
    ): void {
        $currentStepKey = $workflow->currentStepKey();
        if ($currentStepKey === null) {
            return;
        }

        if ($definition->isLastStep($currentStepKey)) {
            $workflow->succeed();
            $this->workflowRepository->save($workflow);

            return;
        }

        $nextStep = $definition->getNextStep($currentStepKey);
        if ($nextStep === null) {
            $workflow->succeed();
            $this->workflowRepository->save($workflow);

            return;
        }

        $workflow->advanceToStep($nextStep->key());
        $this->workflowRepository->save($workflow);

        $this->stepDispatcher->dispatchStep($workflow, $nextStep);
    }

    private function acquireLock(WorkflowInstance $workflow, string $lockId): bool
    {
        try {
            $workflow->acquireLock($lockId);
            $this->workflowRepository->save($workflow);

            return true;
        } catch (WorkflowLockedException) {
            return false;
        }
    }

    private function releaseLock(WorkflowInstance $workflow, string $lockId): void
    {
        $workflow->releaseLock($lockId);
        $this->workflowRepository->save($workflow);
    }

    private function generateLockId(): string
    {
        return Uuid::uuid7()->toString();
    }
}
