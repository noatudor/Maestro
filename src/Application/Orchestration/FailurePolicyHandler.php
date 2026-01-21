<?php

declare(strict_types=1);

namespace Maestro\Workflow\Application\Orchestration;

use Maestro\Workflow\Contracts\StepDefinition;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\StepRun;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\FailurePolicy;
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
    ) {}

    /**
     * Handle a step failure based on its failure policy.
     *
     * @throws InvalidStateTransitionException
     * @throws StepDependencyException
     * @throws DefinitionNotFoundException
     */
    public function handle(
        WorkflowInstance $workflow,
        StepRun $stepRun,
        StepDefinition $stepDefinition,
    ): void {
        $policy = $stepDefinition->failurePolicy();
        $retryConfig = $stepDefinition->retryConfiguration();

        if ($policy === FailurePolicy::RetryStep) {
            if (! $retryConfig->hasReachedMaxAttempts($stepRun->attempt)) {
                $this->retryStep($workflow, $stepDefinition);

                return;
            }

            $this->failWorkflow($workflow, $stepRun);

            return;
        }

        match ($policy) {
            FailurePolicy::FailWorkflow => $this->failWorkflow($workflow, $stepRun),
            FailurePolicy::PauseWorkflow => $this->pauseWorkflow($workflow, $stepRun),
            FailurePolicy::SkipStep => $this->skipStep($workflow),
            FailurePolicy::ContinueWithPartial => $this->continueWithPartial($workflow),
        };
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function failWorkflow(WorkflowInstance $workflow, StepRun $stepRun): void
    {
        $workflow->fail(
            $stepRun->failureCode(),
            $stepRun->failureMessage(),
        );

        $this->workflowRepository->save($workflow);
    }

    /**
     * @throws InvalidStateTransitionException
     */
    private function pauseWorkflow(WorkflowInstance $workflow, StepRun $stepRun): void
    {
        $reason = sprintf(
            'Step "%s" failed: %s',
            $stepRun->stepKey->value,
            $stepRun->failureMessage() ?? 'Unknown error',
        );

        $workflow->pause($reason);
        $this->workflowRepository->save($workflow);
    }

    /**
     * @throws StepDependencyException
     * @throws InvalidStateTransitionException
     * @throws DefinitionNotFoundException
     */
    private function retryStep(WorkflowInstance $workflow, StepDefinition $stepDefinition): void
    {
        $this->stepDispatcher->retryStep($workflow, $stepDefinition);
    }

    private function skipStep(WorkflowInstance $workflow): void
    {
        $this->workflowRepository->save($workflow);
    }

    private function continueWithPartial(WorkflowInstance $workflow): void
    {
        $this->workflowRepository->save($workflow);
    }
}
