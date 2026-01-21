<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Maestro\Workflow\Contracts\WorkflowManager;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Exceptions\InvalidStateTransitionException;
use Maestro\Workflow\Exceptions\WorkflowAlreadyCancelledException;
use Maestro\Workflow\Exceptions\WorkflowNotFoundException;
use Maestro\Workflow\ValueObjects\StepKey;
use Maestro\Workflow\ValueObjects\WorkflowId;

/**
 * Artisan command to cancel workflows.
 *
 * Supports both single workflow cancellation and bulk cancellation of stuck workflows.
 */
final class CancelWorkflowCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'maestro:cancel
                            {workflow? : The workflow ID to cancel (omit for bulk operations)}
                            {--stuck : Cancel workflows that have been paused for too long}
                            {--stuck-hours=72 : Hours a workflow must be paused to be considered stuck}
                            {--failed : Cancel all failed workflows}
                            {--force : Cancel without confirmation}
                            {--dry-run : Show what would be cancelled without making changes}';

    /**
     * @var string
     */
    protected $description = 'Cancel workflow(s)';

    public function __construct(
        private readonly WorkflowManager $workflowManager,
        private readonly WorkflowRepository $workflowRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $workflowIdString = $this->argument('workflow');
        $cancelStuck = (bool) $this->option('stuck');
        $cancelFailed = (bool) $this->option('failed');
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if ($workflowIdString !== null && is_string($workflowIdString)) {
            return $this->cancelSingleWorkflow($workflowIdString, $force, $dryRun);
        }

        if ($cancelStuck) {
            $stuckHours = (int) $this->option('stuck-hours');

            return $this->cancelStuckWorkflows($stuckHours, $force, $dryRun);
        }

        if ($cancelFailed) {
            return $this->cancelFailedWorkflows($force, $dryRun);
        }

        $this->error('Please provide a workflow ID, --stuck, or --failed option.');

        return self::FAILURE;
    }

    private function cancelSingleWorkflow(string $workflowIdString, bool $force, bool $dryRun): int
    {
        $workflowId = WorkflowId::fromString($workflowIdString);

        try {
            $workflow = $this->workflowRepository->findOrFail($workflowId);
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        }

        if ($workflow->isTerminal()) {
            $this->error('Workflow is already in terminal state: '.$workflow->state()->value);

            return self::FAILURE;
        }

        $this->displayWorkflowInfo($workflow);

        if ($dryRun) {
            $this->warn('Dry run: Would cancel this workflow.');

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm('Do you want to cancel this workflow?')) {
            $this->info('Cancellation aborted.');

            return self::SUCCESS;
        }

        try {
            $this->workflowManager->cancelWorkflow($workflowId);
            $this->info(sprintf('Workflow %s has been cancelled successfully.', $workflowIdString));

            return self::SUCCESS;
        } catch (WorkflowNotFoundException) {
            $this->error('Workflow not found: '.$workflowIdString);

            return self::FAILURE;
        } catch (InvalidStateTransitionException $e) {
            $this->error('Cannot cancel workflow: '.$e->getMessage());

            return self::FAILURE;
        } catch (WorkflowAlreadyCancelledException) {
            $this->warn('Workflow was already cancelled: '.$workflowIdString);

            return self::SUCCESS;
        }
    }

    private function cancelStuckWorkflows(int $stuckHours, bool $force, bool $dryRun): int
    {
        $pausedWorkflows = $this->workflowRepository->findPaused();
        $threshold = CarbonImmutable::now()->subHours($stuckHours);

        $stuckWorkflows = array_filter(
            $pausedWorkflows,
            static fn (WorkflowInstance $workflowInstance): bool => $workflowInstance->pausedAt() instanceof CarbonImmutable
                && $workflowInstance->pausedAt()->lessThan($threshold),
        );

        $count = count($stuckWorkflows);

        if ($count === 0) {
            $this->info(sprintf('No workflows have been paused for more than %d hours.', $stuckHours));

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d stuck workflow(s) (paused > %d hours):', $count, $stuckHours));
        $this->newLine();

        $this->displayWorkflowTable($stuckWorkflows);

        if ($dryRun) {
            $this->warn(sprintf('Dry run: Would cancel %d workflow(s).', $count));

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm(sprintf('Do you want to cancel all %d stuck workflow(s)?', $count))) {
            $this->info('Cancellation aborted.');

            return self::SUCCESS;
        }

        return $this->cancelWorkflows($stuckWorkflows);
    }

    private function cancelFailedWorkflows(bool $force, bool $dryRun): int
    {
        $failedWorkflows = $this->workflowRepository->findFailed();
        $count = count($failedWorkflows);

        if ($count === 0) {
            $this->info('No failed workflows found.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d failed workflow(s):', $count));
        $this->newLine();

        $this->table(
            ['ID', 'Definition', 'Current Step', 'Failed At', 'Failure'],
            array_map(
                static function (WorkflowInstance $workflowInstance): array {
                    $stepKey = $workflowInstance->currentStepKey();

                    return [
                        $workflowInstance->id->value,
                        $workflowInstance->definitionKey->value.' v'.$workflowInstance->definitionVersion->toString(),
                        $stepKey instanceof StepKey ? $stepKey->value : 'N/A',
                        $workflowInstance->failedAt()?->toDateTimeString() ?? 'N/A',
                        $workflowInstance->failureMessage() ?? 'N/A',
                    ];
                },
                $failedWorkflows,
            ),
        );

        if ($dryRun) {
            $this->warn(sprintf('Dry run: Would cancel %d workflow(s).', $count));

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm(sprintf('Do you want to cancel all %d failed workflow(s)?', $count))) {
            $this->info('Cancellation aborted.');

            return self::SUCCESS;
        }

        return $this->cancelWorkflows($failedWorkflows);
    }

    /**
     * @param array<WorkflowInstance> $workflows
     */
    private function cancelWorkflows(array $workflows): int
    {
        $succeeded = 0;
        $failed = 0;

        foreach ($workflows as $workflow) {
            try {
                $this->workflowManager->cancelWorkflow($workflow->id);
                $this->info('Cancelled: '.$workflow->id->value);
                $succeeded++;
            } catch (WorkflowNotFoundException|InvalidStateTransitionException|WorkflowAlreadyCancelledException $e) {
                $this->error(sprintf('Failed to cancel %s: %s', $workflow->id->value, $e->getMessage()));
                $failed++;
            }
        }

        $this->newLine();
        $this->info(sprintf('Cancelled: %d, Failed: %d', $succeeded, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function displayWorkflowInfo(WorkflowInstance $workflowInstance): void
    {
        $currentStepKey = $workflowInstance->currentStepKey();
        $currentStep = $currentStepKey instanceof StepKey ? $currentStepKey->value : 'N/A';
        $version = $workflowInstance->definitionVersion->toString();

        $this->info('Workflow ID: '.$workflowInstance->id->value);
        $this->info(sprintf('Definition: %s v%s', $workflowInstance->definitionKey->value, $version));
        $this->info('State: '.$workflowInstance->state()->value);
        $this->info('Current step: '.$currentStep);
    }

    /**
     * @param array<WorkflowInstance> $workflows
     */
    private function displayWorkflowTable(array $workflows): void
    {
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
                $workflows,
            ),
        );
    }
}
