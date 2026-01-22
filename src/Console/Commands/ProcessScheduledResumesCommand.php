<?php

declare(strict_types=1);

namespace Maestro\Workflow\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Maestro\Workflow\Application\Orchestration\PauseTriggerHandler;
use Maestro\Workflow\Application\Orchestration\WorkflowAdvancer;
use Maestro\Workflow\Contracts\WorkflowRepository;
use Maestro\Workflow\Domain\WorkflowInstance;
use Maestro\Workflow\Enums\WorkflowState;
use Throwable;

/**
 * Process scheduled auto-resumes for paused workflows.
 *
 * Queries for paused workflows with scheduled_resume_at <= now() and
 * automatically resumes them.
 *
 * Should be run every minute via Laravel scheduler.
 */
final class ProcessScheduledResumesCommand extends Command
{
    protected $signature = 'maestro:process-scheduled-resumes
        {--limit=100 : Maximum number of workflows to process}
        {--dry-run : Show what would be processed without making changes}';

    protected $description = 'Process workflows with scheduled auto-resume times';

    public function __construct(
        private readonly WorkflowRepository $workflowRepository,
        private readonly PauseTriggerHandler $pauseTriggerHandler,
        private readonly WorkflowAdvancer $workflowAdvancer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Checking for scheduled resumes...');

        $dueWorkflows = $this->workflowRepository->findByStateAndScheduledResumeBefore(
            WorkflowState::Paused,
            CarbonImmutable::now(),
            $limit,
        );

        if ($dueWorkflows === []) {
            $this->info('No workflows due for scheduled resume.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d workflow(s) due for scheduled resume.', count($dueWorkflows)));

        $processed = 0;
        $failed = 0;

        foreach ($dueWorkflows as $dueWorkflow) {
            $this->processScheduledResume($dueWorkflow, $dryRun, $processed, $failed);
        }

        $this->info(sprintf('Processed: %d, Failed: %d', $processed, $failed));

        return self::SUCCESS;
    }

    private function processScheduledResume(
        WorkflowInstance $workflowInstance,
        bool $dryRun,
        int &$processed,
        int &$failed,
    ): void {
        if ($dryRun) {
            $this->line(sprintf(
                '  [DRY RUN] Would auto-resume workflow %s (scheduled for: %s)',
                $workflowInstance->id->value,
                $workflowInstance->scheduledResumeAt()?->toDateTimeString() ?? 'unknown',
            ));
            $processed++;

            return;
        }

        try {
            $this->pauseTriggerHandler->processScheduledResume($workflowInstance);
            $this->workflowAdvancer->evaluate($workflowInstance->id);

            $this->line(sprintf(
                '  Auto-resumed workflow %s',
                $workflowInstance->id->value,
            ));
            $processed++;
        } catch (Throwable $e) {
            $this->error(sprintf(
                '  Failed to auto-resume workflow %s: %s',
                $workflowInstance->id->value,
                $e->getMessage(),
            ));
            $failed++;
        }
    }
}
