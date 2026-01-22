<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\ResolutionDecision;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\InvalidStepKeyException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFailedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Artisan command to resolve a failed workflow with a specific decision.
 */
final class ResolveWorkflowCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:resolve
                            {workflow : The workflow ID to resolve}
                            {--decision= : The resolution decision (retry, retry_from_step, compensate, cancel, mark_resolved)}
                            {--step= : The step key to retry from (for retry_from_step decision)}
                            {--reason= : Reason for the resolution decision}
                            {--by= : Who is making this decision}
                            {--force : Apply without confirmation}';

    /**
     * @var string
     */
    protected $description = 'Resolve a failed workflow by applying a resolution decision';

    public function __construct(
        private readonly WorkflowManager $workflowManager,
        private readonly WorkflowRepository $workflowRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string $workflowIdString */
        $workflowIdString = $this->argument('workflow');
        $workflowId = WorkflowId::fromString($workflowIdString);

        try {
            $workflow = $this->workflowRepository->findOrFail($workflowId);
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        }

        if (! $workflow->isFailed()) {
            $this->error('Workflow is not in failed state. Current state: '.$workflow->state()->value);

            return self::FAILURE;
        }

        $this->displayWorkflowInfo($workflow);

        $decision = $this->getDecision();

        if (! $decision instanceof ResolutionDecision) {
            return self::FAILURE;
        }

        $retryFromStepKey = null;
        if ($decision === ResolutionDecision::RetryFromStep) {
            try {
                $retryFromStepKey = $this->getRetryFromStepKey();
            } catch (InvalidStepKeyException $e) {
                $this->error('Invalid step key: '.$e->getMessage());

                return self::FAILURE;
            }

            if (! $retryFromStepKey instanceof StepKey) {
                $this->error('Step key is required for retry_from_step decision.');

                return self::FAILURE;
            }
        }

        $reason = $this->getOptionOrPrompt('reason', 'Reason for this decision (optional)');
        $decidedBy = $this->getOptionOrPrompt('by', 'Who is making this decision (optional)');

        $force = (bool) $this->option('force');

        if (! $force) {
            $this->newLine();
            $this->info('Resolution Details:');
            $this->info('  Decision: '.$decision->value);

            if ($retryFromStepKey instanceof StepKey) {
                $this->info('  Retry from step: '.$retryFromStepKey->value);
            }

            if ($reason !== '') {
                $this->info('  Reason: '.$reason);
            }

            if ($decidedBy !== '') {
                $this->info('  Decided by: '.$decidedBy);
            }

            if (! $this->confirm('Do you want to apply this resolution decision?')) {
                $this->info('Resolution cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $this->workflowManager->resolveFailure(
                workflowId: $workflowId,
                decidedBy: $decidedBy !== '' ? $decidedBy : null,
                reason: $reason !== '' ? $reason : null,
                retryFromStepKey: $retryFromStepKey,
                decision: $decision,
            );

            $this->info(sprintf('Workflow %s has been resolved with decision: %s', $workflowIdString, $decision->value));

            return self::SUCCESS;
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        } catch (WorkflowNotFailedException $e) {
            $this->error('Workflow is not in failed state: '.$e->getMessage());

            return self::FAILURE;
        } catch (InvalidStateTransitionException $e) {
            $this->error('Invalid state transition: '.$e->getMessage());

            return self::FAILURE;
        } catch (WorkflowLockedException $e) {
            $this->error('Workflow is locked by another process: '.$e->getMessage());

            return self::FAILURE;
        } catch (DefinitionNotFoundException $e) {
            $this->error('Workflow definition not found: '.$e->getMessage());

            return self::FAILURE;
        } catch (StepDependencyException $e) {
            $this->error('Step dependency error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function displayWorkflowInfo(WorkflowInstance $workflowInstance): void
    {
        $currentStepKey = $workflowInstance->currentStepKey();
        $currentStep = $currentStepKey instanceof StepKey ? $currentStepKey->value : 'N/A';
        $failureMessage = $workflowInstance->failureMessage() ?? 'N/A';
        $failureCode = $workflowInstance->failureCode() ?? 'N/A';
        $version = $workflowInstance->definitionVersion->toString();

        $this->info('Workflow Details:');
        $this->info('  ID: '.$workflowInstance->id->value);
        $this->info(sprintf('  Definition: %s v%s', $workflowInstance->definitionKey->value, $version));
        $this->info('  Failed at step: '.$currentStep);
        $this->info('  Failure code: '.$failureCode);
        $this->info('  Failure message: '.$failureMessage);
        $this->newLine();
    }

    private function getDecision(): ?ResolutionDecision
    {
        /** @var string|null $decisionOption */
        $decisionOption = $this->option('decision');

        if ($decisionOption !== null) {
            return ResolutionDecision::tryFrom($decisionOption);
        }

        $selected = select(
            label: 'Select a resolution decision:',
            options: [
                ResolutionDecision::Retry->value => 'Retry - Re-run the failed step',
                ResolutionDecision::RetryFromStep->value => 'Retry from step - Re-run from a specific step',
                ResolutionDecision::Compensate->value => 'Compensate - Execute compensation jobs',
                ResolutionDecision::Cancel->value => 'Cancel - Mark workflow as cancelled',
                ResolutionDecision::MarkResolved->value => 'Mark resolved - Mark as resolved without action',
            ],
        );

        return ResolutionDecision::from($selected);
    }

    /**
     * @throws InvalidStepKeyException
     */
    private function getRetryFromStepKey(): ?StepKey
    {
        /** @var string|null $stepOption */
        $stepOption = $this->option('step');

        if ($stepOption !== null && $stepOption !== '') {
            return StepKey::fromString($stepOption);
        }

        $stepKeyString = text(
            label: 'Enter the step key to retry from:',
            required: true,
        );

        if ($stepKeyString === '') {
            return null;
        }

        return StepKey::fromString($stepKeyString);
    }

    private function getOptionOrPrompt(string $option, string $prompt): string
    {
        /** @var string|null $value */
        $value = $this->option($option);

        if ($value !== null) {
            return $value;
        }

        return text(label: $prompt);
    }
}
