<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\Events\WorkflowFailed;
use Maestro\Workflow\Domain\Events\WorkflowPaused;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\FailurePolicy;
use Maestro\Workflow\Exceptions\ConditionEvaluationException;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;

/**
 * Handles step failures based on the configured failure policy.
 *
 * Applies the appropriate action when a step fails:
 * - Fail the workflow
 * - Pause the workflow for manual intervention
 * - Retry the step
 * - Skip the step and continue
 * - Continue with partial success
 */
final readonly class FailurePolicyHandler
{
    public function __construct(
        private WorkflowRepository $workflowRepository,
        private StepDispatcher $stepDispatcher,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * Handle a step failure based on its failure policy.
     *
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws DefinitionNotFoundException
     * @throws ConditionEvaluationException
     */
    public function handle(
        WorkflowInstance $workflowInstance,
        StepRun $stepRun,
        StepDefinition $stepDefinition,
    ): void {
        $failurePolicy = $stepDefinition->failurePolicy();
        $retryConfiguration = $stepDefinition->retryConfiguration();

        if ($failurePolicy === FailurePolicy::RetryStep) {
            if (! $retryConfiguration->hasReachedMaxAttempts($stepRun->attempt)) {
                $this->retryStep($workflowInstance, $stepDefinition);

                return;
            }

            $this->failWorkflow($workflowInstance, $stepRun);

            return;
        }

        match ($failurePolicy) {
            FailurePolicy::FailWorkflow => $this->failWorkflow($workflowInstance, $stepRun),
            FailurePolicy::PauseWorkflow => $this->pauseWorkflow($workflowInstance, $stepRun),
            FailurePolicy::SkipStep => $this->skipStep($workflowInstance),
            FailurePolicy::ContinueWithPartial => $this->continueWithPartial($workflowInstance),
        };
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function failWorkflow(WorkflowInstance $workflowInstance, StepRun $stepRun): void
    {
        $workflowInstance->fail(
            $stepRun->failureCode(),
            $stepRun->failureMessage(),
        );

        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowFailed(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            failureCode: $stepRun->failureCode(),
            failureMessage: $stepRun->failureMessage(),
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function pauseWorkflow(WorkflowInstance $workflowInstance, StepRun $stepRun): void
    {
        $reason = sprintf(
            'Step "%s" failed: %s',
            $stepRun->stepKey->value,
            $stepRun->failureMessage() ?? 'Unknown error',
        );

        $workflowInstance->pause($reason);
        $this->workflowRepository->save($workflowInstance);

        $this->eventDispatcher->dispatch(new WorkflowPaused(
            workflowId: $workflowInstance->id,
            definitionKey: $workflowInstance->definitionKey,
            definitionVersion: $workflowInstance->definitionVersion,
            reason: $reason,
            occurredAt: CarbonImmutable::now(),
        ));
    }

    /**
     * @throws StepDependencyException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     * @throws ConditionEvaluationException
     */
    private function retryStep(WorkflowInstance $workflowInstance, StepDefinition $stepDefinition): void
    {
        $this->stepDispatcher->retryStep($workflowInstance, $stepDefinition);
    }

    private function skipStep(WorkflowInstance $workflowInstance): void
    {
        $this->workflowRepository->save($workflowInstance);
    }

    private function continueWithPartial(WorkflowInstance $workflowInstance): void
    {
        $this->workflowRepository->save($workflowInstance);
    }
}
