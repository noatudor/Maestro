<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Artisan command to pause a running workflow.
 */
final class PauseWorkflowCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:pause
                            {workflow : The workflow ID to pause}
                            {--reason= : Reason for pausing the workflow}
                            {--force : Pause without confirmation}';

    /**
     * @var string
     */
    protected $description = 'Pause a running workflow';

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
        /** @var string|null $reason */
        $reason = $this->option('reason');
        $force = (bool) $this->option('force');

        try {
            $workflow = $this->workflowRepository->findOrFail($workflowId);
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        }

        if (! $workflow->isRunning()) {
            $this->error('Workflow is not running. Current state: '.$workflow->state()->value);

            return self::FAILURE;
        }

        $currentStepKey = $workflow->currentStepKey();
        $currentStep = $currentStepKey instanceof StepKey ? $currentStepKey->value : 'N/A';
        $version = $workflow->definitionVersion->toString();

        $this->info('Workflow ID: '.$workflow->id->value);
        $this->info(sprintf('Definition: %s v%s', $workflow->definitionKey->value, $version));
        $this->info('Current step: '.$currentStep);

        if (! $force && ! $this->confirm('Do you want to pause this workflow?')) {
            $this->info('Pause cancelled.');

            return self::SUCCESS;
        }

        try {
            $this->workflowManager->pauseWorkflow($workflowId, $reason);
            $this->info(sprintf('Workflow %s has been paused successfully.', $workflowIdString));

            if ($reason !== null) {
                $this->info('Reason: '.$reason);
            }

            return self::SUCCESS;
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        } catch (InvalidStateTransitionException $e) {
            $this->error('Cannot pause workflow: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
