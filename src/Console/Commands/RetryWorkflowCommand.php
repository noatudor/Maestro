<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Artisan command to retry a failed workflow.
 */
final class RetryWorkflowCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:retry
                            {workflow : The workflow ID to retry}
                            {--force : Retry without confirmation}';

    /**
     * @var string
     */
    protected $description = 'Retry a failed workflow from the failed step';

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
        } catch (WorkflowNotFoundException $e) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        }

        if (! $workflow->isFailed()) {
            $this->error('Workflow is not in failed state. Current state: '.$workflow->state()->value);

            return self::FAILURE;
        }

        $currentStepKey = $workflow->currentStepKey();
        $currentStep = $currentStepKey instanceof StepKey ? $currentStepKey->value : 'N/A';
        $failureMessage = $workflow->failureMessage() ?? 'N/A';
        $version = $workflow->definitionVersion->toString();

        $this->info('Workflow ID: '.$workflow->id->value);
        $this->info(sprintf('Definition: %s v%s', $workflow->definitionKey->value, $version));
        $this->info('Current step: '.$currentStep);
        $this->info('Failure: '.$failureMessage);

        $force = (bool) $this->option('force');

        if (! $force && ! $this->confirm('Do you want to retry this workflow?')) {
            $this->info('Retry cancelled.');

            return self::SUCCESS;
        }

        try {
            $this->workflowManager->retryWorkflow($workflowId);
            $this->info(sprintf('Workflow %s has been retried successfully.', $workflowIdString));

            return self::SUCCESS;
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        } catch (InvalidStateTransitionException $e) {
            $this->error('Cannot retry workflow: '.$e->getMessage());

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
}
