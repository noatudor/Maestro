<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\DefinitionNotFoundException;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\StepDependencyException;
use Maestro\Workflow\Exceptions\WorkflowLockedException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Artisan command to resume paused workflows.
 *
 * Supports both single workflow resume and bulk resume of all paused workflows.
 */
final class ResumeWorkflowsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:resume
                            {workflow? : The workflow ID to resume (omit for bulk operations)}
                            {--all : Resume all paused workflows}
                            {--force : Resume without confirmation}
                            {--dry-run : Show what would be resumed without making changes}';

    /**
     * @var string
     */
    protected $description = 'Resume paused workflow(s)';

    public function __construct(
        private readonly WorkflowManager $workflowManager,
        private readonly WorkflowRepository $workflowRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $workflowIdString = $this->argument('workflow');
        $resumeAll = (bool) $this->option('all');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if ($workflowIdString !== null && is_string($workflowIdString)) {
            return $this->resumeSingleWorkflow($workflowIdString, $force, $dryRun);
        }

        if ($resumeAll) {
            return $this->resumeAllPausedWorkflows($force, $dryRun);
        }

        $this->error('Please provide a workflow ID or use --all to resume all paused workflows.');

        return self::FAILURE;
    }

    private function resumeSingleWorkflow(string $workflowIdString, bool $force, bool $dryRun): int
    {
        $workflowId = WorkflowId::fromString($workflowIdString);

        try {
            $workflow = $this->workflowRepository->findOrFail($workflowId);
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        }

        if (! $workflow->isPaused()) {
            $this->error('Workflow is not paused. Current state: '.$workflow->state()->value);

            return self::FAILURE;
        }

        $this->displayWorkflowInfo($workflow);

        if ($dryRun) {
            $this->warn('Dry run: Would resume this workflow.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Do you want to resume this workflow?')) {
            $this->info('Resume cancelled.');

            return self::SUCCESS;
        }

        try {
            $this->workflowManager->resumeWorkflow($workflowId);
            $this->info(sprintf('Workflow %s has been resumed successfully.', $workflowIdString));

            return self::SUCCESS;
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        } catch (InvalidStateTransitionException $e) {
            $this->error('Cannot resume workflow: '.$e->getMessage());

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

    private function resumeAllPausedWorkflows(bool $force, bool $dryRun): int
    {
        $pausedWorkflows = $this->workflowRepository->findPaused();
        $count = count($pausedWorkflows);

        if ($count === 0) {
            $this->info('No paused workflows found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d paused workflow(s):', $count));
        $this->newLine();

        $this->table(
            ['ID', 'Definition', 'Current Step', 'Paused At', 'Reason'],
            array_map(
                static function (WorkflowInstance $workflowInstance): array {
                    $stepKey = $workflowInstance->currentStepKey();

                    return [
                        $workflowInstance->id->value,
                        $workflowInstance->definitionKey->value.' v'.$workflowInstance->definitionVersion->toString(),
                        $stepKey instanceof StepKey ? $stepKey->value : 'N/A',
                        $workflowInstance->pausedAt()?->toDateTimeString() ?? 'N/A',
                        $workflowInstance->pausedReason() ?? 'N/A',
                    ];
                },
                $pausedWorkflows,
            ),
        );

        if ($dryRun) {
            $this->warn(sprintf('Dry run: Would resume %d workflow(s).', $count));

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm(sprintf('Do you want to resume all %d paused workflow(s)?', $count))) {
            $this->info('Resume cancelled.');

            return self::SUCCESS;
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($pausedWorkflows as $pausedWorkflow) {
            try {
                $this->workflowManager->resumeWorkflow($pausedWorkflow->id);
                $this->info('Resumed: '.$pausedWorkflow->id->value);
                $succeeded++;
            } catch (WorkflowNotFoundException|InvalidStateTransitionException|WorkflowLockedException|DefinitionNotFoundException|StepDependencyException $e) {
                $this->error(sprintf('Failed to resume %s: %s', $pausedWorkflow->id->value, $e->getMessage()));
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf('Resumed: %d, Failed: %d', $succeeded, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function displayWorkflowInfo(WorkflowInstance $workflowInstance): void
    {
        $currentStepKey = $workflowInstance->currentStepKey();
        $currentStep = $currentStepKey instanceof StepKey ? $currentStepKey->value : 'N/A';
        $pausedAt = $workflowInstance->pausedAt()?->toDateTimeString() ?? 'N/A';
        $pausedReason = $workflowInstance->pausedReason() ?? 'N/A';
        $version = $workflowInstance->definitionVersion->toString();

        $this->info('Workflow ID: '.$workflowInstance->id->value);
        $this->info(sprintf('Definition: %s v%s', $workflowInstance->definitionKey->value, $version));
        $this->info('Current step: '.$currentStep);
        $this->info('Paused at: '.$pausedAt);
        $this->info('Paused reason: '.$pausedReason);
    }
}
